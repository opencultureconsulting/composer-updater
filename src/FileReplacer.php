<?php

/**
 * Updater for Composer-based Applications
 * Copyright (C) 2026 Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace OCC\ComposerUpdater;

use RuntimeException;

/**
 * Replaces the files of an installation with those of a new release.
 *
 * @see ReplaceRules for how each path is handled
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class FileReplacer
{
    /**
     * Creates a new file replacer.
     *
     * @param string $source The directory holding the new release
     * @param string $target The directory of the current installation
     * @param ReplaceRules $rules The rules for handling paths
     * @param ?Backup $backup The backup to record all changes in or NULL for no backup
     */
    public function __construct(
        private readonly string $source,
        private readonly string $target,
        private readonly ReplaceRules $rules,
        private readonly ?Backup $backup = null
    ) {
    }

    /**
     * Replaces the files.
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    public function run(): void
    {
        if (!is_writable($this->target)) {
            throw new RuntimeException(sprintf('Directory "%s" is not writable.', $this->target));
        }
        $this->syncDirectory('');
        // Remove mirrored paths which are no longer part of the new release.
        foreach ($this->rules->pathsWith('mirror') as $path) {
            if (!Files::exists($this->source . '/' . $path) && Files::exists($this->target . '/' . $path)) {
                $this->removeNode($path);
            }
        }
    }

    /**
     * Synchronizes a directory of the new release into the installation.
     *
     * @param string $relPath The directory's path relative to the root, '' for the root itself
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function syncDirectory(string $relPath): void
    {
        $entries = Files::list($this->path($this->source, $relPath));
        foreach ($entries as $entry) {
            $this->syncNode($relPath === '' ? $entry : $relPath . '/' . $entry);
        }
        if ($relPath !== '' && $this->rules->for($relPath) === 'mirror') {
            $this->deleteStale($relPath, $entries);
        }
    }

    /**
     * Synchronizes a single file, symlink or directory.
     *
     * @param string $relPath The node's path relative to the root
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function syncNode(string $relPath): void
    {
        $sourceNode = $this->path($this->source, $relPath);
        $targetNode = $this->path($this->target, $relPath);
        $rule = $this->rules->for($relPath);
        $sourceIsDir = !is_link($sourceNode) && is_dir($sourceNode);
        if ($rule === 'ignore' || ($rule === 'preserve' && Files::exists($targetNode))) {
            // More specific rules for paths below must still be applied.
            if ($sourceIsDir && $this->rules->hasRulesBelow($relPath, ['copy', 'mirror'])) {
                $this->syncProtectedDirectory($relPath);
            }
            return;
        }
        if ($sourceIsDir) {
            $this->syncChildDirectory($relPath, $sourceNode, $targetNode);
        } else {
            $this->syncFile($relPath, $sourceNode, $targetNode);
        }
    }

    /**
     * Synchronizes a single file or symlink.
     *
     * @param string $relPath The node's path relative to the root
     * @param string $sourceNode The node's absolute path in the new release
     * @param string $targetNode The node's absolute path in the installation
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function syncFile(string $relPath, string $sourceNode, string $targetNode): void
    {
        if (!is_link($targetNode) && is_dir($targetNode) && $this->hasProtectedPathsBelow($relPath)) {
            throw new RuntimeException(
                sprintf(
                    'Cannot replace directory "%s" with a file, because it contains preserved or ignored paths.',
                    $targetNode
                )
            );
        }
        $this->prepareChange($targetNode);
        Files::copy($sourceNode, $targetNode);
    }

    /**
     * Synchronizes a directory below the root.
     *
     * @param string $relPath The directory's path relative to the root
     * @param string $sourceNode The directory's absolute path in the new release
     * @param string $targetNode The directory's absolute path in the installation
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function syncChildDirectory(string $relPath, string $sourceNode, string $targetNode): void
    {
        // Symlinks to directories are kept, and files are merged into the directory they point to.
        if (is_dir($targetNode)) {
            $this->syncDirectory($relPath);
            return;
        }
        $this->createDirectory($targetNode);
        $this->syncDirectory($relPath);
        chmod($targetNode, Files::permissions($sourceNode));
    }

    /**
     * Synchronizes the paths with more specific rules inside a preserved or ignored directory.
     *
     * @param string $relPath The directory's path relative to the root
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function syncProtectedDirectory(string $relPath): void
    {
        $targetNode = $this->path($this->target, $relPath);
        if (!Files::exists($targetNode)) {
            $this->createDirectory($targetNode);
        } elseif (!is_dir($targetNode)) {
            // Never replace a preserved or ignored node.
            return;
        }
        $this->syncDirectory($relPath);
    }

    /**
     * Creates a directory in place of whatever node is there now.
     *
     * The directory is created writable, so its contents can be added. The
     * caller sets the final permissions afterwards.
     *
     * @param string $targetNode The absolute path
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function createDirectory(string $targetNode): void
    {
        $this->prepareChange($targetNode);
        if (!mkdir($targetNode) && !is_dir($targetNode)) {
            throw new RuntimeException(sprintf('Could not create directory "%s".', $targetNode));
        }
    }

    /**
     * Deletes nodes in a mirrored directory which are not part of the new release.
     *
     * @param string $relPath The directory's path relative to the root
     * @param list<string> $keep The entries present in the new release
     *
     * @return void
     *
     * @throws RuntimeException if deleting fails
     */
    private function deleteStale(string $relPath, array $keep): void
    {
        foreach (array_diff(Files::list($this->path($this->target, $relPath)), $keep) as $entry) {
            if (in_array($this->rules->for($relPath . '/' . $entry), ['copy', 'mirror'], true)) {
                $this->removeNode($relPath . '/' . $entry);
            }
        }
    }

    /**
     * Removes a node from the installation, but keeps preserved and ignored paths below it.
     *
     * @param string $relPath The node's path relative to the root
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function removeNode(string $relPath): void
    {
        $targetNode = $this->path($this->target, $relPath);
        if (is_link($targetNode) || !is_dir($targetNode) || !$this->hasProtectedPathsBelow($relPath)) {
            $this->prepareChange($targetNode);
            return;
        }
        foreach (Files::list($targetNode) as $entry) {
            if (in_array($this->rules->for($relPath . '/' . $entry), ['copy', 'mirror'], true)) {
                $this->removeNode($relPath . '/' . $entry);
            }
        }
    }

    /**
     * Checks whether preserved or ignored paths are configured below a path.
     *
     * @param string $relPath The path relative to the root
     *
     * @return bool Whether there are protected paths below
     */
    private function hasProtectedPathsBelow(string $relPath): bool
    {
        return $this->rules->hasRulesBelow($relPath, ['preserve', 'ignore']);
    }

    /**
     * Frees a path in the installation, recording the change in the backup (if any).
     *
     * @param string $targetNode The absolute path
     *
     * @return void
     *
     * @throws RuntimeException if a file operation fails
     */
    private function prepareChange(string $targetNode): void
    {
        if ($this->backup !== null) {
            $this->backup->prepareChange($targetNode);
        } else {
            Files::delete($targetNode);
        }
    }

    /**
     * Joins a base directory and a relative path.
     *
     * @param string $base The base directory
     * @param string $relPath The relative path
     *
     * @return string The joined path
     */
    private function path(string $base, string $relPath): string
    {
        return $relPath === '' ? $base : $base . '/' . $relPath;
    }
}

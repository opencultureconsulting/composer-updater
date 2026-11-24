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
use Throwable;

/**
 * Journal of file changes which can be rolled back.
 *
 * Before a node is changed, it is either moved into the backup directory (if
 * it exists) or recorded as newly created (if it does not). Rolling back
 * deletes all created nodes and moves all saved nodes back into place.
 *
 * Changes below a node which is already recorded need no further record,
 * because rolling back that node restores the whole subtree.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class Backup
{
    /**
     * The recorded nodes: target path => location in backup or NULL if newly created.
     *
     * @var array<string, ?string>
     */
    private array $entries = [];

    /**
     * Creates a new backup.
     *
     * @param string $dir The backup directory (must not exist)
     *
     * @throws RuntimeException if the backup directory exists or cannot be created
     */
    public function __construct(private readonly string $dir)
    {
        if (Files::exists($dir)) {
            throw new RuntimeException(
                sprintf(
                    'The backup directory "%s" already exists. It may contain files left from a failed upgrade.'
                    . ' Check and remove it before trying again.',
                    $dir
                )
            );
        }
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create backup directory "%s".', $dir));
        }
    }

    /**
     * Prepares a node for being changed.
     *
     * An existing node is moved into the backup, so afterwards the path is
     * free for the new node. A missing node is recorded as newly created.
     *
     * @param string $path The node's absolute path
     *
     * @return void
     *
     * @throws RuntimeException if moving the node fails
     */
    public function prepareChange(string $path): void
    {
        if ($this->isRecorded($path)) {
            Files::delete($path);
            return;
        }
        if (!Files::exists($path)) {
            $this->entries[$path] = null;
            return;
        }
        $location = $this->nextLocation();
        try {
            Files::move($path, $location);
        } catch (IncompleteMoveException $exception) {
            // The backup holds the only complete copy now, so it must be restored on rollback.
            $this->entries[$path] = $location;
            throw $exception;
        }
        $this->entries[$path] = $location;
    }

    /**
     * Saves a copy of a node, leaving the original in place.
     *
     * @param string $path The node's absolute path
     *
     * @return void
     *
     * @throws RuntimeException if copying fails
     */
    public function saveCopy(string $path): void
    {
        if ($this->isRecorded($path)) {
            return;
        }
        if (!Files::exists($path)) {
            $this->entries[$path] = null;
            return;
        }
        $location = $this->nextLocation();
        Files::copy($path, $location);
        $this->entries[$path] = $location;
    }

    /**
     * Rolls back all recorded changes and removes the backup directory.
     *
     * @return void
     *
     * @throws RuntimeException if rolling back fails for any node (the backup directory is kept then)
     */
    public function rollback(): void
    {
        $failed = [];
        foreach (array_reverse($this->entries, true) as $path => $location) {
            try {
                Files::delete($path);
                if ($location !== null) {
                    Files::move($location, $path);
                }
            } catch (Throwable) {
                $failed[] = $path;
            }
        }
        if (count($failed) > 0) {
            throw new RuntimeException(
                sprintf(
                    'Could not restore %s. The backup is kept in "%s".',
                    implode(', ', $failed),
                    $this->dir
                )
            );
        }
        $this->entries = [];
        $this->discard();
    }

    /**
     * Removes the backup directory without rolling back.
     *
     * @return void
     *
     * @throws RuntimeException if deleting fails
     */
    public function discard(): void
    {
        $this->entries = [];
        Files::delete($this->dir);
    }

    /**
     * Checks whether a node or any of its parents is already recorded.
     *
     * @param string $path The node's absolute path
     *
     * @return bool Whether the node is recorded
     */
    private function isRecorded(string $path): bool
    {
        while (!array_key_exists($path, $this->entries)) {
            $pos = strrpos($path, '/');
            if ($pos === false || $pos === 0) {
                return false;
            }
            $path = substr($path, 0, $pos);
        }
        return true;
    }

    /**
     * Gets the next free location in the backup directory.
     *
     * @return string The location
     */
    private function nextLocation(): string
    {
        return $this->dir . '/' . count($this->entries);
    }
}

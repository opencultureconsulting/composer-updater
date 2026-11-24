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

use Composer\Util\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Low-level file operations which handle symlinks correctly.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class Files
{
    /**
     * Checks whether a node exists (including dangling symlinks).
     *
     * @param string $path The path
     *
     * @return bool Whether the node exists
     */
    public static function exists(string $path): bool
    {
        return file_exists($path) || is_link($path);
    }

    /**
     * Deletes a file, symlink or directory (if it exists).
     *
     * @param string $path The path
     *
     * @return void
     *
     * @throws RuntimeException if deleting fails
     */
    public static function delete(string $path): void
    {
        if (!self::exists($path)) {
            return;
        }
        $success = is_link($path) ? unlink($path) : (new Filesystem())->remove($path);
        clearstatcache(true, $path);
        if (!$success) {
            throw new RuntimeException(sprintf('Could not delete "%s".', $path));
        }
    }

    /**
     * Moves a file, symlink or directory.
     *
     * @param string $source The source path
     * @param string $target The target path (must not exist)
     *
     * @return void
     *
     * @throws IncompleteMoveException if the node was copied, but the original could not be fully deleted
     * @throws RuntimeException if moving fails (the source is left intact then)
     */
    public static function move(string $source, string $target): void
    {
        // Suppress the warning rename() emits when moving across filesystems.
        set_error_handler(static fn (): bool => true);
        try {
            $moved = rename($source, $target);
        } finally {
            restore_error_handler();
        }
        if ($moved) {
            return;
        }
        try {
            self::copy($source, $target);
        } catch (Throwable $exception) {
            // The source is still intact, so remove the incomplete copy.
            try {
                self::delete($target);
            } catch (Throwable) {
                // Nothing more we can do, the original exception is more important.
            }
            throw $exception;
        }
        try {
            self::delete($source);
        } catch (RuntimeException $exception) {
            throw new IncompleteMoveException(
                sprintf('Copied "%s" to "%s", but could not delete the original.', $source, $target),
                0,
                $exception
            );
        }
    }

    /**
     * Copies a file, symlink or directory recursively, preserving symlinks and permissions.
     *
     * @param string $source The source path
     * @param string $target The target path (must not exist)
     *
     * @return void
     *
     * @throws RuntimeException if copying fails
     */
    public static function copy(string $source, string $target): void
    {
        if (is_link($source)) {
            self::copyLink($source, $target);
        } elseif (is_dir($source)) {
            // Create the directory writable and set its final permissions after copying its contents,
            // so read-only directories can be copied, too.
            if (!is_dir($target) && !mkdir($target) && !is_dir($target)) {
                throw new RuntimeException(sprintf('Could not create directory "%s".', $target));
            }
            foreach (self::list($source) as $entry) {
                self::copy($source . '/' . $entry, $target . '/' . $entry);
            }
            chmod($target, self::permissions($source));
        } else {
            if (!copy($source, $target)) {
                throw new RuntimeException(sprintf('Could not copy "%s" to "%s".', $source, $target));
            }
            chmod($target, self::permissions($source));
        }
    }

    /**
     * Copies a symlink.
     *
     * @param string $source The source symlink
     * @param string $target The target path (must not exist)
     *
     * @return void
     *
     * @throws RuntimeException if creating the symlink fails
     */
    private static function copyLink(string $source, string $target): void
    {
        $link = readlink($source);
        if ($link === false || !symlink($link, $target)) {
            throw new RuntimeException(sprintf('Could not create symlink "%s".', $target));
        }
    }

    /**
     * Lists the entries of a directory.
     *
     * @param string $dir The directory
     *
     * @return list<string> The entries (without "." and "..")
     *
     * @throws RuntimeException if the directory cannot be read
     */
    public static function list(string $dir): array
    {
        $entries = is_dir($dir) ? scandir($dir) : false;
        if ($entries === false) {
            throw new RuntimeException(sprintf('Could not read directory "%s".', $dir));
        }
        return array_values(array_diff($entries, ['.', '..']));
    }

    /**
     * Gets the permissions of a node.
     *
     * @param string $path The path
     *
     * @return int The permissions
     */
    public static function permissions(string $path): int
    {
        $perms = fileperms($path);
        return $perms === false ? 0755 : $perms & 0777;
    }
}

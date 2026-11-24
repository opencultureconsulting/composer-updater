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

namespace OCC\ComposerUpdater\Tests;

use OCC\ComposerUpdater\Files;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Base class for tests working with temporary directories.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
abstract class FilesystemTestCase extends TestCase
{
    /**
     * The temporary directories created by the current test.
     *
     * @var list<string>
     */
    private array $tempDirs = [];

    /**
     * Removes all temporary directories.
     *
     * @return void
     */
    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            Files::delete($dir);
        }
        $this->tempDirs = [];
        parent::tearDown();
    }

    /**
     * Creates a temporary directory which is removed after the test.
     *
     * @param array<string, ?string> $files Files to create: relative path => content (NULL for a directory)
     *
     * @return string The absolute path
     */
    protected function createTempDir(array $files = []): string
    {
        $dir = sys_get_temp_dir() . '/composer-updater-test-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0777, true)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $dir));
        }
        $dir = (string) realpath($dir);
        $this->tempDirs[] = $dir;
        $this->writeFiles($dir, $files);
        return $dir;
    }

    /**
     * Creates files in a directory.
     *
     * @param string $dir The directory
     * @param array<string, ?string> $files Files to create: relative path => content (NULL for a directory)
     *
     * @return void
     */
    protected function writeFiles(string $dir, array $files): void
    {
        foreach ($files as $path => $content) {
            $path = $dir . '/' . $path;
            $parent = $content === null ? $path : dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0777, true);
            }
            if ($content !== null) {
                file_put_contents($path, $content);
            }
        }
    }

    /**
     * Reads a directory tree.
     *
     * @param string $dir The directory
     *
     * @return array<string, string> Relative path => content ("<dir>" for directories, "-> target" for symlinks)
     */
    protected function readTree(string $dir): array
    {
        $tree = [];
        $this->collectTree($dir, '', $tree);
        ksort($tree);
        return $tree;
    }

    /**
     * Collects a directory tree recursively.
     *
     * @param string $dir The base directory
     * @param string $relPath The current path relative to the base directory
     * @param array<string, string> $tree The tree collected so far
     *
     * @return void
     */
    private function collectTree(string $dir, string $relPath, array &$tree): void
    {
        foreach (Files::list($relPath === '' ? $dir : $dir . '/' . $relPath) as $entry) {
            $childPath = $relPath === '' ? $entry : $relPath . '/' . $entry;
            $absolute = $dir . '/' . $childPath;
            if (is_link($absolute)) {
                $tree[$childPath] = '-> ' . (string) readlink($absolute);
            } elseif (is_dir($absolute)) {
                $tree[$childPath] = '<dir>';
                $this->collectTree($dir, $childPath, $tree);
            } else {
                $tree[$childPath] = (string) file_get_contents($absolute);
            }
        }
    }
}

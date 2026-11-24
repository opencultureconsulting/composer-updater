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

namespace OCC\ComposerUpdater\Tests\Unit;

use OCC\ComposerUpdater\Files;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use RuntimeException;

/**
 * Tests for the file operations.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(Files::class)]
final class FilesTest extends FilesystemTestCase
{
    /**
     * Tests copying a directory tree including symlinks and permissions.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testCopyPreservesSymlinksAndPermissions(): void
    {
        $source = $this->createTempDir(['a.txt' => 'A', 'sub/b.txt' => 'B', 'empty' => null]);
        symlink('sub', $source . '/link');
        symlink('missing', $source . '/dangling');
        chmod($source . '/a.txt', 0750);
        $target = $this->createTempDir() . '/copy';

        Files::copy($source, $target);

        self::assertSame(
            [
                'a.txt' => 'A',
                'dangling' => '-> missing',
                'empty' => '<dir>',
                'link' => '-> sub',
                'sub' => '<dir>',
                'sub/b.txt' => 'B'
            ],
            $this->readTree($target)
        );
        self::assertSame(0750, Files::permissions($target . '/a.txt'));
    }

    /**
     * Tests copying a read-only directory.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testCopyReadOnlyDirectory(): void
    {
        $source = $this->createTempDir(['readonly/file.txt' => 'content']);
        chmod($source . '/readonly', 0555);
        $target = $this->createTempDir() . '/copy';

        try {
            Files::copy($source . '/readonly', $target);
            self::assertStringEqualsFile($target . '/file.txt', 'content');
            self::assertSame(0555, Files::permissions($target));
        } finally {
            chmod($source . '/readonly', 0755);
            if (is_dir($target)) {
                chmod($target, 0755);
            }
        }
    }

    /**
     * Tests moving a directory.
     *
     * @return void
     */
    public function testMove(): void
    {
        $dir = $this->createTempDir(['from/file.txt' => 'content']);

        Files::move($dir . '/from', $dir . '/to');

        self::assertSame(['to' => '<dir>', 'to/file.txt' => 'content'], $this->readTree($dir));
    }

    /**
     * Tests deleting files, directories and (dangling) symlinks.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testDelete(): void
    {
        $dir = $this->createTempDir(['file.txt' => 'x', 'sub/file.txt' => 'y', 'target/keep.txt' => 'z']);
        symlink('target', $dir . '/link');
        symlink('missing', $dir . '/dangling');

        Files::delete($dir . '/file.txt');
        Files::delete($dir . '/sub');
        Files::delete($dir . '/link');
        Files::delete($dir . '/dangling');
        Files::delete($dir . '/does-not-exist');

        // Deleting a symlink must not delete the directory it points to.
        self::assertSame(['target' => '<dir>', 'target/keep.txt' => 'z'], $this->readTree($dir));
    }

    /**
     * Tests the existence check for dangling symlinks.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testExistsForDanglingSymlink(): void
    {
        $dir = $this->createTempDir();
        symlink('missing', $dir . '/dangling');

        self::assertTrue(Files::exists($dir . '/dangling'));
        self::assertFalse(Files::exists($dir . '/missing'));
    }

    /**
     * Tests listing a directory which does not exist.
     *
     * @return void
     */
    public function testListMissingDirectory(): void
    {
        $this->expectException(RuntimeException::class);
        Files::list($this->createTempDir() . '/missing');
    }
}

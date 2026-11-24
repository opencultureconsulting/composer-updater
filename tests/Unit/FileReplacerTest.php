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

use OCC\ComposerUpdater\Backup;
use OCC\ComposerUpdater\FileReplacer;
use OCC\ComposerUpdater\Files;
use OCC\ComposerUpdater\ReplaceRules;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Tests for the file replacer.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(FileReplacer::class)]
#[UsesClass(Backup::class)]
#[UsesClass(Files::class)]
#[UsesClass(ReplaceRules::class)]
final class FileReplacerTest extends FilesystemTestCase
{
    /**
     * The files of the installed (old) version, including local changes.
     */
    private const INSTALLED = [
        'composer.json' => 'old composer.json',
        'README.md' => 'old readme',
        'local-notes.txt' => 'added by admin',
        'src/Common.php' => 'old common',
        'src/Removed.php' => 'removed in new version',
        'src/Sub/Removed.php' => 'removed in new version',
        'src/cache/keep.txt' => 'preserved inside mirror',
        'config/settings.php' => 'locally modified settings',
        'config/defaults.php' => 'old defaults',
        'config/custom.php' => 'added by admin',
        'data/upload.jpg' => 'user data',
        'legacy/old.php' => 'dropped directory',
        'vendor/autoload.php' => 'vendor',
        'swap' => 'file becoming a directory'
    ];

    /**
     * The files of the new version.
     */
    private const RELEASE = [
        'composer.json' => 'new composer.json',
        'README.md' => 'new readme',
        'src/Common.php' => 'new common',
        'src/Added.php' => 'added',
        'src/Sub/Added.php' => 'added',
        'config/settings.php' => 'new default settings',
        'config/defaults.php' => 'new defaults',
        'config/new.php' => 'new config',
        'data/sample.jpg' => 'sample data',
        'vendor/autoload.php' => 'must be ignored',
        'swap/file.txt' => 'now a directory',
        '.git/HEAD' => 'must be ignored'
    ];

    /**
     * The configuration of the new version.
     */
    private const CONFIG = [
        'mirror' => ['src', 'legacy'],
        'preserve' => ['config/settings.php', 'src/cache'],
        'ignore' => ['data']
    ];

    /**
     * The expected result of the upgrade.
     */
    private const EXPECTED = [
        'composer.json' => 'new composer.json',
        'README.md' => 'new readme',
        'local-notes.txt' => 'added by admin',
        'src' => '<dir>',
        'src/Added.php' => 'added',
        'src/Common.php' => 'new common',
        'src/Sub' => '<dir>',
        'src/Sub/Added.php' => 'added',
        'src/cache' => '<dir>',
        'src/cache/keep.txt' => 'preserved inside mirror',
        'config' => '<dir>',
        'config/custom.php' => 'added by admin',
        'config/defaults.php' => 'new defaults',
        'config/new.php' => 'new config',
        'config/settings.php' => 'locally modified settings',
        'data' => '<dir>',
        'data/upload.jpg' => 'user data',
        'vendor' => '<dir>',
        'vendor/autoload.php' => 'vendor',
        'swap' => '<dir>',
        'swap/file.txt' => 'now a directory'
    ];

    /**
     * Tests replacing files according to the rules.
     *
     * @return void
     */
    public function testReplacesFilesAccordingToRules(): void
    {
        $target = $this->createTempDir(self::INSTALLED);
        $source = $this->createTempDir(self::RELEASE);

        (new FileReplacer($source, $target, new ReplaceRules(self::CONFIG, ['vendor'])))->run();

        self::assertEquals(self::sorted(self::EXPECTED), $this->readTree($target));
    }

    /**
     * Tests that the same result is achieved when recording changes in a backup.
     *
     * @return void
     */
    public function testReplacesFilesWithBackup(): void
    {
        $target = $this->createTempDir(self::INSTALLED);
        $source = $this->createTempDir(self::RELEASE);
        $backup = new Backup($this->createTempDir() . '/backup');

        (new FileReplacer($source, $target, new ReplaceRules(self::CONFIG, ['vendor']), $backup))->run();

        self::assertEquals(self::sorted(self::EXPECTED), $this->readTree($target));
    }

    /**
     * Tests rolling back all changes.
     *
     * @return void
     */
    public function testRollbackRestoresInstalledVersion(): void
    {
        $target = $this->createTempDir(self::INSTALLED);
        $source = $this->createTempDir(self::RELEASE);
        $original = $this->readTree($target);
        $backup = new Backup($this->createTempDir() . '/backup');

        (new FileReplacer($source, $target, new ReplaceRules(self::CONFIG, ['vendor']), $backup))->run();
        $backup->rollback();

        self::assertSame($original, $this->readTree($target));
    }

    /**
     * Tests replacing symlinks.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testReplacesSymlinks(): void
    {
        $target = $this->createTempDir(['bin/tool' => 'old file', 'real/file.txt' => 'real']);
        symlink('real', $target . '/linked');
        $source = $this->createTempDir(['bin/tool.php' => 'tool', 'linked/file.txt' => 'new']);
        symlink('tool.php', $source . '/bin/tool');

        (new FileReplacer($source, $target, new ReplaceRules([])))->run();

        // File symlinks are replaced, directory symlinks are kept and the files are merged into their target.
        self::assertSame(
            [
                'bin' => '<dir>',
                'bin/tool' => '-> tool.php',
                'bin/tool.php' => 'tool',
                'linked' => '-> real',
                'real' => '<dir>',
                'real/file.txt' => 'new'
            ],
            $this->readTree($target)
        );
    }

    /**
     * Tests that preserved and ignored paths survive when their parent directory is removed by a mirror rule.
     *
     * @return void
     */
    public function testProtectedPathsInsideStaleDirectoriesAreKept(): void
    {
        $target = $this->createTempDir([
            'var/config/local.php' => 'local',
            'var/config/stale.php' => 'stale',
            'var/cache/data.bin' => 'cache',
            'var/old.txt' => 'old',
            'lib/local.php' => 'local lib',
            'lib/old.php' => 'old lib'
        ]);
        $source = $this->createTempDir(['var/new.txt' => 'new']);
        $rules = new ReplaceRules([
            'mirror' => ['var', 'lib'],
            'preserve' => ['var/config/local.php', 'lib/local.php'],
            'ignore' => ['var/cache']
        ]);

        (new FileReplacer($source, $target, $rules))->run();

        self::assertSame(
            [
                'lib' => '<dir>',
                'lib/local.php' => 'local lib',
                'var' => '<dir>',
                'var/cache' => '<dir>',
                'var/cache/data.bin' => 'cache',
                'var/config' => '<dir>',
                'var/config/local.php' => 'local',
                'var/new.txt' => 'new'
            ],
            $this->readTree($target)
        );
    }

    /**
     * Tests rolling back the removal of stale directories containing protected paths.
     *
     * @return void
     */
    public function testRollbackOfStaleDirectoriesWithProtectedPaths(): void
    {
        $target = $this->createTempDir(['var/config/local.php' => 'local', 'var/old.txt' => 'old']);
        $source = $this->createTempDir(['var/new.txt' => 'new']);
        $original = $this->readTree($target);
        $backup = new Backup($this->createTempDir() . '/backup');
        $rules = new ReplaceRules(['mirror' => ['var'], 'preserve' => ['var/config/local.php']]);

        (new FileReplacer($source, $target, $rules, $backup))->run();
        $backup->rollback();

        self::assertSame($original, $this->readTree($target));
    }

    /**
     * Tests refusing to replace a directory containing protected paths with a file.
     *
     * @return void
     */
    public function testFileCannotReplaceDirectoryWithProtectedPaths(): void
    {
        $target = $this->createTempDir(['data/upload.jpg' => 'user data']);
        $source = $this->createTempDir(['data' => 'now a file']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contains preserved or ignored paths');
        (new FileReplacer($source, $target, new ReplaceRules(['ignore' => ['data/upload.jpg']])))->run();
    }

    /**
     * Tests that more specific rules inside preserved and ignored directories are applied.
     *
     * @return void
     */
    public function testSpecificRulesInsideProtectedDirectories(): void
    {
        $target = $this->createTempDir([
            'config/defaults.php' => 'old defaults',
            'config/local.php' => 'local',
            'data/upload.jpg' => 'user data'
        ]);
        $source = $this->createTempDir([
            'config/defaults.php' => 'new defaults',
            'config/local.php' => 'default local',
            'data/README.md' => 'readme',
            'data/sample.jpg' => 'sample',
            'cache/README.md' => 'cache readme'
        ]);
        $rules = new ReplaceRules([
            'preserve' => ['config'],
            'ignore' => ['data', 'cache'],
            'copy' => ['config/defaults.php', 'data/README.md', 'cache/README.md']
        ]);

        (new FileReplacer($source, $target, $rules))->run();

        self::assertSame(
            [
                'cache' => '<dir>',
                'cache/README.md' => 'cache readme',
                'config' => '<dir>',
                'config/defaults.php' => 'new defaults',
                'config/local.php' => 'local',
                'data' => '<dir>',
                'data/README.md' => 'readme',
                'data/upload.jpg' => 'user data'
            ],
            $this->readTree($target)
        );
    }

    /**
     * Tests that symlinks to directories in the installation are kept.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testSymlinkedDirectoriesAreKept(): void
    {
        $shared = $this->createTempDir(['uploads/photo.jpg' => 'photo']);
        $target = $this->createTempDir(['public/index.php' => 'old index']);
        symlink($shared . '/uploads', $target . '/public/uploads');
        $source = $this->createTempDir(['public/index.php' => 'new index', 'public/uploads/.gitkeep' => '']);

        (new FileReplacer($source, $target, new ReplaceRules([])))->run();

        self::assertSame($shared . '/uploads', readlink($target . '/public/uploads'));
        self::assertSame(
            ['uploads' => '<dir>', 'uploads/.gitkeep' => '', 'uploads/photo.jpg' => 'photo'],
            $this->readTree($shared)
        );
    }

    /**
     * Sorts an expected tree like readTree() does.
     *
     * @param array<string, string> $tree The tree
     *
     * @return array<string, string> The sorted tree
     */
    private static function sorted(array $tree): array
    {
        ksort($tree);
        return $tree;
    }
}

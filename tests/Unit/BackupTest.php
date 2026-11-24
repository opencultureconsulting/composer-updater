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
use OCC\ComposerUpdater\Files;
use OCC\ComposerUpdater\IncompleteMoveException;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Tests for the backup journal.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(Backup::class)]
#[UsesClass(Files::class)]
final class BackupTest extends FilesystemTestCase
{
    /**
     * Tests rolling back modified, deleted and created nodes.
     *
     * @return void
     */
    public function testRollbackRestoresOriginalState(): void
    {
        $project = $this->createTempDir([
            'modified.txt' => 'old',
            'deleted/file.txt' => 'deleted',
            'merged/existing.txt' => 'existing',
            'vendor/lib.php' => 'lib'
        ]);
        $original = $this->readTree($project);
        $backup = new Backup($this->createTempDir() . '/backup');

        $backup->saveCopy($project . '/vendor');
        $backup->prepareChange($project . '/modified.txt');
        file_put_contents($project . '/modified.txt', 'new');
        $backup->prepareChange($project . '/deleted');
        $backup->prepareChange($project . '/merged/new.txt');
        file_put_contents($project . '/merged/new.txt', 'new');
        $backup->prepareChange($project . '/created');
        mkdir($project . '/created');
        // Changes below a recorded node need no record of their own.
        $backup->prepareChange($project . '/created/file.txt');
        file_put_contents($project . '/created/file.txt', 'new');
        file_put_contents($project . '/vendor/lib.php', 'changed');

        self::assertFileDoesNotExist($project . '/deleted');
        self::assertFileExists($project . '/vendor/lib.php', 'saveCopy() must leave the original in place.');

        $backup->rollback();

        self::assertSame($original, $this->readTree($project));
    }

    /**
     * Tests that a node copied to the backup, but not fully deleted from its origin, is restored on rollback.
     *
     * @return void
     */
    #[RequiresOperatingSystemFamily('Linux')]
    public function testIncompleteMoveIsRestoredOnRollback(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('File permissions are not enforced for root.');
        }
        $project = $this->createTempDir(['locked/data/a.txt' => 'A', 'locked/data/b.txt' => 'B']);
        $original = $this->readTree($project);
        $backup = new Backup($this->createTempDir() . '/backup');
        // A read-only parent makes rename() fail, so the node is copied, but can't be deleted afterwards.
        chmod($project . '/locked', 0555);
        try {
            $backup->prepareChange($project . '/locked/data');
            self::fail('Expected an IncompleteMoveException.');
        } catch (IncompleteMoveException) {
            // expected
        } finally {
            chmod($project . '/locked', 0755);
        }

        $backup->rollback();

        self::assertSame($original, $this->readTree($project));
    }

    /**
     * Tests that preparing a change frees the path.
     *
     * @return void
     */
    public function testPrepareChangeMovesNodeAway(): void
    {
        $project = $this->createTempDir(['file.txt' => 'old']);
        $backup = new Backup($this->createTempDir() . '/backup');

        $backup->prepareChange($project . '/file.txt');

        self::assertFileDoesNotExist($project . '/file.txt');
    }

    /**
     * Tests discarding a backup.
     *
     * @return void
     */
    public function testDiscardKeepsChanges(): void
    {
        $project = $this->createTempDir(['file.txt' => 'old']);
        $backupDir = $this->createTempDir() . '/backup';
        $backup = new Backup($backupDir);
        $backup->prepareChange($project . '/file.txt');
        file_put_contents($project . '/file.txt', 'new');

        $backup->discard();

        self::assertDirectoryDoesNotExist($backupDir);
        self::assertStringEqualsFile($project . '/file.txt', 'new');
    }

    /**
     * Tests that the backup directory is removed after rolling back.
     *
     * @return void
     */
    public function testRollbackRemovesBackupDirectory(): void
    {
        $backupDir = $this->createTempDir() . '/backup';
        $backup = new Backup($backupDir);
        self::assertDirectoryExists($backupDir);

        $backup->rollback();

        self::assertDirectoryDoesNotExist($backupDir);
    }

    /**
     * Tests refusing to overwrite an existing backup.
     *
     * @return void
     */
    public function testExistingBackupDirectoryIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already exists');
        new Backup($this->createTempDir());
    }
}

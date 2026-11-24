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

use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallationManager;
use Composer\Installer\ProjectInstaller;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\InstalledArrayRepository;
use Composer\Util\Filesystem;
use RuntimeException;
use Throwable;

/**
 * Upgrades the application to another version.
 *
 * The upgrade runs in these steps:
 *  1. Download the new version to a temporary directory.
 *  2. Back up the vendor directory (other files are backed up while being replaced).
 *  3. Run "pre-upgrade-cmd" of the current version.
 *  4. Replace the files.
 *  5. Run "composer install" for the new version.
 *  6. Run "post-upgrade-cmd" of the new version.
 * If any of the steps 3 to 5 fails, the previous version is restored. Step 6
 * is not rolled back, because its tasks (e.g. database migrations) may have
 * been run partially, so the old code might not work with them anymore.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 *
 * @phpstan-import-type Version from VersionStore
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class Upgrader
{
    /**
     * The name of the script run before replacing the files (taken from the current version).
     */
    public const PRE_UPGRADE_CMD = 'pre-upgrade-cmd';

    /**
     * The name of the script run after a successful upgrade (taken from the new version).
     */
    public const POST_UPGRADE_CMD = 'post-upgrade-cmd';

    /**
     * The name of the backup directory inside the project directory.
     */
    public const BACKUP_DIR = '.composer-updater-backup';

    /**
     * The runner for Composer subprocesses.
     */
    private ComposerProcess $process;

    /**
     * Creates a new upgrader.
     *
     * @param Composer $composer The Composer instance of the current installation
     * @param IOInterface $io The input/output handler
     * @param string $projectDir The project directory
     */
    public function __construct(
        private readonly Composer $composer,
        private readonly IOInterface $io,
        private readonly string $projectDir
    ) {
        // Everything is run in separate processes to avoid mixing old and new code.
        $this->process = new ComposerProcess($io, $projectDir);
    }

    /**
     * Upgrades the application.
     *
     * @param PackageInterface $target The version to install
     * @param ?Version $current The current version
     * @param bool $withBackup Whether to back up the current version for rolling back
     *
     * @return void
     *
     * @throws RuntimeException if upgrading fails
     */
    public function run(PackageInterface $target, ?array $current, bool $withBackup): void
    {
        $tempDir = sys_get_temp_dir() . '/composer-updater-' . bin2hex(random_bytes(8));
        $env = [
            'COMPOSER_UPDATER_PREVIOUS_VERSION' => $current['pretty_version'] ?? '',
            'COMPOSER_UPDATER_VERSION' => $target->getPrettyVersion()
        ];
        $backupDir = $this->projectDir . '/' . self::BACKUP_DIR;
        if (Files::exists($backupDir)) {
            // Checked even without backup, since the directory may hold the only copy of files from a failed upgrade.
            throw new RuntimeException(
                sprintf(
                    'The backup directory "%s" already exists. It may contain files left from a failed upgrade.'
                    . ' Check and remove it before trying again.',
                    $backupDir
                )
            );
        }
        try {
            $this->download($target, $tempDir);
            $config = ReleaseConfig::fromDirectory(
                $tempDir,
                $this->composer->getPackage()->getName(),
                $this->getIgnoredPaths()
            );
            $backup = $withBackup ? $this->createBackup() : null;
            try {
                $this->runPreUpgradeCmd($current, $env);
                $this->io->write(['', 'Replacing files...']);
                (new FileReplacer($tempDir, $this->projectDir, $config->rules, $backup))->run();
                $this->removeStaleLockFile($tempDir, $backup);
                VersionStore::write($this->composer, $target->getPrettyVersion(), $target->getVersion());
                // "composer install" creates a lock file if there is none, which must be removed on rollback.
                $backup?->saveCopy($this->projectDir . '/composer.lock');
                $this->installDependencies($target, $env);
            } catch (Throwable $exception) {
                $this->rollback($backup, $exception);
            }
            $this->discardBackup($backup);
        } finally {
            Files::delete($tempDir);
            if (function_exists('opcache_reset')) {
                opcache_reset();
            }
        }
        if ($config->hasScript(self::POST_UPGRADE_CMD)) {
            $this->runPostUpgradeCmd($target, $env);
        }
    }

    /**
     * Downloads and extracts the target version to a temporary directory.
     *
     * @param PackageInterface $target The version to download
     * @param string $tempDir The (not yet existing) directory to extract to
     *
     * @return void
     */
    private function download(PackageInterface $target, string $tempDir): void
    {
        $this->io->write(
            ['', 'Downloading ' . $target->getPrettyName() . ' ' . $target->getFullPrettyVersion() . '...']
        );
        $downloadManager = $this->composer->getDownloadManager();
        $downloadManager->setPreferDist(true);
        $installationManager = new InstallationManager($this->composer->getLoop(), $this->io);
        $installationManager->addInstaller(new ProjectInstaller($tempDir, $downloadManager, new Filesystem()));
        $installationManager->execute(new InstalledArrayRepository(), [new InstallOperation($target)]);
    }

    /**
     * Creates a backup of the current version.
     *
     * @return Backup The backup
     *
     * @throws RuntimeException if creating the backup fails
     */
    private function createBackup(): Backup
    {
        $this->io->write(['', 'Backing up current version...']);
        $backup = new Backup($this->projectDir . '/' . self::BACKUP_DIR);
        // "composer install" changes the vendor directory and the bin directory (which may be outside of it).
        $dirs = array_filter(
            [$this->getVendorDir(), $this->getConfiguredDir('bin-dir')],
            static fn (?string $dir): bool => $dir !== null
        );
        try {
            foreach ($dirs as $dir) {
                $backup->saveCopy($dir);
            }
        } catch (Throwable $exception) {
            $backup->discard();
            throw new RuntimeException(
                'Backing up the vendor directory failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
        return $backup;
    }

    /**
     * Removes the backup after a successful upgrade.
     *
     * Failing to remove it doesn't make the upgrade fail, since the new version is installed already.
     *
     * @param ?Backup $backup The backup or NULL if there is none
     *
     * @return void
     */
    private function discardBackup(?Backup $backup): void
    {
        try {
            $backup?->discard();
        } catch (Throwable $exception) {
            $this->io->writeError(
                sprintf(
                    '<warning>Could not remove the backup directory: %s. Remove "%s" manually before the next upgrade.</warning>',
                    $exception->getMessage(),
                    $this->projectDir . '/' . self::BACKUP_DIR
                )
            );
        }
    }

    /**
     * Runs the "pre-upgrade-cmd" script of the current version (if defined).
     *
     * @param ?Version $current The current version
     * @param array<string, string> $env The environment variables
     *
     * @return void
     *
     * @throws RuntimeException if the script fails
     */
    private function runPreUpgradeCmd(?array $current, array $env): void
    {
        if (!array_key_exists(self::PRE_UPGRADE_CMD, $this->composer->getPackage()->getScripts())) {
            return;
        }
        $this->io->write(['', 'Running pre-upgrade tasks...']);
        if ($current !== null) {
            $env['COMPOSER_ROOT_VERSION'] = $current['pretty_version'];
        }
        if ($this->process->run(['run-script', self::PRE_UPGRADE_CMD], $env) !== 0) {
            throw new RuntimeException('Running the pre-upgrade tasks failed.');
        }
    }

    /**
     * Removes the current lock file if the new version does not ship one.
     *
     * Otherwise "composer install" would install the old version's dependencies.
     *
     * @param string $releaseDir The directory holding the new version
     * @param ?Backup $backup The backup or NULL if there is none
     *
     * @return void
     *
     * @throws RuntimeException if deleting the lock file fails
     */
    private function removeStaleLockFile(string $releaseDir, ?Backup $backup): void
    {
        $lockFile = $this->projectDir . '/composer.lock';
        if (Files::exists($releaseDir . '/composer.lock') || !Files::exists($lockFile)) {
            return;
        }
        if ($backup !== null) {
            $backup->prepareChange($lockFile);
        } else {
            Files::delete($lockFile);
        }
    }

    /**
     * Installs the dependencies of the new version.
     *
     * @param PackageInterface $target The new version
     * @param array<string, string> $env The environment variables
     *
     * @return void
     *
     * @throws RuntimeException if installing the dependencies fails
     */
    private function installDependencies(PackageInterface $target, array $env): void
    {
        $this->io->write(['', 'Installing dependencies...']);
        $args = ['install'];
        if ($this->composer->getRepositoryManager()->getLocalRepository()->getDevMode() !== true) {
            $args[] = '--no-dev';
        }
        $env['COMPOSER_ROOT_VERSION'] = $target->getPrettyVersion();
        if ($this->process->run($args, $env) !== 0) {
            throw new RuntimeException('Installing the dependencies of the new version failed.');
        }
    }

    /**
     * Runs the "post-upgrade-cmd" script of the new version.
     *
     * @param PackageInterface $target The new version
     * @param array<string, string> $env The environment variables
     *
     * @return void
     *
     * @throws RuntimeException if the script fails
     */
    private function runPostUpgradeCmd(PackageInterface $target, array $env): void
    {
        $this->io->write(['', 'Running post-upgrade tasks...']);
        $env['COMPOSER_ROOT_VERSION'] = $target->getPrettyVersion();
        if ($this->process->run(['run-script', self::POST_UPGRADE_CMD], $env) !== 0) {
            throw new RuntimeException(
                'The new version is installed, but running its post-upgrade tasks failed.' . PHP_EOL
                . 'Fix the problem and run "composer run-script ' . self::POST_UPGRADE_CMD . '" manually.'
            );
        }
    }

    /**
     * Rolls back to the previous version after a failure.
     *
     * @param ?Backup $backup The backup or NULL if there is none
     * @param Throwable $exception The exception which caused the failure
     *
     * @return never
     *
     * @throws RuntimeException always
     */
    private function rollback(?Backup $backup, Throwable $exception): never
    {
        $message = 'Upgrading failed: ' . $exception->getMessage() . PHP_EOL;
        if ($backup === null) {
            throw new RuntimeException(
                $message . 'No backup was made, so the installation may be incomplete.',
                0,
                $exception
            );
        }
        $this->io->writeError(['', '<error>Upgrading failed. Restoring previous version...</error>']);
        try {
            $backup->rollback();
        } catch (Throwable $rollbackException) {
            throw new RuntimeException(
                $message . 'Restoring the previous version failed, too: ' . $rollbackException->getMessage(),
                0,
                $exception
            );
        }
        throw new RuntimeException($message . 'The previous version was restored.', 0, $exception);
    }

    /**
     * Gets the paths (relative to the project directory) which must never be touched.
     *
     * @return list<string> The paths
     */
    private function getIgnoredPaths(): array
    {
        $ignore = [self::BACKUP_DIR];
        $vendorDir = $this->getVendorDir();
        if ($vendorDir !== null && str_starts_with($vendorDir, $this->projectDir . '/')) {
            $ignore[] = substr($vendorDir, strlen($this->projectDir) + 1);
        }
        return $ignore;
    }

    /**
     * Gets the absolute path of the vendor directory.
     *
     * @return ?string The vendor directory or NULL if it does not exist
     */
    private function getVendorDir(): ?string
    {
        return $this->getConfiguredDir('vendor-dir');
    }

    /**
     * Gets the absolute path of a directory from Composer's configuration.
     *
     * @param string $key The configuration key, e.g. "vendor-dir"
     *
     * @return ?string The directory or NULL if it does not exist
     */
    private function getConfiguredDir(string $key): ?string
    {
        $dir = $this->composer->getConfig()->get($key);
        $dir = is_string($dir) ? realpath($dir) : false;
        return $dir === false ? null : $dir;
    }
}

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

use Composer\Command\BaseCommand;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Repository\PlatformRepository;
use Override;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Upgrades the application to another release or development branch.
 *
 * @see Upgrader for the actual upgrade process
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 *
 * @phpstan-import-type Version from VersionStore
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
final class UpgradeCommand extends BaseCommand
{
    /**
     * Configures the command.
     *
     * @return void
     */
    #[Override]
    protected function configure(): void
    {
        $this
            ->setName('app:upgrade')
            ->setDescription('Upgrades this application to another release')
            ->addArgument(
                'version',
                InputArgument::OPTIONAL,
                'The version to install or "latest" for the newest stable release',
                'latest'
            )
            ->addOption('list', 'l', InputOption::VALUE_NONE, 'List all available versions')
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Include development versions (branches)')
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Install even if the current version is equal to or newer than the target version'
            )
            ->addOption(
                'ignore-platform-reqs',
                null,
                InputOption::VALUE_NONE,
                'Ignore platform requirements (php & ext- packages) of the target version'
            )
            ->addOption(
                'no-backup',
                null,
                InputOption::VALUE_NONE,
                'Do not back up the current version (disables automatic rollback on failure)'
            )
            ->setHelp(
                <<<'EOT'
                    The <info>app:upgrade</info> command replaces the application's files with those of
                    another version fetched from the configured repositories (usually Packagist),
                    installs its dependencies and runs its upgrade scripts. If replacing the files
                    or installing the dependencies fails, the previous version is restored.

                    <info>composer app:upgrade --list</info>       List available stable versions
                    <info>composer app:upgrade --list --dev</info> List development versions, too
                    <info>composer app:upgrade</info>              Upgrade to the latest stable release
                    <info>composer app:upgrade 2.1.0</info>        Install version 2.1.0
                    <info>composer app:upgrade dev-main --dev</info> Install the "main" branch
                    EOT
            );
    }

    /**
     * Executes the command.
     *
     * @param InputInterface $input The input
     * @param OutputInterface $output The output
     *
     * @return int 0 if everything went fine, or an error code
     *
     * @throws RuntimeException if upgrading fails
     */
    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->upgrade($input);
        } catch (RuntimeException $exception) {
            if ($this->getIO()->isVerbose()) {
                throw $exception;
            }
            $this->getIO()->writeError(
                ['', '<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>']
            );
            return 1;
        }
    }

    /**
     * Performs the upgrade (or lists the available versions).
     *
     * @param InputInterface $input The input
     *
     * @return int 0 if everything went fine, or an error code
     *
     * @throws RuntimeException if upgrading fails
     */
    private function upgrade(InputInterface $input): int
    {
        $composer = $this->requireComposer();
        $io = $this->getIO();
        $name = $composer->getPackage()->getName();
        if ($name === '__root__') {
            throw new RuntimeException('The application\'s composer.json does not define a package name.');
        }
        $dev = (bool) $input->getOption('dev');

        $current = VersionStore::current($composer);
        $io->write(sprintf('Current version of <info>%s</info>: %s', $name, $this->format($current)));

        // The real platform is used, since "config.platform" describes the platform the lock file is created for,
        // not the server the application is running on.
        $releases = Releases::fetch(
            $name,
            $composer->getRepositoryManager()->getRepositories(),
            $dev,
            (bool) $input->getOption('ignore-platform-reqs') ? null : new PlatformRepository()
        );

        if ((bool) $input->getOption('list')) {
            $this->listVersions($releases, $current);
            return 0;
        }

        $requested = $input->getArgument('version');
        $target = $this->selectTarget($releases, is_string($requested) ? $requested : 'latest', $dev);
        $this->checkVersion($current, $target, (bool) $input->getOption('force'));

        $upgrader = new Upgrader(
            $composer,
            $io,
            $this->getProjectDir(),
            (bool) $input->getOption('ignore-platform-reqs')
        );
        $upgrader->checkLeftoverBackup();

        $io->write(sprintf('Target version: <info>%s</info>', $target->getFullPrettyVersion()));
        if (!$this->confirm($io, $target)) {
            $io->writeError('Aborted.');
            return 1;
        }

        $upgrader->run($target, $current, !(bool) $input->getOption('no-backup'));

        $io->write(['', sprintf('<info>%s %s was installed successfully.</info>', $name, $target->getPrettyVersion())]);
        return 0;
    }

    /**
     * Displays a warning and asks the user for confirmation.
     *
     * @param IOInterface $io The input/output handler
     * @param PackageInterface $target The version to install
     *
     * @return bool Whether the user confirmed
     */
    private function confirm(IOInterface $io, PackageInterface $target): bool
    {
        $io->write([
            '',
            '<warning>You are about to replace the application\'s files and run its upgrade scripts.</warning>',
            '<warning>This may include database migrations which can lead to data loss.</warning>',
            '<warning>Carefully read the release notes and always keep a BACKUP!</warning>'
        ]);
        if ($target->isDev()) {
            $io->write([
                '',
                '<comment>Development versions are considered unstable and not suitable for production.</comment>'
            ]);
        }
        return $io->askConfirmation(PHP_EOL . 'Continue? [<comment>Y,n</comment>] ', true);
    }

    /**
     * Checks whether the target version may be installed over the current version.
     *
     * @param ?Version $current The current version
     * @param PackageInterface $target The target version
     * @param bool $force Whether to skip the check
     *
     * @return void
     *
     * @throws RuntimeException if the target version is not newer
     */
    private function checkVersion(?array $current, PackageInterface $target, bool $force): void
    {
        if ($force || $current === null || $target->isDev() || VersionStore::isDev($current['version'])) {
            return;
        }
        $compare = version_compare($current['version'], $target->getVersion());
        if ($compare >= 0) {
            throw new RuntimeException(
                sprintf(
                    'The current version %s is %s %s. Use "--force" to install anyway.',
                    $current['pretty_version'],
                    $compare === 0 ? 'the same as' : 'newer than',
                    $target->getPrettyVersion()
                )
            );
        }
    }

    /**
     * Displays the available versions.
     *
     * @param Releases $releases The available versions
     * @param ?Version $current The current version
     *
     * @return void
     */
    private function listVersions(Releases $releases, ?array $current): void
    {
        $io = $this->getIO();
        if (count($releases->all()) === 0) {
            $io->write('No versions available. Use "--dev" to include development versions.');
            return;
        }
        $io->write('Available versions:');
        foreach ($releases->all() as $package) {
            $notes = [];
            if ($current !== null && $current['version'] === $package->getVersion()) {
                $notes[] = '<info>current</info>';
            }
            if ($package->getStability() !== 'stable') {
                $notes[] = '<comment>' . $package->getStability() . '</comment>';
            }
            if (count($releases->missingPlatformReqs($package)) > 0) {
                $notes[] = '<comment>platform requirements not met</comment>';
            }
            $io->write(
                ' - ' . $package->getPrettyVersion() . (count($notes) > 0 ? ' (' . implode(', ', $notes) . ')' : '')
            );
        }
    }

    /**
     * Selects the version to install.
     *
     * @param Releases $releases The available versions
     * @param string $requested The requested version or "latest"
     * @param bool $dev Whether development versions are allowed
     *
     * @return PackageInterface The version to install
     *
     * @throws RuntimeException if no suitable version is found
     */
    private function selectTarget(Releases $releases, string $requested, bool $dev): PackageInterface
    {
        if ($requested === 'latest') {
            $latest = $releases->latest();
            if (count($latest['skipped']) > 0) {
                $this->getIO()->writeError(
                    sprintf(
                        '<warning>Skipped newer version(s) %s because of unmet platform requirements.</warning>',
                        implode(', ', $latest['skipped'])
                    )
                );
            }
            return $latest['package'];
        }
        $target = $releases->find($requested);
        if ($target === null) {
            throw new RuntimeException(
                sprintf(
                    'Version "%s" not found. Use "--list%s" to see available versions.%s',
                    $requested,
                    $dev ? ' --dev' : '',
                    $dev ? '' : ' Development versions require "--dev".'
                )
            );
        }
        $missing = $releases->missingPlatformReqs($target);
        if (count($missing) > 0) {
            throw new RuntimeException(
                sprintf(
                    'Version %s requires %s, which is not available. Use "--ignore-platform-reqs" to ignore this.',
                    $target->getPrettyVersion(),
                    implode(', ', $missing)
                )
            );
        }
        return $target;
    }

    /**
     * Gets the project's root directory.
     *
     * @return string The absolute path
     *
     * @throws RuntimeException if the directory cannot be determined
     */
    private function getProjectDir(): string
    {
        $dir = realpath(dirname(Factory::getComposerFile()));
        if ($dir === false) {
            throw new RuntimeException('Could not determine the project directory.');
        }
        return $dir;
    }

    /**
     * Formats a version for output.
     *
     * @param ?Version $version The version
     *
     * @return string The formatted version
     */
    private function format(?array $version): string
    {
        return $version === null ? '<comment>unknown</comment>' : '<info>' . $version['pretty_version'] . '</info>';
    }
}

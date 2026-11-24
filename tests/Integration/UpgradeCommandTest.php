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

namespace OCC\ComposerUpdater\Tests\Integration;

use OCC\ComposerUpdater\Files;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use OCC\ComposerUpdater\UpgradeCommand;
use OCC\ComposerUpdater\Upgrader;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * End-to-end tests running the real Composer binary against a local Git repository.
 *
 * The test application "acme/app" has these versions:
 *  - 1.0.0: initial release
 *  - 1.1.0: renames src/Old.php to src/New.php, adds post-upgrade-cmd
 *  - 1.2.0-RC1: fails while installing its dependencies
 *  - 2.0.0: requires PHP >= 99 (on branch "release-2")
 *  - dev-main: one commit after 1.1.0 (1.2.0-RC1 is on branch "rc")
 *
 * Every version defines a pre-upgrade-cmd which fails if the environment
 * variable FAIL_PRE_UPGRADE is set. All scripts log to "upgrade.log".
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(UpgradeCommand::class)]
#[CoversClass(Upgrader::class)]
#[Group('integration')]
#[RequiresOperatingSystemFamily('Linux')]
final class UpgradeCommandTest extends FilesystemTestCase
{
    /**
     * The Git repository of the test application (shared by all tests).
     */
    private static ?string $repository = null;

    /**
     * The isolated Composer home directory (shared by all tests).
     */
    private static ?string $composerHome = null;

    /**
     * Builds the test application's repository.
     *
     * @return void
     */
    #[Override]
    public static function setUpBeforeClass(): void
    {
        $finder = new ExecutableFinder();
        if ($finder->find('git') === null || $finder->find('composer') === null) {
            return;
        }
        $base = sys_get_temp_dir() . '/composer-updater-integration-' . bin2hex(random_bytes(8));
        mkdir($base . '/home', 0777, true);
        self::$composerHome = $base . '/home';
        self::$repository = $base . '/repository';
        self::buildRepository(self::$repository);
    }

    /**
     * Removes the test application's repository.
     *
     * @return void
     */
    #[Override]
    public static function tearDownAfterClass(): void
    {
        if (self::$repository !== null) {
            Files::delete(dirname(self::$repository));
        }
        self::$repository = null;
        self::$composerHome = null;
    }

    /**
     * Skips the tests if Git or Composer are not available.
     *
     * @return void
     */
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        if (self::$repository === null) {
            self::markTestSkipped('The integration tests require "git" and "composer" to be in the PATH.');
        }
    }

    /**
     * Tests listing the available versions.
     *
     * @return void
     */
    public function testListVersions(): void
    {
        $app = $this->deploy('1.0.0');

        $output = $this->composer($app, ['app:upgrade', '--list']);

        self::assertStringContainsString('Current version of acme/app: 1.0.0', $output);
        self::assertMatchesRegularExpression(
            '/2\.0\.0 \(platform requirements not met\).*1\.2\.0-RC1 \(RC\).*1\.1\.0.*1\.0\.0 \(current\)/s',
            $output
        );
        self::assertStringNotContainsString('dev-main', $output);

        self::assertStringContainsString('dev-main (dev)', $this->composer($app, ['app:upgrade', '--list', '--dev']));
    }

    /**
     * Tests a successful upgrade.
     *
     * @return void
     */
    public function testUpgrade(): void
    {
        $app = $this->deploy('1.0.0');
        $this->writeFiles($app, [
            'config/settings.php' => 'locally modified',
            'src/Stray.php' => 'stray file',
            'data/upload.txt' => 'user data'
        ]);

        $output = $this->composer($app, ['app:upgrade', '1.1.0']);

        self::assertStringContainsString('acme/app 1.1.0 was installed successfully.', $output);
        self::assertSame(['Common.php', 'New.php'], Files::list($app . '/src'), 'src/ is mirrored.');
        self::assertStringEqualsFile($app . '/src/Common.php', 'common 1.1.0');
        self::assertStringEqualsFile($app . '/config/settings.php', 'locally modified');
        self::assertStringEqualsFile($app . '/data/upload.txt', 'user data');
        self::assertSame(
            ['pre 1.0.0 -> 1.1.0 (root version 1.0.0)', 'post 1.0.0 -> 1.1.0 (root version 1.1.0)'],
            $this->readLog($app)
        );
        self::assertSame('1.1.0', $this->recordedVersion($app));
        self::assertFileDoesNotExist($app . '/' . Upgrader::BACKUP_DIR);
    }

    /**
     * Tests upgrading to the latest release skipping releases with unmet requirements.
     *
     * @return void
     */
    public function testUpgradeToLatest(): void
    {
        $app = $this->deploy('1.0.0');

        $output = $this->composer($app, ['app:upgrade']);

        self::assertStringContainsString('Skipped newer version(s) 2.0.0', $output);
        self::assertStringContainsString('acme/app 1.1.0 was installed successfully.', $output);
        self::assertSame('1.1.0', $this->recordedVersion($app));
    }

    /**
     * Tests the version checks.
     *
     * @return void
     */
    public function testVersionChecks(): void
    {
        $app = $this->deploy('1.1.0');

        $output = $this->composer($app, ['app:upgrade', '1.0.0'], 1);
        self::assertStringContainsString('The current version 1.1.0 is newer than 1.0.0.', $output);

        $output = $this->composer($app, ['app:upgrade', 'dev-main'], 1);
        self::assertStringContainsString('Development versions require "--dev"', $output);

        $output = $this->composer($app, ['app:upgrade', '2.0.0'], 1);
        self::assertStringContainsString('Version 2.0.0 requires php >=99', $output);
        self::assertStringNotContainsString('UpgradeCommand.php', $output, 'Errors must not be shown as stack traces.');

        $output = $this->composer($app, ['app:upgrade', 'dev-main', '--dev']);
        self::assertStringContainsString('acme/app dev-main was installed successfully.', $output);
        self::assertSame('dev-main', $this->recordedVersion($app));

        $output = $this->composer($app, ['app:upgrade', '1.0.0', '--force']);
        self::assertStringContainsString('acme/app 1.0.0 was installed successfully.', $output);
        self::assertSame('1.0.0', $this->recordedVersion($app));
    }

    /**
     * Tests rolling back when installing the dependencies fails.
     *
     * @return void
     */
    public function testFailedInstallIsRolledBack(): void
    {
        $app = $this->deploy('1.1.0');
        $this->writeFiles($app, ['src/Stray.php' => 'stray file']);
        $before = $this->readTree($app);

        $output = $this->composer($app, ['app:upgrade', '1.2.0-RC1'], 1);

        self::assertStringContainsString('Installing the dependencies of the new version failed.', $output);
        self::assertStringContainsString('The previous version was restored.', $output);
        $after = $this->readTree($app);
        unset($after['upgrade.log']);
        self::assertSame($before, $after);
        self::assertSame(['pre 1.1.0 -> 1.2.0-RC1 (root version 1.1.0)'], $this->readLog($app));
    }

    /**
     * Tests aborting when the pre-upgrade tasks fail.
     *
     * @return void
     */
    public function testFailedPreUpgradeCmdChangesNothing(): void
    {
        $app = $this->deploy('1.0.0');
        $before = $this->readTree($app);

        $output = $this->composer($app, ['app:upgrade', '1.1.0'], 1, ['FAIL_PRE_UPGRADE' => '1']);

        self::assertStringContainsString('Running the pre-upgrade tasks failed.', $output);
        self::assertSame($before, $this->readTree($app));
    }

    /**
     * Tests refusing to upgrade while a backup from a failed upgrade exists.
     *
     * @return void
     */
    public function testLeftoverBackupBlocksUpgrade(): void
    {
        $app = $this->deploy('1.0.0');
        $this->writeFiles($app, [Upgrader::BACKUP_DIR . '/0' => 'left over']);
        $before = $this->readTree($app);

        $output = $this->composer($app, ['app:upgrade', '1.1.0'], 1);

        self::assertStringContainsString('already exists', $output);
        self::assertStringNotContainsString('You are about to', $output, 'The check must precede the confirmation.');
        self::assertSame($before, $this->readTree($app), 'Neither files nor pre-upgrade-cmd must have run.');

        $output = $this->composer($app, ['app:upgrade', '1.1.0', '--no-backup'], 1);

        self::assertStringContainsString('already exists', $output, 'The check must not depend on making a backup.');
        self::assertSame($before, $this->readTree($app));
    }

    /**
     * Tests checking the platform requirements against the real platform, ignoring "config.platform".
     *
     * @return void
     */
    public function testPlatformOverridesAreIgnored(): void
    {
        $app = $this->deploy('1.1.0');
        $this->composer($app, ['config', 'platform.php', '99.0.0']);

        $output = $this->composer($app, ['app:upgrade', '--list']);
        self::assertStringContainsString('2.0.0 (platform requirements not met)', $output);

        $output = $this->composer($app, ['app:upgrade', '2.0.0'], 1);
        self::assertStringContainsString('Version 2.0.0 requires php >=99', $output);
    }

    /**
     * Tests installing a version with unmet platform requirements.
     *
     * @return void
     */
    public function testIgnorePlatformReqs(): void
    {
        $app = $this->deploy('1.1.0');

        $output = $this->composer($app, ['app:upgrade', '2.0.0', '--ignore-platform-reqs']);

        self::assertStringContainsString('acme/app 2.0.0 was installed successfully.', $output);
        self::assertSame('2.0.0', $this->recordedVersion($app));
        self::assertSame('common 2.0.0', file_get_contents($app . '/src/Common.php'));
    }

    /**
     * Tests upgrading without a backup.
     *
     * @return void
     */
    public function testUpgradeWithoutBackup(): void
    {
        $app = $this->deploy('1.0.0');

        $output = $this->composer($app, ['app:upgrade', '1.1.0', '--no-backup']);

        self::assertStringNotContainsString('Backing up', $output);
        self::assertStringContainsString('acme/app 1.1.0 was installed successfully.', $output);
    }

    /**
     * Deploys a version of the test application with "composer create-project".
     *
     * @param string $version The version
     *
     * @return string The application's directory
     */
    private function deploy(string $version): string
    {
        $app = $this->createTempDir() . '/app';
        $this->composer(dirname($app), [
            'create-project',
            'acme/app',
            $app,
            $version,
            '--repository=' . json_encode(['type' => 'vcs', 'url' => self::$repository], JSON_THROW_ON_ERROR),
            '--repository=' . json_encode(self::updaterRepository(), JSON_THROW_ON_ERROR)
        ]);
        self::assertSame($version, $this->recordedVersion($app), 'The version is recorded after create-project.');
        return $app;
    }

    /**
     * Runs Composer.
     *
     * @param string $dir The working directory
     * @param list<string> $args The arguments
     * @param int $expectedExitCode The expected exit code
     * @param array<string, string> $env Additional environment variables
     *
     * @return string The combined output
     */
    private function composer(string $dir, array $args, int $expectedExitCode = 0, array $env = []): string
    {
        $process = new Process(
            ['composer', ...$args, '--no-interaction', '--no-ansi'],
            $dir,
            $env + [
                'COMPOSER_HOME' => self::$composerHome,
                'COMPOSER_ROOT_VERSION' => false,
                'COMPOSER_NO_INTERACTION' => '1',
                'XDEBUG_MODE' => 'off',
                'COLUMNS' => '500'
            ],
            null,
            300
        );
        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        self::assertSame(
            $expectedExitCode,
            $process->getExitCode() === 0 ? 0 : 1,
            sprintf('Unexpected exit code of "composer %s":%s%s', implode(' ', $args), PHP_EOL, $output)
        );
        return $output;
    }

    /**
     * Reads the version recorded by the plugin.
     *
     * @param string $app The application's directory
     *
     * @return ?string The pretty version or NULL if none is recorded
     */
    private function recordedVersion(string $app): ?string
    {
        $file = $app . '/vendor/composer-updater.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($data) && is_string($data['pretty_version'] ?? null) ? $data['pretty_version'] : null;
    }

    /**
     * Reads the log written by the test application's scripts.
     *
     * @param string $app The application's directory
     *
     * @return list<string> The log lines
     */
    private function readLog(string $app): array
    {
        $file = $app . '/upgrade.log';
        $lines = is_file($file) ? file($file, FILE_IGNORE_NEW_LINES) : false;
        return $lines === false ? [] : $lines;
    }

    /**
     * Gets the repository configuration for this plugin.
     *
     * @return array<string, mixed> The repository configuration
     */
    private static function updaterRepository(): array
    {
        return [
            'type' => 'path',
            'url' => dirname(__DIR__, 2),
            'options' => ['versions' => ['opencultureconsulting/updater' => '1.0.0']]
        ];
    }

    /**
     * Builds the Git repository of the test application.
     *
     * @param string $dir The repository directory
     *
     * @return void
     */
    private static function buildRepository(string $dir): void
    {
        mkdir($dir, 0777, true);
        self::git($dir, ['init', '--quiet', '--initial-branch=main']);
        self::git($dir, ['config', 'user.name', 'Test']);
        self::git($dir, ['config', 'user.email', 'test@example.com']);
        self::git($dir, ['config', 'commit.gpgSign', 'false']);
        self::git($dir, ['config', 'tag.gpgSign', 'false']);

        $log = <<<'PHP'
            <?php
            if ($argv[1] === 'pre' && getenv('FAIL_PRE_UPGRADE') !== false) {
                exit(1);
            }
            file_put_contents(
                __DIR__ . '/../upgrade.log',
                sprintf(
                    "%s %s -> %s (root version %s)\n",
                    $argv[1],
                    getenv('COMPOSER_UPDATER_PREVIOUS_VERSION'),
                    getenv('COMPOSER_UPDATER_VERSION'),
                    getenv('COMPOSER_ROOT_VERSION')
                ),
                FILE_APPEND
            );
            PHP;
        $scripts = ['pre-upgrade-cmd' => '@php bin/log.php pre'];

        self::commit($dir, '1.0.0', $scripts, [], [
            'bin/log.php' => $log,
            'src/Old.php' => 'old',
            'src/Common.php' => 'common 1.0.0',
            'config/settings.php' => 'settings 1.0.0'
        ]);
        unlink($dir . '/src/Old.php');
        $scripts['post-upgrade-cmd'] = '@php bin/log.php post';
        self::commit($dir, '1.1.0', $scripts, [], [
            'src/New.php' => 'new',
            'src/Common.php' => 'common 1.1.0',
            'config/settings.php' => 'settings 1.1.0'
        ]);

        self::git($dir, ['checkout', '--quiet', '-b', 'rc']);
        self::commit($dir, '1.2.0-RC1', $scripts + ['post-install-cmd' => '@php bin/fail.php', 'post-update-cmd' => '@php bin/fail.php'], [], [
            'bin/fail.php' => '<?php exit(1);',
            'src/Common.php' => 'common 1.2.0-RC1'
        ]);

        self::git($dir, ['checkout', '--quiet', 'main']);
        self::git($dir, ['checkout', '--quiet', '-b', 'release-2']);
        self::commit($dir, '2.0.0', $scripts, ['php' => '>=99'], ['src/Common.php' => 'common 2.0.0']);

        self::git($dir, ['checkout', '--quiet', 'main']);
        self::commit($dir, null, $scripts, [], ['src/Common.php' => 'common dev-main']);
    }

    /**
     * Commits a version of the test application.
     *
     * @param string $dir The repository directory
     * @param ?string $tag The version to tag or NULL for none
     * @param array<string, string> $scripts The scripts
     * @param array<string, string> $requires Additional requirements
     * @param array<string, string> $files The files to write
     *
     * @return void
     */
    private static function commit(string $dir, ?string $tag, array $scripts, array $requires, array $files): void
    {
        $files['composer.json'] = json_encode(
            [
                'name' => 'acme/app',
                'type' => 'project',
                'require' => ['opencultureconsulting/updater' => '*'] + $requires,
                'repositories' => [
                    ['type' => 'vcs', 'url' => $dir],
                    self::updaterRepository(),
                    ['packagist.org' => false]
                ],
                'config' => ['allow-plugins' => ['opencultureconsulting/updater' => true]],
                'scripts' => $scripts,
                'extra' => [
                    'composer-updater' => ['mirror' => ['src'], 'preserve' => ['config/settings.php']]
                ]
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($dir . '/' . $path))) {
                mkdir(dirname($dir . '/' . $path), 0777, true);
            }
            file_put_contents($dir . '/' . $path, $content);
        }
        self::git($dir, ['add', '--all']);
        self::git($dir, ['commit', '--quiet', '--message', $tag ?? 'development']);
        if ($tag !== null) {
            self::git($dir, ['tag', '--annotate', $tag, '--message', $tag]);
        }
    }

    /**
     * Runs Git.
     *
     * @param string $dir The working directory
     * @param list<string> $args The arguments
     *
     * @return void
     *
     * @throws RuntimeException if Git fails
     */
    private static function git(string $dir, array $args): void
    {
        $process = new Process(['git', ...$args], $dir);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('git ' . implode(' ', $args) . ' failed: ' . $process->getErrorOutput());
        }
    }
}

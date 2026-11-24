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
use OCC\ComposerUpdater\ReleaseConfig;
use OCC\ComposerUpdater\ReplaceRules;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;

/**
 * Tests for reading a release's configuration.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(ReleaseConfig::class)]
#[UsesClass(Files::class)]
#[UsesClass(ReplaceRules::class)]
final class ReleaseConfigTest extends FilesystemTestCase
{
    /**
     * Tests reading a valid configuration.
     *
     * @return void
     */
    public function testReadsConfiguration(): void
    {
        $dir = $this->createTempDir([
            'composer.json' => json_encode([
                'name' => 'acme/app',
                'scripts' => ['post-upgrade-cmd' => 'migrate', 'test' => 'phpunit'],
                'extra' => ['composer-updater' => ['mirror' => ['src']], 'other' => true]
            ], JSON_THROW_ON_ERROR)
        ]);

        $config = ReleaseConfig::fromDirectory($dir, 'acme/app', ['vendor']);

        self::assertTrue($config->hasScript('post-upgrade-cmd'));
        self::assertFalse($config->hasScript('pre-upgrade-cmd'));
        self::assertSame('mirror', $config->rules->for('src/Foo.php'));
        self::assertSame('ignore', $config->rules->for('vendor/autoload.php'));
    }

    /**
     * Tests the defaults when nothing is configured.
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $dir = $this->createTempDir(['composer.json' => '{"name": "acme/app"}']);

        $config = ReleaseConfig::fromDirectory($dir, 'acme/app');

        self::assertSame([], $config->scripts);
        self::assertSame('copy', $config->rules->for('src/Foo.php'));
    }

    /**
     * Provides invalid releases.
     *
     * @return array<string, array{array<string, ?string>, string}>
     */
    public static function invalidProvider(): array
    {
        return [
            'missing composer.json' => [['README.md' => 'x'], 'valid composer.json'],
            'invalid JSON' => [['composer.json' => '{'], 'valid composer.json'],
            'other package' => [['composer.json' => '{"name": "evil/app"}'], 'not a version of acme/app'],
            'invalid configuration' => [
                ['composer.json' => '{"name": "acme/app", "extra": {"composer-updater": "src"}}'],
                'must be an object'
            ]
        ];
    }

    /**
     * Tests rejecting invalid releases.
     *
     * @param array<string, ?string> $files The release's files
     * @param string $message The expected part of the error message
     *
     * @return void
     */
    #[DataProvider('invalidProvider')]
    public function testRejectsInvalidRelease(array $files, string $message): void
    {
        $dir = $this->createTempDir($files);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        ReleaseConfig::fromDirectory($dir, 'acme/app');
    }
}

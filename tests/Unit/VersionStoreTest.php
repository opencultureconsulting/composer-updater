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

use Composer\Composer;
use Composer\Config;
use Composer\Package\RootPackage;
use OCC\ComposerUpdater\VersionStore;
use OCC\ComposerUpdater\Tests\FilesystemTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests for determining and recording the installed version.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(VersionStore::class)]
final class VersionStoreTest extends FilesystemTestCase
{
    /**
     * Tests that a recorded version takes precedence over the detected one.
     *
     * @return void
     */
    public function testRecordedVersionTakesPrecedence(): void
    {
        $composer = $this->createComposer('dev-main', 'dev-main');

        VersionStore::write($composer, '1.2.0', '1.2.0.0');

        self::assertSame(['pretty_version' => '1.2.0', 'version' => '1.2.0.0'], VersionStore::current($composer));
    }

    /**
     * Tests falling back to the version detected by Composer.
     *
     * @return void
     */
    public function testFallsBackToDetectedVersion(): void
    {
        $composer = $this->createComposer('2.0.0.0', '2.0.0');

        self::assertNull(VersionStore::read($composer));
        self::assertSame(['pretty_version' => '2.0.0', 'version' => '2.0.0.0'], VersionStore::current($composer));
    }

    /**
     * Tests that Composer's dummy version is treated as unknown.
     *
     * @return void
     */
    public function testDummyVersionIsUnknown(): void
    {
        $composer = $this->createComposer('1.0.0.0', RootPackage::DEFAULT_PRETTY_VERSION);

        self::assertNull(VersionStore::current($composer));
    }

    /**
     * Tests that a corrupt version file is ignored.
     *
     * @return void
     */
    public function testCorruptFileIsIgnored(): void
    {
        $composer = $this->createComposer('2.0.0.0', '2.0.0');
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        self::assertIsString($vendorDir);
        file_put_contents($vendorDir . '/composer-updater.json', '{"version": 42}');

        self::assertNull(VersionStore::read($composer));
        self::assertSame('2.0.0', VersionStore::current($composer)['pretty_version'] ?? null);
    }

    /**
     * Tests removing the version file.
     *
     * @return void
     */
    public function testRemove(): void
    {
        $composer = $this->createComposer('2.0.0.0', '2.0.0');
        VersionStore::write($composer, '1.0.0', '1.0.0.0');

        VersionStore::remove($composer);

        self::assertNull(VersionStore::read($composer));
    }

    /**
     * Provides normalized versions and whether they are development versions.
     *
     * @return array<string, array{string, bool}>
     */
    public static function devVersionProvider(): array
    {
        return [
            'release' => ['1.2.0.0', false],
            'pre-release' => ['1.2.0.0-beta1', false],
            'branch' => ['dev-main', true],
            'branch alias' => ['1.2.9999999.9999999-dev', true]
        ];
    }

    /**
     * Tests detecting development versions.
     *
     * @param string $version The normalized version
     * @param bool $expected Whether it is a development version
     *
     * @return void
     */
    #[DataProvider('devVersionProvider')]
    public function testIsDev(string $version, bool $expected): void
    {
        self::assertSame($expected, VersionStore::isDev($version));
    }

    /**
     * Creates a minimal Composer instance.
     *
     * @param string $version The root package's normalized version
     * @param string $prettyVersion The root package's pretty version
     *
     * @return Composer The Composer instance
     */
    private function createComposer(string $version, string $prettyVersion): Composer
    {
        $dir = $this->createTempDir(['vendor' => null]);
        $config = new Config(false, $dir);
        $config->merge(['config' => ['vendor-dir' => $dir . '/vendor']]);
        $composer = new Composer();
        $composer->setConfig($config);
        $composer->setPackage(new RootPackage('acme/app', $version, $prettyVersion));
        return $composer;
    }
}

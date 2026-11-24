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

use Composer\Package\AliasPackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\ArrayRepository;
use Composer\Repository\PlatformRepository;
use OCC\ComposerUpdater\Releases;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the version selection.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(Releases::class)]
final class ReleasesTest extends TestCase
{
    /**
     * Tests sorting: stable and pre-releases by version descending, development versions last.
     *
     * @return void
     */
    public function testAllIsSorted(): void
    {
        $releases = new Releases([
            self::package('1.0.0'),
            self::package('dev-main'),
            self::package('1.10.0'),
            self::package('2.0.0-beta1'),
            self::package('1.9.0'),
            self::package('1.x-dev')
        ]);

        self::assertSame(
            ['2.0.0-beta1', '1.10.0', '1.9.0', '1.0.0', '1.x-dev', 'dev-main'],
            self::versions($releases->all())
        );
    }

    /**
     * Tests that alias packages and duplicates are dropped.
     *
     * @return void
     */
    public function testAliasesAndDuplicatesAreDropped(): void
    {
        $main = self::package('dev-main');
        $releases = new Releases([
            self::package('1.0.0'),
            self::package('1.0.0'),
            $main,
            new AliasPackage($main, '9999999-dev', 'dev-default')
        ]);

        self::assertSame(['1.0.0', 'dev-main'], self::versions($releases->all()));
    }

    /**
     * Tests fetching versions from repositories.
     *
     * @return void
     */
    public function testFetchFiltersDevelopmentVersions(): void
    {
        $repository = new ArrayRepository([
            self::package('1.0.0'),
            self::package('1.1.0-RC1'),
            self::package('dev-main'),
            new CompletePackage('acme/other', '2.0.0.0', '2.0.0')
        ]);

        self::assertSame(
            ['1.1.0-RC1', '1.0.0'],
            self::versions(Releases::fetch('acme/app', [$repository], false, null)->all())
        );
        self::assertSame(
            ['1.1.0-RC1', '1.0.0', 'dev-main'],
            self::versions(Releases::fetch('acme/app', [$repository], true, null)->all())
        );
    }

    /**
     * Provides requested version strings and the expected matches.
     *
     * @return array<string, array{string, ?string}>
     */
    public static function findProvider(): array
    {
        return [
            'pretty version' => ['1.2.0', '1.2.0'],
            'with "v" prefix' => ['v1.2.0', '1.2.0'],
            'normalized version' => ['1.2.0.0', '1.2.0'],
            'branch' => ['dev-main', 'dev-main'],
            'unknown version' => ['3.0.0', null],
            'invalid version' => ['not a version', null]
        ];
    }

    /**
     * Tests finding a version.
     *
     * @param string $requested The requested version
     * @param ?string $expected The expected pretty version or NULL if not found
     *
     * @return void
     */
    #[DataProvider('findProvider')]
    public function testFind(string $requested, ?string $expected): void
    {
        $releases = new Releases([self::package('1.2.0'), self::package('dev-main')]);

        self::assertSame($expected, $releases->find($requested)?->getPrettyVersion());
    }

    /**
     * Tests selecting the latest stable release.
     *
     * @return void
     */
    public function testLatestSkipsPreReleasesAndUnmetRequirements(): void
    {
        $releases = new Releases(
            [
                self::package('1.0.0'),
                self::package('1.1.0', ['php' => '>=8.0']),
                self::package('2.0.0', ['php' => '>=99']),
                self::package('2.1.0-beta1'),
                self::package('dev-main')
            ],
            self::platform()
        );

        $latest = $releases->latest();

        self::assertSame('1.1.0', $latest['package']->getPrettyVersion());
        self::assertSame(['2.0.0'], $latest['skipped']);
    }

    /**
     * Tests that platform requirements are ignored without a platform repository.
     *
     * @return void
     */
    public function testLatestIgnoresRequirementsWithoutPlatform(): void
    {
        $releases = new Releases([self::package('1.0.0'), self::package('2.0.0', ['php' => '>=99'])]);

        self::assertSame('2.0.0', $releases->latest()['package']->getPrettyVersion());
    }

    /**
     * Tests the error when no stable release exists.
     *
     * @return void
     */
    public function testLatestWithoutStableRelease(): void
    {
        $releases = new Releases([self::package('1.0.0-beta1'), self::package('dev-main')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No stable release available');
        $releases->latest();
    }

    /**
     * Tests the error when no stable release meets the platform requirements.
     *
     * @return void
     */
    public function testLatestWithUnmetRequirements(): void
    {
        $releases = new Releases([self::package('1.0.0', ['php' => '>=99'])], self::platform());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--ignore-platform-reqs');
        $releases->latest();
    }

    /**
     * Tests detecting unmet platform requirements.
     *
     * @return void
     */
    public function testMissingPlatformReqs(): void
    {
        $releases = new Releases([], self::platform());
        $package = self::package('1.0.0', [
            'php' => '>=99',
            'ext-does-not-exist' => '*',
            'acme/library' => '^1.0',
            'php-64bit' => '*'
        ]);

        self::assertSame(['php >=99', 'ext-does-not-exist *'], $releases->missingPlatformReqs($package));
    }

    /**
     * Creates a version of the test application.
     *
     * @param string $version The pretty version
     * @param array<string, string> $requires The requirements: package name => constraint
     *
     * @return CompletePackage The package
     */
    private static function package(string $version, array $requires = []): CompletePackage
    {
        $parser = new VersionParser();
        $package = new CompletePackage('acme/app', $parser->normalize($version), $version);
        $links = [];
        foreach ($requires as $target => $constraint) {
            $links[$target] = new Link(
                'acme/app',
                $target,
                $parser->parseConstraints($constraint),
                Link::TYPE_REQUIRE,
                $constraint
            );
        }
        $package->setRequires($links);
        return $package;
    }

    /**
     * Creates a platform repository with a fixed PHP version.
     *
     * @return PlatformRepository The platform repository
     */
    private static function platform(): PlatformRepository
    {
        return new PlatformRepository([], ['php' => '8.2.0']);
    }

    /**
     * Gets the pretty versions of packages.
     *
     * @param list<PackageInterface> $packages The packages
     *
     * @return list<string> The pretty versions
     */
    private static function versions(array $packages): array
    {
        return array_map(static fn (PackageInterface $package): string => $package->getPrettyVersion(), $packages);
    }
}

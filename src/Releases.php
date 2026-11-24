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

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\PlatformRepository;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\RepositorySet;
use RuntimeException;
use UnexpectedValueException;

/**
 * The available versions of an application and the logic to select one.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class Releases
{
    /**
     * The available versions, newest stable release first, development versions last.
     *
     * @var list<PackageInterface>
     */
    private array $packages;

    /**
     * Creates a new list of versions.
     *
     * @param list<PackageInterface> $packages The available versions in any order
     * @param ?PlatformRepository $platform The platform to check requirements against or NULL to ignore them
     */
    public function __construct(array $packages, private readonly ?PlatformRepository $platform = null)
    {
        $unique = [];
        foreach ($packages as $package) {
            if (!$package instanceof AliasPackage) {
                $unique[$package->getVersion()] ??= $package;
            }
        }
        $this->packages = array_values($unique);
        usort($this->packages, self::compare(...));
    }

    /**
     * Fetches the versions of a package from repositories.
     *
     * @param string $name The package name
     * @param array<array-key, RepositoryInterface> $repositories The repositories to search
     * @param bool $dev Whether to include development versions
     * @param ?PlatformRepository $platform The platform to check requirements against or NULL to ignore them
     *
     * @return self The available versions
     */
    public static function fetch(string $name, array $repositories, bool $dev, ?PlatformRepository $platform): self
    {
        $repositorySet = new RepositorySet('dev');
        foreach ($repositories as $repository) {
            $repositorySet->addRepository($repository);
        }
        $packages = array_filter(
            $repositorySet->findPackages($name),
            static fn (PackageInterface $package): bool => $dev || !$package->isDev()
        );
        return new self(array_values($packages), $platform);
    }

    /**
     * Gets all versions.
     *
     * @return list<PackageInterface> The versions, newest stable release first, development versions last
     */
    public function all(): array
    {
        return $this->packages;
    }

    /**
     * Finds a version by its pretty or normalized version string.
     *
     * @param string $requested The requested version, e.g. "v1.2.0", "1.2.0" or "dev-main"
     *
     * @return ?PackageInterface The version or NULL if not found
     */
    public function find(string $requested): ?PackageInterface
    {
        try {
            $normalized = (new VersionParser())->normalize($requested);
        } catch (UnexpectedValueException) {
            $normalized = null;
        }
        foreach ($this->packages as $package) {
            if ($package->getPrettyVersion() === $requested || $package->getVersion() === $normalized) {
                return $package;
            }
        }
        return null;
    }

    /**
     * Gets the newest stable release meeting the platform requirements.
     *
     * @return array{package: PackageInterface, skipped: list<string>} The release and newer releases skipped
     *
     * @throws RuntimeException if there is no suitable release
     */
    public function latest(): array
    {
        $skipped = [];
        foreach ($this->packages as $package) {
            if ($package->getStability() !== 'stable') {
                continue;
            }
            if (count($this->missingPlatformReqs($package)) === 0) {
                return ['package' => $package, 'skipped' => $skipped];
            }
            $skipped[] = $package->getPrettyVersion();
        }
        throw new RuntimeException(
            count($skipped) > 0
                ? 'No stable release meets the platform requirements. Use "--ignore-platform-reqs" to ignore them.'
                : 'No stable release available. Specify the target version explicitly.'
        );
    }

    /**
     * Gets the platform requirements of a version which are not met.
     *
     * @param PackageInterface $package The version
     *
     * @return list<string> The unmet requirements, e.g. "php >=8.4"
     */
    public function missingPlatformReqs(PackageInterface $package): array
    {
        if ($this->platform === null) {
            return [];
        }
        $missing = [];
        foreach ($package->getRequires() as $link) {
            if (
                PlatformRepository::isPlatformPackage($link->getTarget())
                && $this->platform->findPackage($link->getTarget(), $link->getConstraint()) === null
            ) {
                $missing[] = $link->getTarget() . ' ' . $link->getPrettyConstraint();
            }
        }
        return $missing;
    }

    /**
     * Compares two versions for sorting.
     *
     * @param PackageInterface $first The first version
     * @param PackageInterface $second The second version
     *
     * @return int The comparison result
     */
    private static function compare(PackageInterface $first, PackageInterface $second): int
    {
        if ($first->isDev() !== $second->isDev()) {
            return $first->isDev() ? 1 : -1;
        }
        if ($first->isDev()) {
            return strnatcasecmp($first->getPrettyVersion(), $second->getPrettyVersion());
        }
        return version_compare($second->getVersion(), $first->getVersion());
    }
}

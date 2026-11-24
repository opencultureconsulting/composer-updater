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
use Composer\Package\RootPackage;

/**
 * Determines and persists the installed version of the application.
 *
 * The version is stored in a small JSON file inside the vendor directory,
 * which is left alone when the application's files are replaced.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 *
 * @phpstan-type Version = array{pretty_version: string, version: string}
 */
final class VersionStore
{
    /**
     * The name of the file holding the version information.
     */
    private const FILENAME = 'composer-updater.json';

    /**
     * Determines the currently installed version of the application.
     *
     * A version recorded by this plugin takes precedence, because Composer
     * cannot detect the root package's version without VCS metadata and
     * falls back to a dummy version in that case.
     *
     * @param Composer $composer The Composer instance
     *
     * @return ?Version The current version or NULL if unknown
     */
    public static function current(Composer $composer): ?array
    {
        $stored = self::read($composer);
        if ($stored !== null) {
            return $stored;
        }
        $package = $composer->getPackage();
        if ($package->getPrettyVersion() === RootPackage::DEFAULT_PRETTY_VERSION) {
            return null;
        }
        return [
            'pretty_version' => $package->getPrettyVersion(),
            'version' => $package->getVersion()
        ];
    }

    /**
     * Checks whether a normalized version denotes a development version.
     *
     * @param string $version The normalized version
     *
     * @return bool Whether it is a development version
     */
    public static function isDev(string $version): bool
    {
        return str_starts_with($version, 'dev-') || str_ends_with($version, '-dev');
    }

    /**
     * Reads the recorded version.
     *
     * @param Composer $composer The Composer instance
     *
     * @return ?Version The recorded version or NULL if there is none
     */
    public static function read(Composer $composer): ?array
    {
        $file = self::path($composer);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (
            !is_array($data)
            || !isset($data['pretty_version'], $data['version'])
            || !is_string($data['pretty_version'])
            || !is_string($data['version'])
        ) {
            return null;
        }
        return [
            'pretty_version' => $data['pretty_version'],
            'version' => $data['version']
        ];
    }

    /**
     * Removes the recorded version.
     *
     * @param Composer $composer The Composer instance
     *
     * @return void
     */
    public static function remove(Composer $composer): void
    {
        $file = self::path($composer);
        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Records the given version.
     *
     * @param Composer $composer The Composer instance
     * @param string $prettyVersion The human-readable version
     * @param string $version The normalized version
     *
     * @return void
     */
    public static function write(Composer $composer, string $prettyVersion, string $version): void
    {
        $file = self::path($composer);
        if (!is_dir(dirname($file))) {
            return;
        }
        file_put_contents(
            $file,
            json_encode(
                [
                    'name' => $composer->getPackage()->getName(),
                    'pretty_version' => $prettyVersion,
                    'version' => $version
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . PHP_EOL
        );
    }

    /**
     * Gets the path of the version file.
     *
     * @param Composer $composer The Composer instance
     *
     * @return string The fully qualified filename
     */
    private static function path(Composer $composer): string
    {
        $vendorDir = $composer->getConfig()->get('vendor-dir');
        return (is_string($vendorDir) ? $vendorDir : 'vendor') . DIRECTORY_SEPARATOR . self::FILENAME;
    }
}

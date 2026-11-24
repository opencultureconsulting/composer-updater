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

use JsonException;
use RuntimeException;

/**
 * The upgrade-related configuration of a downloaded release.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class ReleaseConfig
{
    /**
     * The key in the "extra" section of composer.json holding the configuration.
     */
    public const EXTRA_KEY = 'composer-updater';

    /**
     * Creates a new release configuration.
     *
     * @param ReplaceRules $rules The rules for replacing files
     * @param list<string> $scripts The names of the scripts defined by the release
     */
    public function __construct(
        public readonly ReplaceRules $rules,
        public readonly array $scripts
    ) {
    }

    /**
     * Reads and validates the configuration from a release's composer.json.
     *
     * @param string $releaseDir The directory holding the release
     * @param string $expectedName The expected package name
     * @param list<string> $ignore Additional paths to ignore when replacing files
     *
     * @return self The configuration
     *
     * @throws RuntimeException if the composer.json is missing, invalid or belongs to another package
     */
    public static function fromDirectory(string $releaseDir, string $expectedName, array $ignore = []): self
    {
        $file = $releaseDir . '/composer.json';
        try {
            $json = is_file($file) ? json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $json = null;
        }
        if (!is_array($json)) {
            throw new RuntimeException('The downloaded release does not contain a valid composer.json.');
        }
        if (($json['name'] ?? null) !== $expectedName) {
            throw new RuntimeException(
                sprintf(
                    'The downloaded release is not a version of %s. Aborting to protect your installation.',
                    $expectedName
                )
            );
        }
        $extra = is_array($json['extra'] ?? null) ? ($json['extra'][self::EXTRA_KEY] ?? []) : [];
        if (!is_array($extra)) {
            throw new RuntimeException(
                sprintf('Invalid configuration: "extra.%s" must be an object.', self::EXTRA_KEY)
            );
        }
        $scripts = is_array($json['scripts'] ?? null) ? array_keys($json['scripts']) : [];
        return new self(
            new ReplaceRules($extra, $ignore),
            array_values(array_filter($scripts, 'is_string'))
        );
    }

    /**
     * Checks whether the release defines a script.
     *
     * @param string $name The script name
     *
     * @return bool Whether the script is defined
     */
    public function hasScript(string $name): bool
    {
        return in_array($name, $this->scripts, true);
    }
}

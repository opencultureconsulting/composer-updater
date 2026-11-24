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

use RuntimeException;

/**
 * Holds the rules defining how each path of a new release is handled.
 *
 * Rules are configured in "extra.composer-updater" of the new release's
 * composer.json as lists of paths relative to the project root:
 *  - "copy" (default): Files are overwritten, directories are merged, so files
 *    not part of the new release are kept.
 *  - "mirror": Like "copy", but files not part of the new release are deleted.
 *  - "preserve": Existing files are never touched, missing ones are added.
 *  - "ignore": The path is neither copied nor deleted.
 *
 * The most specific rule applies, e.g. "preserve" for "config/local.php"
 * overrides "mirror" for "config".
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 *
 * @phpstan-type Rule = 'copy'|'mirror'|'preserve'|'ignore'
 */
final class ReplaceRules
{
    /**
     * The available rules.
     */
    public const RULES = ['copy', 'mirror', 'preserve', 'ignore'];

    /**
     * Paths that are always ignored.
     */
    private const ALWAYS_IGNORE = ['.git', '.hg', '.svn'];

    /**
     * The configured rules indexed by relative path.
     *
     * @var array<string, Rule>
     */
    private array $rules = [];

    /**
     * Creates a new rule set.
     *
     * @param array<array-key, mixed> $config The "extra.composer-updater" configuration
     * @param list<string> $ignore Additional paths to ignore
     *
     * @throws RuntimeException if the configuration is invalid
     */
    public function __construct(array $config, array $ignore = [])
    {
        foreach (self::RULES as $rule) {
            foreach (self::parsePaths($config, $rule) as $path) {
                $this->rules[$path] = $rule;
            }
        }
        foreach ([...self::ALWAYS_IGNORE, ...$ignore] as $path) {
            $path = self::normalize($path);
            if ($path !== '') {
                $this->rules[$path] = 'ignore';
            }
        }
    }

    /**
     * Determines the rule for a path (the most specific configured rule wins).
     *
     * @param string $relPath The path relative to the project root
     *
     * @return Rule The applicable rule
     */
    public function for(string $relPath): string
    {
        $relPath = self::normalize($relPath);
        while ($relPath !== '') {
            if (isset($this->rules[$relPath])) {
                return $this->rules[$relPath];
            }
            $pos = strrpos($relPath, '/');
            $relPath = $pos === false ? '' : substr($relPath, 0, $pos);
        }
        return 'copy';
    }

    /**
     * Gets all paths configured with the given rule.
     *
     * @param Rule $rule The rule
     *
     * @return list<string> The paths
     */
    public function pathsWith(string $rule): array
    {
        return array_keys(array_filter($this->rules, static fn (string $value): bool => $value === $rule));
    }

    /**
     * Checks whether rules are configured for paths below the given path.
     *
     * @param string $relPath The path relative to the project root
     * @param list<Rule> $rules The rules to look for
     *
     * @return bool Whether any of the rules is configured for a path below
     */
    public function hasRulesBelow(string $relPath, array $rules): bool
    {
        $prefix = self::normalize($relPath) . '/';
        foreach ($this->rules as $path => $rule) {
            if (str_starts_with($path, $prefix) && in_array($rule, $rules, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Parses and validates the paths configured for a rule.
     *
     * @param array<array-key, mixed> $config The configuration
     * @param Rule $rule The rule
     *
     * @return list<string> The normalized paths
     *
     * @throws RuntimeException if the configuration is invalid
     */
    private static function parsePaths(array $config, string $rule): array
    {
        $paths = $config[$rule] ?? [];
        if (!is_array($paths)) {
            throw new RuntimeException(
                sprintf('Invalid configuration: "extra.composer-updater.%s" must be a list of paths.', $rule)
            );
        }
        $normalized = [];
        foreach ($paths as $path) {
            $path = is_string($path) ? self::normalize($path) : '';
            if ($path === '') {
                throw new RuntimeException(
                    sprintf('Invalid configuration: "extra.composer-updater.%s" contains an invalid path.', $rule)
                );
            }
            $normalized[] = $path;
        }
        return $normalized;
    }

    /**
     * Normalizes a relative path.
     *
     * @param string $path The path
     *
     * @return string The normalized path or '' if empty or invalid
     */
    private static function normalize(string $path): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $path)),
            static fn (string $segment): bool => $segment !== '' && $segment !== '.'
        );
        return in_array('..', $segments, true) ? '' : implode('/', $segments);
    }
}

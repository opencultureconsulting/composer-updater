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

use OCC\ComposerUpdater\ReplaceRules;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the replace rules.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
#[CoversClass(ReplaceRules::class)]
final class ReplaceRulesTest extends TestCase
{
    /**
     * Provides paths and their expected rules.
     *
     * @return array<string, array{string, string}>
     */
    public static function pathProvider(): array
    {
        return [
            'unconfigured path defaults to copy' => ['public/index.php', 'copy'],
            'configured directory' => ['src', 'mirror'],
            'file in configured directory' => ['src/Foo/Bar.php', 'mirror'],
            'more specific rule wins' => ['src/generated/file.php', 'preserve'],
            'even more specific rule wins' => ['src/generated/keep/file.php', 'mirror'],
            'similar prefix does not match' => ['srcfoo/file.php', 'copy'],
            'nested configured path' => ['config/local.php', 'preserve'],
            'sibling of nested path' => ['config/app.php', 'copy'],
            'explicit copy' => ['public/assets/app.css', 'copy'],
            'ignored path' => ['data/file.txt', 'ignore'],
            'always ignored VCS metadata' => ['.git/HEAD', 'ignore'],
            'additionally ignored path' => ['vendor/autoload.php', 'ignore'],
            'path is normalized' => ['./src//Foo.php', 'mirror']
        ];
    }

    /**
     * Tests determining the rule for a path.
     *
     * @param string $path The path
     * @param string $expected The expected rule
     *
     * @return void
     */
    #[DataProvider('pathProvider')]
    public function testRuleForPath(string $path, string $expected): void
    {
        $rules = new ReplaceRules(
            [
                'mirror' => ['src', 'src/generated/keep'],
                'preserve' => ['/config/local.php', 'src/generated/'],
                'copy' => ['public/assets'],
                'ignore' => ['data']
            ],
            ['vendor']
        );
        self::assertSame($expected, $rules->for($path));
    }

    /**
     * Tests listing the paths configured with a rule.
     *
     * @return void
     */
    public function testPathsWith(): void
    {
        $rules = new ReplaceRules(['mirror' => ['src', 'lib\\legacy']]);
        self::assertSame(['src', 'lib/legacy'], $rules->pathsWith('mirror'));
        self::assertSame([], $rules->pathsWith('preserve'));
    }

    /**
     * Provides invalid configurations.
     *
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function invalidConfigProvider(): array
    {
        return [
            'rule is not a list' => [['mirror' => 'src']],
            'path is not a string' => [['mirror' => [42]]],
            'path is empty' => [['preserve' => ['']]],
            'path is the root' => [['mirror' => ['.']]],
            'path leaves the project' => [['mirror' => ['../other']]]
        ];
    }

    /**
     * Tests rejecting invalid configurations.
     *
     * @param array<array-key, mixed> $config The invalid configuration
     *
     * @return void
     */
    #[DataProvider('invalidConfigProvider')]
    public function testInvalidConfig(array $config): void
    {
        $this->expectException(RuntimeException::class);
        new ReplaceRules($config);
    }
}

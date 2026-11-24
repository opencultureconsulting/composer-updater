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

use Composer\IO\IOInterface;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use RuntimeException;

/**
 * Runs Composer commands in separate processes.
 *
 * Running Composer in a fresh process makes sure the new version's files are
 * loaded instead of classes already loaded from the old version.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 */
final class ComposerProcess
{
    /**
     * Creates a new Composer process runner.
     *
     * @param IOInterface $io The input/output handler
     * @param string $workingDir The project directory
     */
    public function __construct(
        private readonly IOInterface $io,
        private readonly string $workingDir
    ) {
    }

    /**
     * Runs a Composer command.
     *
     * @param list<string> $args The command and its arguments
     * @param array<string, string> $env Environment variables to set
     *
     * @return int The exit code
     *
     * @throws RuntimeException if the Composer binary cannot be determined
     */
    public function run(array $args, array $env = []): int
    {
        $binary = Platform::getEnv('COMPOSER_BINARY');
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Could not determine the Composer binary.');
        }
        foreach ($env as $name => $value) {
            Platform::putEnv($name, $value);
        }
        $command = [PHP_BINARY, $binary, ...$args, '--no-interaction', '--working-dir=' . $this->workingDir];
        if ($this->io->isDecorated()) {
            $command[] = '--ansi';
        }
        $verbosity = $this->verbosityFlag();
        if ($verbosity !== null) {
            $command[] = $verbosity;
        }
        ProcessExecutor::setTimeout(0);
        return (new ProcessExecutor($this->io))->execute(
            implode(' ', array_map([ProcessExecutor::class, 'escape'], $command))
        );
    }

    /**
     * Gets the verbosity flag matching the current verbosity.
     *
     * @return ?string The flag or NULL for normal verbosity
     */
    private function verbosityFlag(): ?string
    {
        return match (true) {
            $this->io->isDebug() => '-vvv',
            $this->io->isVeryVerbose() => '-vv',
            $this->io->isVerbose() => '-v',
            default => null
        };
    }
}

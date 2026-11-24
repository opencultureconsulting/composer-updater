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
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider as CommandProviderCapability;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Composer\Util\Platform;
use Override;

/**
 * Composer plugin providing the "app:upgrade" command.
 *
 * Besides registering the command, the plugin records the application's
 * version whenever Composer knows it for sure (i.e. right after
 * "composer create-project" or after an upgrade), because Composer cannot
 * detect the root package's version in deployments without VCS metadata.
 *
 * @author Sebastian Meyer <sebastian.meyer@opencultureconsulting.com>
 * @package ComposerUpdater
 *
 * @psalm-api Instantiated by Composer as declared in "extra.class".
 */
final class ComposerUpdater implements PluginInterface, Capable, EventSubscriberInterface
{
    /**
     * Applies plugin modifications to Composer.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The input/output handler
     *
     * @return void
     */
    #[Override]
    public function activate(Composer $composer, IOInterface $io): void
    {
        // Nothing to do here.
    }

    /**
     * Removes any hooks from Composer.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The input/output handler
     *
     * @return void
     */
    #[Override]
    public function deactivate(Composer $composer, IOInterface $io): void
    {
        // Nothing to do here.
    }

    /**
     * Prepares the plugin to be uninstalled.
     *
     * @param Composer $composer The Composer instance
     * @param IOInterface $io The input/output handler
     *
     * @return void
     */
    #[Override]
    public function uninstall(Composer $composer, IOInterface $io): void
    {
        VersionStore::remove($composer);
    }

    /**
     * Returns the plugin's capabilities.
     *
     * @return array<class-string, class-string>
     */
    #[Override]
    public function getCapabilities(): array
    {
        return [
            CommandProviderCapability::class => CommandProvider::class
        ];
    }

    /**
     * Returns the events this plugin subscribes to.
     *
     * @return array<string, string>
     */
    #[Override]
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'recordVersion',
            ScriptEvents::POST_UPDATE_CMD => 'recordVersion'
        ];
    }

    /**
     * Records the root package's version if it was set explicitly.
     *
     * "composer create-project" and the "app:upgrade" command both set the
     * environment variable COMPOSER_ROOT_VERSION when installing dependencies,
     * so this is the moment the application's version is known for sure.
     *
     * @param Event $event The script event
     *
     * @return void
     */
    public function recordVersion(Event $event): void
    {
        $rootVersion = Platform::getEnv('COMPOSER_ROOT_VERSION');
        if ($rootVersion === false || $rootVersion === '') {
            return;
        }
        $composer = $event->getComposer();
        $package = $composer->getPackage();
        VersionStore::write($composer, $package->getPrettyVersion(), $package->getVersion());
    }
}

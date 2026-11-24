.. title:: Examples

Examples
########

.. sidebar:: Table of Contents
  .. contents::

These examples show complete configurations for typical applications and scenarios. They are meant as a starting point:
adapt the paths and commands to your application.

Minimal Setup
=============

For a simple application without local configuration or user data, no configuration is needed at all. All files are
copied, and nothing is ever deleted:

.. code-block:: json

  {
      "name": "acme/tool",
      "type": "project",
      "require": {
          "php": "^8.2",
          "opencultureconsulting/updater": "^1.0"
      },
      "config": {
          "allow-plugins": {
              "opencultureconsulting/updater": true
          }
      }
  }

However, files you remove from your application will remain in the installations. Once your application grows, mirror
your code directories as shown in the next example.

Typical Web Application
=======================

A typical web application has code, templates and assets which should match the release exactly, a local configuration
edited by the administrator, and runtime data like caches, logs and uploads:

.. code-block:: text

  bin/              console script                        → mirror
  config/           default configuration                 → mirror
  config/local.php  local configuration (edited by admin) → preserve
  public/           document root                         → copy (admins may add files)
  public/build/     compiled assets with hashed names     → mirror
  public/uploads/   user uploads                          → ignore
  src/              application code                      → mirror
  templates/        templates                             → mirror
  var/              cache, logs, sessions                 → ignore

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["bin", "config", "public/build", "src", "templates"],
              "preserve": ["config/local.php"],
              "ignore": ["public/uploads", "var"]
          }
      },
      "scripts": {
          "pre-upgrade-cmd": "@php bin/console app:maintenance on",
          "post-upgrade-cmd": [
              "Composer\\Config::disableProcessTimeout",
              "@php bin/console app:migrate --no-interaction",
              "@php bin/console cache:clear",
              "@php bin/console app:maintenance off"
          ]
      }
  }

The `public` directory needs no configuration, as `copy` is the default.

Symfony Application
===================

A `Symfony <https://symfony.com/>`_ application keeps its local configuration in `.env.local`, runtime data in `var/`,
and runs migrations with `Doctrine Migrations <https://www.doctrine-project.org/projects/migrations.html>`_:

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["bin", "config", "migrations", "src", "templates", "translations"],
              "preserve": [".env.local"],
              "ignore": ["var"]
          }
      },
      "scripts": {
          "post-upgrade-cmd": [
              "Composer\\Config::disableProcessTimeout",
              "@php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration",
              "@php bin/console cache:clear --no-warmup",
              "@php bin/console cache:warmup"
          ]
      }
  }

.. note::

  `.env` itself contains the defaults and should be overwritten (default rule `copy`), while `.env.local` holds the
  administrator's settings. Don't ship a `.env.local` with your release: it's created by the administrator.

If your application uses `Symfony Messenger <https://symfony.com/doc/current/messenger.html>`_ workers, add
`"@php bin/console messenger:stop-workers"` to the `post-upgrade-cmd` script, so the workers are restarted with the new
code by your process manager.

Laravel Application
===================

A `Laravel <https://laravel.com/>`_ application keeps its local configuration in `.env`, runtime data and uploads in
`storage/`, and has a maintenance mode built in:

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["app", "bootstrap/app.php", "config", "database", "resources", "routes"],
              "preserve": [".env"],
              "ignore": ["bootstrap/cache", "storage"]
          }
      },
      "scripts": {
          "pre-upgrade-cmd": "@php artisan down --retry=60",
          "post-upgrade-cmd": [
              "Composer\\Config::disableProcessTimeout",
              "@php artisan migrate --force",
              "@php artisan optimize:clear",
              "@php artisan queue:restart",
              "@php artisan up"
          ]
      }
  }

.. caution::

  If the upgrade fails *after* `pre-upgrade-cmd` ran, the rollback restores the files, but the application stays in
  maintenance mode. Tell your users to run `php artisan up` in this case, or check the maintenance mode in your
  application's health checks.

.. _example-config-template:

Configuration Templates
=======================

`preserve` protects the administrator's configuration, but also means that changes to the default configuration never
reach existing installations. A common pattern is to ship a template that is always updated, and let the administrator
override only what they need in a separate, preserved file:

.. code-block:: text

  config/config.dist.php   defaults, always updated  → mirror (via "config")
  config/config.php        local overrides           → preserve

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["config"],
              "preserve": ["config/config.php"]
          }
      }
  }

.. code-block:: php

  <?php
  // config/config.php (shipped as a mostly empty file, edited by the administrator)
  return array_replace_recursive(
      require __DIR__ . '/config.dist.php',
      [
          'database' => ['host' => 'db.example.com'],
      ]
  );

New settings added to `config.dist.php` reach every installation, while the administrator's overrides are kept.

.. _example-restructuring:

Restructuring the Application
=============================

Suppose version 2.0 moves the code from `lib/` to `src/`. Without special care, every upgraded installation would keep
an orphaned `lib/` directory. Since mirrored paths which don't exist in the new version are deleted, mirroring the old
path in the new release removes it:

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["src", "lib"]
          }
      }
  }

You can remove `lib` from the configuration in a later release. Keep in mind that users may skip releases, so keep it
at least until the next major version.

The same works for single files: to remove `public/legacy.php` from all installations, add it to `mirror` in the release
which doesn't contain it anymore.

.. _example-version-specific:

Version-Specific Tasks
======================

Sometimes a task is only needed when upgrading from certain versions, e.g. converting data stored by older versions. Use
the environment variable `COMPOSER_UPDATER_PREVIOUS_VERSION` in a PHP callback:

.. code-block:: json

  {
      "scripts": {
          "post-upgrade-cmd": [
              "Acme\\Blog\\Upgrade::postUpgrade",
              "@php bin/console app:migrate --no-interaction"
          ]
      }
  }

.. code-block:: php

  <?php

  namespace Acme\Blog;

  use Composer\Script\Event;

  final class Upgrade
  {
      public static function postUpgrade(Event $event): void
      {
          $from = getenv('COMPOSER_UPDATER_PREVIOUS_VERSION');
          if ($from === false || $from === '' || str_starts_with($from, 'dev-')) {
              // Unknown or development version: skip version-specific tasks.
              $event->getIO()->write('Skipping version-specific upgrade tasks.');
              return;
          }

          if (version_compare($from, '2.0.0', '<')) {
              $event->getIO()->write('Converting uploads to the 2.x directory layout...');
              UploadConverter::convert();
          }
      }
  }

To prevent upgrades across too many versions at once, fail with a helpful message instead:

.. code-block:: php

  if (version_compare($from, '1.5.0', '<')) {
      throw new \RuntimeException('Please upgrade to version 1.5 first: composer app:upgrade 1.5.0');
  }

.. caution::

  Throwing in `post-upgrade-cmd` happens *after* the files were replaced, so the new version is installed but not
  migrated. To check before anything is changed, put the check in the `pre-upgrade-cmd` script, which can read the
  target version from `COMPOSER_UPDATER_VERSION`. Remember that this script is taken from the *installed* version, so the
  check only works for installations which already have it.

Database Backup Before Upgrading
================================

The plugin backs up files, but not databases. Create a database dump in the `pre-upgrade-cmd` script, so your users can
restore it if a migration goes wrong:

.. code-block:: json

  {
      "scripts": {
          "pre-upgrade-cmd": "@php bin/console app:database:dump var/backup/before-upgrade.sql"
      },
      "extra": {
          "composer-updater": {
              "ignore": ["var"]
          }
      }
  }

Make sure the dump is written to an ignored path, so it survives the upgrade.

Private Distribution
====================

An application sold to customers and distributed via `Private Packagist <https://packagist.com/>`_ adds the
repository to its `composer.json` and disables Packagist, so no public package with the same name can interfere:

.. code-block:: json

  {
      "name": "acme/shop",
      "type": "project",
      "repositories": [
          {
              "type": "composer",
              "url": "https://acme.repo.packagist.com/customer-name/"
          },
          {
              "packagist.org": false
          }
      ]
  }

Customers store their credentials once, e.g. in the `auth.json` of their installation:

.. code-block:: shell

  composer config http-basic.acme.repo.packagist.com token <customer-token>

.. caution::

  Disabling Packagist means all dependencies must be available from your private repository. Private Packagist mirrors
  them automatically. For other repositories, keep Packagist enabled.

.. title:: Upgrade Scripts

Upgrade Scripts
###############

.. sidebar:: Table of Contents
  .. contents::

Most applications need to do more than replacing files when being upgraded: databases need to be migrated, caches
cleared, and maybe a maintenance mode enabled while all this happens. You can hook such tasks into the upgrade with two
`Composer scripts <https://getcomposer.org/doc/articles/scripts.md>`_.

The Scripts
===========

`pre-upgrade-cmd`
-----------------

This script is taken from the *currently installed* version and runs after the new version was downloaded and the
backup was created, but before any file is replaced.

Typical tasks are enabling a maintenance mode, creating a database backup, or stopping background workers.

If the script fails (i.e. exits with a non-zero exit code), the upgrade is aborted and the installation remains
unchanged. Anything the script did before failing is *not* undone, though.

.. important::

  Because the script is taken from the installed version, a `pre-upgrade-cmd` script added in a new release is only
  used when upgrading *from* that release, not when upgrading *to* it.

`post-upgrade-cmd`
------------------

This script is taken from the *new* version and runs after the new version's files and dependencies are installed.

Typical tasks are running database migrations, clearing or warming up caches, disabling the maintenance mode, and
restarting background workers.

If the script fails, the upgrade is reported as failed, but **not** rolled back, because its tasks may have been run
partially and the previous version might not work with the result. The user is asked to fix the problem and to run the
script manually:

.. code-block:: shell

  composer run-script post-upgrade-cmd

Environment Variables
=====================

Both scripts can read these environment variables:

`COMPOSER_UPDATER_PREVIOUS_VERSION`
  The version upgraded from, e.g. `1.2.0`. Empty if the installed version is unknown.

`COMPOSER_UPDATER_VERSION`
  The version upgraded to, e.g. `1.3.0` or `dev-main`.

`COMPOSER_ROOT_VERSION`
  The version of the application the script belongs to, i.e. the installed version for `pre-upgrade-cmd` (if known)
  and the new version for `post-upgrade-cmd`.

.. caution::

  When a user runs a script manually, e.g. after fixing a failed migration, these variables are not set. Make sure your
  scripts work without them.

Writing Scripts
===============

The scripts are regular Composer scripts, so you can use everything Composer supports: shell commands, PHP commands with
`@php`, other Composer scripts with `@`-references, and static PHP methods. A script can be a single command or a list of
commands, which are run in order until one fails.

.. code-block:: json

  {
      "scripts": {
          "pre-upgrade-cmd": "@php bin/console app:maintenance on",
          "post-upgrade-cmd": [
              "@php bin/console doctrine:migrations:migrate --no-interaction",
              "@php bin/console cache:clear",
              "@php bin/console app:maintenance off"
          ]
      }
  }

Keep these rules in mind:

* **Never ask questions.** The scripts run with `--no-interaction`, so commands waiting for input will fail or use
  their defaults. Pass the "non-interactive" options of the tools you call, e.g. `--no-interaction` or `--force`.
* **Exit with a non-zero code on failure.** Only then can the plugin abort the upgrade or report the failure.
* **Make the scripts idempotent.** Users will re-run a failed `post-upgrade-cmd` script manually, so running it twice
  must not do any harm. Migration tools usually take care of this.
* **Mind the timeout.** Composer kills scripts running longer than its `process-timeout` (300 seconds by default). If
  your migrations may take longer, disable the timeout as shown below.

Disabling the Timeout
---------------------

Add `Composer\\Config::disableProcessTimeout` as the first command of a script to disable the timeout for all its
commands:

.. code-block:: json

  {
      "scripts": {
          "post-upgrade-cmd": [
              "Composer\\Config::disableProcessTimeout",
              "@php bin/console app:migrate"
          ]
      }
  }

Using PHP Callbacks
-------------------

For more complex tasks, you can call a static method of a class from your application's autoloader. It receives a
`Composer\\Script\\Event`, which gives access to Composer's input/output handler:

.. code-block:: json

  {
      "scripts": {
          "post-upgrade-cmd": "Acme\\Blog\\Upgrade::postUpgrade"
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
          $io = $event->getIO();
          $from = getenv('COMPOSER_UPDATER_PREVIOUS_VERSION') ?: null;
          $to = getenv('COMPOSER_UPDATER_VERSION') ?: 'unknown';

          $io->write(sprintf('Upgrading from %s to %s...', $from ?? 'an unknown version', $to));

          // Throwing an exception makes the script fail.
          (new Migrator())->migrate();
      }
  }

The :ref:`version-specific tasks example <example-version-specific>` shows how to run tasks only when upgrading from
certain versions.

.. note::

  The `post-install-cmd` and `post-update-cmd` scripts of your application also run during the upgrade, because the
  plugin installs the new version's dependencies with `composer install`. A failure of these scripts fails the
  installation of the dependencies and triggers a rollback.

Testing Scripts
===============

You can run the scripts manually at any time to test them:

.. code-block:: shell

  COMPOSER_UPDATER_PREVIOUS_VERSION=1.2.0 COMPOSER_UPDATER_VERSION=1.3.0 composer run-script post-upgrade-cmd

To test a complete upgrade before publishing a release, see :ref:`testing upgrades <developers-testing>`.

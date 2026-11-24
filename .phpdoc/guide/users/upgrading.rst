.. title:: Upgrading

Upgrading
#########

.. sidebar:: Table of Contents
  .. contents::

Before You Start
================

.. caution::

  Run all commands as the user owning the application's files, usually your web server's or PHP-FPM's system user.
  Running them as `root` leaves files your web server can't read or write.

  .. code-block:: shell

    sudo -u www-data composer app:upgrade

  Commands intended to be run as `root` are always prepended with `sudo` throughout this documentation.

Make sure that

* you are in the application's directory (the one containing its `composer.json`), or pass it with
  `--working-dir` (`-d`),
* you have read the release notes of the version you want to install,
* you have a backup of the application's database and data,
* there is enough free disk space for a copy of the application's `vendor` directory, which is used as backup during
  the upgrade, and
* there is no `.composer-updater-backup` directory left over from a failed upgrade (see
  :doc:`troubleshooting <troubleshooting>`).

Checking for New Versions
=========================

The `--list` option shows the installed version and all versions available for upgrading:

.. code-block:: shell

  composer app:upgrade --list

.. code-block:: text

  Current version of acme/blog: 1.2.0
  Available versions:
   - 2.0.0 (platform requirements not met)
   - 1.3.0
   - 1.3.0-RC1 (RC)
   - 1.2.0 (current)
   - 1.1.0
   - 1.0.0

Versions marked with *platform requirements not met* need a newer PHP version or PHP extensions which aren't installed
on your server. Pre-releases are marked with their stability (`alpha`, `beta` or `RC`).

Development versions (branches) are only listed with `--dev`:

.. code-block:: shell

  composer app:upgrade --list --dev

Upgrading
=========

Without any arguments, the command upgrades to the latest stable release that your server can run:

.. code-block:: shell

  composer app:upgrade

.. code-block:: text

  Current version of acme/blog: 1.2.0
  Skipped newer version(s) 2.0.0 because of unmet platform requirements.
  Target version: 1.3.0

  You are about to replace the application's files and run its upgrade scripts.
  This may include database migrations which can lead to data loss.
  Carefully read the release notes and always keep a BACKUP!

  Continue? [Y,n]

After you confirm, the plugin downloads the new version, backs up the current one, and installs the new version:

.. code-block:: text

  Downloading acme/blog 1.3.0...
    - Installing acme/blog (1.3.0): Extracting archive

  Backing up current version...

  Running pre-upgrade tasks...
  > @php bin/console app:maintenance on

  Replacing files...

  Installing dependencies...
  Installing dependencies from lock file
  Package operations: 2 installs, 5 updates, 0 removals
    ...
  Generating optimized autoload files

  Running post-upgrade tasks...
  > @php bin/console app:migrate --no-interaction
  > @php bin/console app:maintenance off

  acme/blog 1.3.0 was installed successfully.

Which tasks run before and after replacing the files depends on your application. If anything fails while replacing
the files or installing the dependencies, the previous version is restored automatically (see
:doc:`troubleshooting <troubleshooting>`).

.. tip::

  If your server uses PHP's OPcache with `opcache.validate_timestamps=0`, restart PHP-FPM or your web server after the
  upgrade, so it doesn't keep running the old code.

  .. code-block:: shell

    sudo systemctl reload php8.3-fpm

Installing a Specific Version
=============================

Pass the version you want to install as argument. Versions can be given with or without a leading `v`:

.. code-block:: shell

  composer app:upgrade 1.3.0

Pre-releases can be installed the same way:

.. code-block:: shell

  composer app:upgrade 2.0.0-RC1

Development versions additionally need the `--dev` option:

.. code-block:: shell

  composer app:upgrade dev-main --dev

.. warning::

  Pre-releases and development versions are not meant for production. Use them on test or staging systems only.

By default, the command refuses to install a version that is the same as or older than the installed one. Use
`--force` to reinstall the current version or to downgrade (see the :ref:`downgrading example <example-downgrade>`).
This check is skipped if the installed or the requested version is a development version, or if the installed version
is unknown.

Command Reference
=================

.. code-block:: text

  composer app:upgrade [options] [--] [<version>]

Arguments
---------

`version`
  The version to install, e.g. `1.3.0`, `v1.3.0`, `2.0.0-RC1` or `dev-main`. Defaults to `latest`, which selects the
  newest stable release meeting your server's platform requirements.

Options
-------

`--list`, `-l`
  Lists the installed version and all available versions instead of upgrading.

`--dev`
  Includes development versions (branches) when listing or installing versions.

`--force`, `-f`
  Installs the requested version even if it is the same as or older than the installed version.

`--ignore-platform-reqs`
  Ignores the PHP version and PHP extensions required by the requested version and its dependencies. Only use this if
  you know what you're doing: the new version may not work on your server.

`--no-backup`
  Skips the backup of the current version. This saves time and disk space for very large installations, but also
  disables the automatic rollback if the upgrade fails.

`--no-interaction`, `-n`
  Skips the confirmation prompt, e.g. for automated upgrades.

`--working-dir`, `-d`
  Runs the command for the application in the given directory instead of the current one.

`--verbose`, `-v`, `-vv`, `-vvv`
  Shows more details, including the commands run by the plugin and the full details of errors.

Exit Codes
----------

The command exits with `0` if the upgrade (or listing) was successful, and with a non-zero exit code if it was
aborted or failed. Automated scripts can rely on this (see the
:ref:`automated upgrades example <example-automation>`).

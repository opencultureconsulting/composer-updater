.. title:: Overview

Overview
########

.. sidebar:: Table of Contents
  .. contents::

This page explains what happens during an upgrade. Developers need to know this to prepare their releases, and users
will find it helpful to understand what the command does to their installation.

The Upgrade Process
===================

When a user runs `composer app:upgrade`, the plugin performs these steps:

#. **Find versions.** The available versions of the application are fetched from the repositories configured in its
   `composer.json`. That's `Packagist <https://packagist.org/>`_ by default, but private repositories work as well,
   including any authentication configured for Composer.
#. **Select a version.** Unless a version is given explicitly, the newest *stable* release whose platform requirements
   (PHP version and extensions) are met by the server is selected. Pre-releases (alpha, beta, RC) and development
   branches are never selected automatically.
#. **Download.** The selected version is downloaded and extracted to a temporary directory. The plugin verifies that
   it really is a version of the installed application (the package names must match) before touching anything.
#. **Back up.** The vendor directory (and the bin directory, if it is configured outside of it) is copied to
   `.composer-updater-backup/` in the project directory.
#. **Run pre-upgrade tasks.** The `pre-upgrade-cmd` script of the *currently installed* version is run, if defined.
#. **Replace files.** The application's files are replaced with those of the new version according to the
   :ref:`replace rules <overview-replace-rules>`. Every file that is overwritten or deleted is moved to the backup
   first.
#. **Install dependencies.** `composer install` is run for the new version.
#. **Clean up.** The backup is removed.
#. **Run post-upgrade tasks.** The `post-upgrade-cmd` script of the *new* version is run, if defined.

If any of the steps 5 to 7 fails, the previous version is :ref:`restored <overview-rollback>`.

All Composer commands of steps 5, 7 and 9 run in separate processes. This makes sure that the code of the new version
is used after the files were replaced, instead of code that was already loaded from the old version.

.. _overview-replace-rules:

Replace Rules
=============

Applications are rarely a set of files that can simply be overwritten: there are configuration files edited by the
administrator, directories with user uploads, caches, and files that were removed from the application in the new
version. The *replace rules* tell the plugin how to handle each path.

The rules are read from the `extra.composer-updater` section of the *new* version's `composer.json`, so each release
defines how it is installed. Every path is handled by the most specific rule configured for it:

`copy` (default)
  Files are overwritten, directories are merged. Files that exist in the installation but not in the new version are
  kept.

`mirror`
  Like `copy`, but files that exist in the installation but not in the new version are deleted. If a mirrored path
  doesn't exist in the new version at all, it is deleted completely.

`preserve`
  Existing files are never touched. Files that don't exist yet are added.

`ignore`
  The path is neither copied nor deleted.

These paths are always ignored, no matter what is configured:

* the vendor directory (it is managed by `composer install`),
* VCS metadata (`.git`, `.hg`, `.svn`), and
* the backup directory `.composer-updater-backup`.

Preserved and ignored paths are never deleted by a mirror rule of a parent directory, and more specific rules inside
preserved or ignored directories are still applied.

If the new version doesn't contain a `composer.lock`, the existing one is removed, so `composer install` resolves the
new version's dependencies from scratch instead of installing the old ones.

The :doc:`file replacement rules <developers/configuration>` page in the Developer Guide explains the configuration in
detail.

.. _overview-scripts:

Upgrade Scripts
===============

Applications can hook into the upgrade with two Composer scripts:

`pre-upgrade-cmd`
  Taken from the *currently installed* version and run before any file is replaced, e.g. to enable a maintenance mode
  or to back up the database. If it fails, the upgrade is aborted and the installation remains unchanged.

`post-upgrade-cmd`
  Taken from the *new* version and run after the new version and its dependencies are installed, e.g. to run database
  migrations, clear caches, and disable the maintenance mode.

Both scripts can read the versions upgraded from and to from environment variables. See the
:doc:`upgrade scripts <developers/scripts>` page in the Developer Guide for details.

.. _overview-rollback:

Rollback
========

Before a file is overwritten or deleted, it is moved to the backup directory. Files added by the new version are
recorded, too. If running the `pre-upgrade-cmd` script, replacing the files or installing the dependencies fails, all
changes are undone: added files are deleted, and the original files and the vendor directory are moved back into place.
A lock file created by `composer install` is removed. Afterwards, the installation is exactly the way it was before.

.. important::

  A rollback restores *files* only. Anything else the `pre-upgrade-cmd` script did, e.g. enabling a maintenance mode
  or changing the database, is not undone.

Packages installed outside of the vendor directory (e.g. with `installer-paths` of
`composer/installers <https://github.com/composer/installers>`_) and files written by the application's
`post-install-cmd` script are not part of the backup, and therefore not restored.

A failing `post-upgrade-cmd` script does **not** trigger a rollback. Its tasks (e.g. database migrations) may have been
run partially, and the previous version might not work with a partially migrated database. Instead, the user is asked
to fix the problem and to run the script manually.

If the rollback itself fails, the backup directory is kept, and further upgrades are refused (even with `--no-backup`)
until it is checked and removed manually. See :doc:`troubleshooting <users/troubleshooting>` for how to recover in this case.

Users can skip the backup with `--no-backup`, e.g. for very large installations. This also disables the rollback.

.. _overview-version-detection:

Version Detection
=================

To decide whether a version is newer than the installed one, the plugin needs to know the installed version. Composer
itself can only detect the version of the root package from VCS metadata, which is usually missing after
`composer create-project`.

Therefore, the plugin records the installed version in `vendor/composer-updater.json` whenever it is known for sure:

* right after `composer create-project` (which tells Composer the version it installed), and
* after each upgrade.

If that file is missing, the version detected by Composer is used. If Composer can't detect the version either, it is
displayed as *unknown*, and every version can be installed without the "is not newer" check.

The recorded version can be corrected by running `composer install` with the environment variable
`COMPOSER_ROOT_VERSION` set to the correct version:

.. code-block:: shell

  COMPOSER_ROOT_VERSION=1.4.2 composer install

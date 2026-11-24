.. title:: Troubleshooting

Troubleshooting
###############

.. sidebar:: Table of Contents
  .. contents::

This page explains the most common error messages and how to solve them. Add `-vvv` to the command for more details
about what went wrong.

Failed Upgrades
===============

"The previous version was restored."
------------------------------------

.. code-block:: text

  Upgrading failed. Restoring previous version...

  Upgrading failed: Installing the dependencies of the new version failed.
  The previous version was restored.

Something went wrong while running the pre-upgrade tasks, replacing the files or installing the dependencies. All
files and the `vendor` directory were restored, so the previous version keeps working.

Scroll up to find the original error, e.g. a dependency that couldn't be downloaded, a failing script, or missing
permissions. Fix the problem, and run the upgrade again.

.. important::

  If the application's pre-upgrade tasks enabled a maintenance mode or changed anything else, this was *not* undone.
  Check the application's documentation for how to disable the maintenance mode.

"Running the pre-upgrade tasks failed."
---------------------------------------

The application's `pre-upgrade-cmd` script failed, so the upgrade was aborted before any file was replaced. Check the
script's output above the error message. You can run the script manually to debug it:

.. code-block:: shell

  composer run-script pre-upgrade-cmd

"The new version is installed, but running its post-upgrade tasks failed."
--------------------------------------------------------------------------

The new version and its dependencies were installed successfully, but its `post-upgrade-cmd` script (e.g. database
migrations) failed. This is **not** rolled back, because the tasks may have been run partially, and the previous version
might not work with the result.

Check the script's output above the error message, fix the problem (e.g. database credentials or permissions), and run
the tasks again:

.. code-block:: shell

  composer run-script post-upgrade-cmd

If you can't fix the problem, restore your database backup and :ref:`downgrade <example-downgrade>` to the previous
version.

"Restoring the previous version failed, too."
---------------------------------------------

.. code-block:: text

  Upgrading failed: Installing the dependencies of the new version failed.
  Restoring the previous version failed, too: Could not restore /var/www/blog/src. The backup is kept in
  "/var/www/blog/.composer-updater-backup".

This only happens if the files can't be moved back, e.g. because of missing permissions or a full disk. The installation
is now incomplete. To repair it:

#. Fix the cause of the problem, e.g. free some disk space or correct the permissions.
#. Reinstall the previous version, which restores all files of the application:

   .. code-block:: shell

     sudo -u www-data mv .composer-updater-backup ~/composer-updater-backup
     sudo -u www-data composer app:upgrade 1.2.0 --force

   If Composer reports that the command "app:upgrade" is not defined, the `vendor` directory is broken, too. Run
   `sudo -u www-data composer install` first, then try again.

#. Compare your local files (e.g. configuration and uploads) with those in the moved backup directory, and copy back
   anything that's missing. The backup contains the original files and directories, named with numbers in the order
   they were changed.
#. Delete the moved backup directory once everything works.

"The backup directory ... already exists."
------------------------------------------

.. code-block:: text

  The backup directory "/var/www/blog/.composer-updater-backup" already exists. It may contain files left from a failed
  upgrade. Check and remove it before trying again.

A previous upgrade was interrupted (e.g. the server crashed or the process was killed) or its rollback failed. The
plugin refuses to upgrade, so the leftover backup isn't overwritten. Make sure your installation works, follow the steps
above to restore missing files if necessary, and remove the directory:

.. code-block:: shell

  rm -r /var/www/blog/.composer-updater-backup

"Could not remove the backup directory."
----------------------------------------

The upgrade was successful, but the backup directory couldn't be deleted afterwards, e.g. because of missing
permissions. The new version is installed and its post-upgrade tasks ran. Remove the directory manually, since it would
block the next upgrade:

.. code-block:: shell

  rm -r /var/www/blog/.composer-updater-backup

"Cannot replace directory ... with a file, because it contains preserved or ignored paths."
-------------------------------------------------------------------------------------------

The new version contains a file where your installation has a directory with protected data inside, e.g. user uploads.
The upgrade was aborted and rolled back, so nothing was lost. Move the protected data elsewhere (check the release notes
of the new version for where it belongs now), and run the upgrade again. If you're unsure, contact the application's
developers: this is most likely a problem with the release.

Selecting a Version
===================

"The current version ... is the same as ..." / "... is newer than ..."
----------------------------------------------------------------------

The requested version isn't newer than the installed one, i.e. the application is already up to date. Use `--force` to
reinstall or downgrade anyway.

"Version ... not found."
------------------------

The requested version doesn't exist. Run `composer app:upgrade --list` to see all available versions. Development
versions (e.g. `dev-main`) require the `--dev` option.

If a version you expect is missing, Composer may be using outdated metadata. Clear Composer's cache and try again:

.. code-block:: shell

  composer clear-cache

"Version ... requires php ..., which is not available."
-------------------------------------------------------

The requested version needs a newer PHP version or a PHP extension which isn't installed on your server. Upgrade PHP or
install the missing extension. The message tells you exactly what's missing, e.g. `php >=8.4` or `ext-intl *`.

Note that the PHP version used by Composer on the command line may differ from the one used by your web server. Check it
with `php -v`.

"No stable release available." / "No stable release meets the platform requirements."
-------------------------------------------------------------------------------------

There is no stable release that could be selected automatically. Either the application hasn't published a stable
release yet (install a pre-release or development version explicitly), or all releases need a newer PHP version or
additional PHP extensions.

The Installed Version is "unknown"
----------------------------------

.. code-block:: text

  Current version of acme/blog: unknown

The plugin couldn't determine the installed version, e.g. because the application was installed by copying files
instead of with `composer create-project`, or `vendor/composer-updater.json` was deleted. You can still upgrade, but the
"is not newer" check is skipped.

To fix the displayed version, tell Composer the correct version once:

.. code-block:: shell

  COMPOSER_ROOT_VERSION=1.2.0 composer install

The Installed Version is Wrong
------------------------------

The plugin records the installed version in `vendor/composer-updater.json`. If the files were changed by other means,
e.g. by manually copying a new version, the recorded version is outdated. Correct it the same way:

.. code-block:: shell

  COMPOSER_ROOT_VERSION=1.3.0 composer install

Other Problems
==============

Command "app:upgrade" is not defined.
-------------------------------------

Composer doesn't know the command. Check that

* you are in the application's directory (or use `--working-dir`),
* the application's version includes the plugin (`composer show opencultureconsulting/updater`), and
* the plugin is allowed in the application's `composer.json`:

  .. code-block:: shell

    composer config allow-plugins.opencultureconsulting/updater

  If this doesn't print `true`, the plugin was disabled. Allow it and reinstall the dependencies:

  .. code-block:: shell

    composer config allow-plugins.opencultureconsulting/updater true
    composer install

If the installed version doesn't include the plugin yet, you have to upgrade it manually once, following the
application's upgrade instructions.

"Permission denied" / "Directory ... is not writable."
-------------------------------------------------------

You are running the command as a user who can't write to the application's directory (or to some of its files). Run it as the owner of the files,
usually the web server's user:

.. code-block:: shell

  sudo -u www-data composer app:upgrade

The Web Server Still Runs the Old Version
-----------------------------------------

PHP's OPcache keeps compiled code in memory. If it's configured not to check for changed files
(`opcache.validate_timestamps=0`), restart PHP-FPM or your web server after upgrading:

.. code-block:: shell

  sudo systemctl reload php8.3-fpm

The Upgrade Takes Very Long
---------------------------

The plugin copies the `vendor` directory before upgrading, which may take a while for very large installations or on
slow storage. If you have another backup of the application directory (e.g. a filesystem snapshot), you can skip the
copy with `--no-backup`. This also disables the automatic rollback, though.

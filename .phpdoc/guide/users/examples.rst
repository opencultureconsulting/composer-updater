.. title:: Examples

Examples
########

.. sidebar:: Table of Contents
  .. contents::

These examples show how to handle typical upgrade scenarios. Replace `acme/blog` and the version numbers with those of
your application.

Regular Upgrade on a Production Server
======================================

The safest way to upgrade a production server is to check what's available first, read the release notes, back up the
data, and then upgrade as the web server's user:

.. code-block:: shell

  cd /var/www/blog

  # What's installed and what's available?
  sudo -u www-data composer app:upgrade --list

  # Back up the database (adapt to your database system)
  mysqldump --single-transaction blog > ~/blog-before-upgrade.sql

  # Upgrade to the latest stable release
  sudo -u www-data composer app:upgrade

  # Make PHP-FPM pick up the new code (only needed with opcache.validate_timestamps=0)
  sudo systemctl reload php8.3-fpm

Upgrading Step by Step
======================

Some applications don't support skipping major versions, e.g. because migrations for very old versions were removed.
Check the release notes and install the intermediate versions one after another:

.. code-block:: shell

  composer app:upgrade 1.9.0
  composer app:upgrade 2.4.0
  composer app:upgrade

Testing a Release Candidate on a Staging System
===============================================

Pre-releases are never installed automatically, but you can install them explicitly to test them before they're
released:

.. code-block:: shell

  composer app:upgrade --list
  composer app:upgrade 2.0.0-RC1

Once the final release is published, upgrade as usual. `2.0.0` is newer than `2.0.0-RC1`, so no `--force` is needed:

.. code-block:: shell

  composer app:upgrade

Testing a Development Branch
============================

To test a fix that hasn't been released yet, install the development branch it was merged into:

.. code-block:: shell

  composer app:upgrade --list --dev
  composer app:upgrade dev-main --dev

Running `composer app:upgrade dev-main --dev` again later installs the newest commit of the branch.

To return to a stable release, install it explicitly. Since the installed version is a development version, no
`--force` is needed:

.. code-block:: shell

  composer app:upgrade 1.3.0

.. warning::

  Development versions may contain database migrations which aren't compatible with the stable releases. Don't use them
  with data you can't afford to lose.

.. _example-downgrade:

Downgrading After a Problem
===========================

If a new version causes problems, you can reinstall an older one with `--force`:

.. code-block:: shell

  composer app:upgrade 1.2.0 --force

.. danger::

  Downgrading only replaces the files. If the new version migrated your database, the old version may not work with it
  anymore. Restore the database backup you made before upgrading, or check the application's documentation for how to
  revert its migrations *before* downgrading.

Repairing an Installation
=========================

If files of the application were modified or deleted by accident, reinstall the current version:

.. code-block:: shell

  composer app:upgrade 1.3.0 --force

Files protected by the application's replace rules (e.g. local configuration and uploads) are kept, all other files are
restored. The upgrade tasks run again, which is harmless for well-written applications.

.. _example-automation:

Automated Upgrades
==================

`--no-interaction` skips the confirmation prompt, and the exit code tells whether the upgrade was successful. This lets
you upgrade from cron jobs, deployment tools like Ansible, or CI pipelines:

.. code-block:: shell

  #!/bin/sh
  # /usr/local/bin/upgrade-blog: upgrade to the latest stable release
  cd /var/www/blog || exit 1

  if composer app:upgrade --no-interaction --no-ansi > /var/log/blog-upgrade.log 2>&1; then
      systemctl reload php8.3-fpm
  else
      mail -s "Upgrading the blog failed" admin@example.com < /var/log/blog-upgrade.log
  fi

.. code-block:: text

  # /etc/cron.d/blog-upgrade: try to upgrade every Monday at 4 a.m.
  0 4 * * 1 root /usr/local/bin/upgrade-blog

If there's no new version, the command fails with "The current version ... is the same as ..." and a non-zero exit
code, so you'll get a mail in this case, too. To only get notified about real failures, check the output for that
message before sending the mail.

.. caution::

  Unattended upgrades include unattended database migrations. Only automate upgrades if the application's release
  notes don't require manual steps, and if you back up your data automatically, too.

Upgrading an Application in Another Directory
=============================================

Use `--working-dir` (`-d`) to upgrade an application without changing into its directory, e.g. when managing several
installations:

.. code-block:: shell

  for app in /var/www/blog-*; do
      sudo -u www-data composer --working-dir="$app" app:upgrade --no-interaction
  done

Servers with an Older PHP Version
=================================

New releases may require a newer PHP version than your server provides. The plugin then upgrades to the newest release
your server can run, and tells you which releases were skipped:

.. code-block:: text

  Current version of acme/blog: 1.2.0
  Skipped newer version(s) 2.0.0 because of unmet platform requirements.
  Target version: 1.3.0

To get the skipped releases, upgrade PHP on your server first. Then run `composer app:upgrade` again.

.. warning::

  `--ignore-platform-reqs` installs a version anyway, but it will most likely not work on your server. Don't use it on
  production systems.

Very Large Installations
========================

Before replacing any file, the plugin copies the `vendor` directory as backup. For installations with a very large
`vendor` directory or very little free disk space, you can skip the backup:

.. code-block:: shell

  composer app:upgrade --no-backup

.. caution::

  Without a backup, a failed upgrade can't be rolled back, and the installation may be left with a mix of old and new
  files. Make sure you have a backup of the whole application directory, e.g. a filesystem snapshot, before using
  `--no-backup`.

Applications from Private Repositories
======================================

If the application is distributed via a private Composer repository, you need credentials to download new versions. Store
them once in the application's `auth.json` (or globally with `--global`), and `app:upgrade` uses them automatically:

.. code-block:: shell

  composer config http-basic.packages.acme.com your-username your-password

  composer app:upgrade

Ask the application's vendor which credentials to use. See Composer's
`authentication documentation <https://getcomposer.org/doc/articles/authentication-for-private-packages.md>`_ for other
authentication methods.

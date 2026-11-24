.. title:: Publishing Releases

Publishing Releases
###################

.. sidebar:: Table of Contents
  .. contents::

Every release you publish is a version your users can upgrade to (or from). This page explains how the plugin treats
your versions and what to check before publishing a release.

Versions
========

The plugin uses Composer's understanding of versions:

* **Releases** are created by tagging a commit with a version number, e.g. `1.3.0` or `v1.3.0`. Use
  `semantic versioning <https://semver.org/>`_, so your users can tell what to expect from a release.
* **Pre-releases** are tags with a stability suffix, e.g. `2.0.0-alpha1`, `2.0.0-beta2` or `2.0.0-RC1`. They are listed
  and can be installed explicitly, but are never selected as the "latest" version.
* **Development versions** are branches, e.g. `dev-main` or `1.x-dev`. They are only listed and installable if the user
  passes `--dev`.

When selecting the latest version, the plugin skips releases whose platform requirements (PHP version and extensions in
the `require` section) aren't met by the user's server. Users on older servers therefore automatically get the newest
release they can run. The plugin checks against the PHP version and extensions actually available, ignoring any
`config.platform` overrides in your `composer.json`. Make sure your `require` section is accurate:

.. code-block:: json

  {
      "require": {
          "php": "^8.2",
          "ext-intl": "*",
          "ext-pdo_mysql": "*"
      }
  }

Release Checklist
=================

Before publishing a release, check that

* `composer.json` and `composer.lock` are committed and up to date (see :ref:`shipping a lock file
  <developers-lock-file>`),
* the `require` section lists the correct PHP version and all required extensions,
* the :doc:`replace rules <configuration>` cover all new directories, e.g. a new directory for user data is ignored,
* directories removed or renamed since the last release are mirrored, so they're deleted from installations,
* the `post-upgrade-cmd` script runs all tasks needed to upgrade from *any* earlier version, not only from the previous
  release, since users may skip releases,
* the `pre-upgrade-cmd` script of the *previous* release is compatible with the changes in this release, and
* the release notes mention any manual steps that can't be automated.

.. caution::

  Don't move or delete tags of published releases. Composer caches the metadata of your versions, and installations
  might download different code than you expect.

Breaking Changes
================

Upgrades across major versions often need special care. Some suggestions:

* Keep migrations for all older versions in your `post-upgrade-cmd` script, or tell users which version they have to
  upgrade to first, e.g. by checking `COMPOSER_UPDATER_PREVIOUS_VERSION` and failing with a helpful message.
* If your application's structure changes, mirror the old paths in the new release, so they're deleted (see the
  :ref:`restructuring example <example-restructuring>`).
* If the configuration format changes, migrate the configuration files in your `post-upgrade-cmd` script.
* If a new release requires a newer PHP version, users on older servers simply won't get it with `app:upgrade` until
  they upgrade PHP. Mention this prominently in your release notes.

.. _developers-testing:

Testing Upgrades
================

You can test a complete upgrade before publishing a release, using a local Git repository of your application as a
package source. Composer reads versions from its tags and branches.

#. Install the current release from the local repository:

   .. code-block:: shell

     composer create-project acme/blog /tmp/blog-test 1.2.0 \
         --repository='{"type": "vcs", "url": "/path/to/your/repository"}'

#. Add the local repository to the test installation, so `app:upgrade` finds your unpublished versions:

   .. code-block:: shell

     cd /tmp/blog-test
     composer config repositories.local vcs /path/to/your/repository

#. Upgrade to your development branch:

   .. code-block:: shell

     composer app:upgrade dev-main --dev

#. Check the result, e.g. that removed files are deleted, local configuration is kept, and your migrations ran.

To test the rollback, make your `post-install-cmd` script fail temporarily, e.g. with `@php -r "exit(1);"`, and verify
that the installation is restored.

.. tip::

  Add some local changes to the test installation before upgrading, e.g. edit a configuration file and add a file to a
  user data directory, to check that your replace rules protect them.

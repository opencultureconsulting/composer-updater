.. title:: Installation

Installation
############

.. sidebar:: Table of Contents
  .. contents::

Requirements
============

* PHP 8.2 or newer
* Composer 2.3 or newer

Your application must

* be distributed as a Composer package with a `name` in its `composer.json`,
* be available from a Composer repository, usually `Packagist <https://packagist.org/>`_ (see
  :ref:`private repositories <developers-private-repositories>` for alternatives), and
* be installed by its users with `composer create-project`.

The plugin doesn't depend on any framework. It only uses the Composer API, so it works with Symfony, Laravel, Laminas,
Slim, or plain PHP applications alike.

.. note::

  The plugin has been tested on Linux. It doesn't use any platform-specific functionality, but Windows and macOS are
  not covered by the automated tests yet.

Step by Step
============

#. Require the plugin in your application:

   .. code-block:: shell

     composer require opencultureconsulting/updater

#. Allow the plugin to run:

   .. code-block:: shell

     composer config allow-plugins.opencultureconsulting/updater true

   Since Composer 2.2, plugins must be allowed explicitly. This adds the plugin to the `config.allow-plugins` section of
   your `composer.json`:

   .. code-block:: json

     {
         "config": {
             "allow-plugins": {
                 "opencultureconsulting/updater": true
             }
         }
     }

   .. caution::

     Don't skip this step! If the plugin isn't allowed in your `composer.json`, Composer disables it when your users run
     `composer create-project` non-interactively, and the `app:upgrade` command won't be available.

#. Make sure your `composer.json` has the `type` `project` and a `name`:

   .. code-block:: json

     {
         "name": "acme/blog",
         "type": "project"
     }

#. Commit `composer.json` and `composer.lock`, and publish a new release.

That's it! Starting with this release, your users can upgrade their installation with `composer app:upgrade`. Note that
installations of older releases (without the plugin) have to be upgraded manually once.

.. _developers-recommended-setup:

Recommended Setup
=================

The defaults work for many applications, but most applications benefit from some configuration:

* Ship your application with a `composer.lock` (see :ref:`below <developers-lock-file>`).
* Configure the :doc:`replace rules <configuration>`, so local configuration and user data are kept, and files removed
  from your application are deleted from the installations.
* Add a :doc:`post-upgrade-cmd script <scripts>` to run database migrations and clear caches.
* Add a :doc:`pre-upgrade-cmd script <scripts>` to enable a maintenance mode during the upgrade.
* Tell your users about the `app:upgrade` command in your installation instructions, e.g. by linking to the
  :doc:`User Guide <../users/index>`.

The :doc:`examples <examples>` show complete configurations for typical applications.

.. _developers-lock-file:

Shipping a Lock File
====================

It is strongly recommended to commit your application's `composer.lock` and to include it in your releases (i.e. not
to exclude it with `export-ignore` in your `.gitattributes`). Many applications don't, because Composer's
documentation recommends against committing the lock file for *libraries*. But an application installed with
`composer create-project` isn't a library, and a lock file has several advantages for it:

* **Tested dependencies:** Every installation of a release gets exactly the versions of your dependencies you tested
  the release with, both with `composer create-project` and with `composer app:upgrade`. Without a lock file, the
  dependencies are resolved anew on every installation, so your users might get newer, untested versions.
* **Faster and more reliable upgrades:** Installing from a lock file doesn't need to resolve dependencies, which is
  faster and less likely to fail, e.g. because of a temporarily unavailable package.
* **Predictable scripts:** Composer runs different scripts depending on whether there is a lock file. With a lock file,
  `composer install` runs the `pre-install-cmd` and `post-install-cmd` scripts. Without one, it resolves the
  dependencies like `composer update` and runs the `pre-update-cmd` and `post-update-cmd` scripts instead. This also
  applies to `composer create-project` and to the dependency installation during an upgrade (see
  :doc:`upgrade scripts <scripts>`).

If your release doesn't contain a lock file, the plugin removes the installation's existing lock file during the
upgrade, so the new version's dependencies are resolved from scratch rather than installed from an outdated lock file.

.. tip::

  Set the `config.platform.php` option in your `composer.json` to the lowest PHP version you support. Then the lock
  file doesn't contain dependency versions which require a newer PHP version than your users might have, no matter
  which PHP version you create the lock file with. The plugin ignores this setting when checking which versions your
  users' servers can run, so it doesn't keep them from upgrading to releases requiring a newer PHP version.

  .. code-block:: json

    {
        "config": {
            "platform": {
                "php": "8.2.0"
            }
        }
    }

.. _developers-private-repositories:

Private Repositories
====================

The plugin looks for new versions in the repositories configured in the installed application's `composer.json`.
Packagist is used by default. If you distribute your application through a private Composer repository, e.g.
`Private Packagist <https://packagist.com/>`_ or a self-hosted `Satis <https://composer.github.io/satis/>`_ instance,
add it to your application's `composer.json`:

.. code-block:: json

  {
      "repositories": [
          {
              "type": "composer",
              "url": "https://packages.acme.com"
          }
      ]
  }

Credentials are never stored in your application. Your users configure them in their `auth.json` or with
`composer config`, just like for any other private package:

.. code-block:: shell

  composer config --global http-basic.packages.acme.com username password

Uninstalling
============

To remove the plugin from your application, run:

.. code-block:: shell

  composer remove opencultureconsulting/updater

This also removes the file `vendor/composer-updater.json` which holds the installed version. You may also remove the
`allow-plugins` entry and the `extra.composer-updater` section from your `composer.json`.

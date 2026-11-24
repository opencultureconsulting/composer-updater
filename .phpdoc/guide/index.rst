.. title:: Updater for Composer-based Applications

Documentation
#############

The *Updater for Composer-based Applications* is a `Composer <https://getcomposer.org/>`_ plugin which adds an
`app:upgrade` command to any application installed with `composer create-project`. With a single command, users of the
application can

* list all available versions of the application,
* download and install any of them (including development branches, if they ask for it),
* install the new version's dependencies, and
* run the application's own upgrade tasks, e.g. database migrations.

If anything goes wrong while replacing the files or installing the dependencies, the previous version is restored
automatically.

.. code-block:: shell

  # Upgrade to the latest stable release
  composer app:upgrade

The documentation is divided into two parts: the :doc:`Developer Guide <developers/index>` explains how to add the
plugin to your application and how to prepare your releases for automated upgrades. The
:doc:`User Guide <users/index>` explains how to upgrade an application which comes with the plugin. Both build on the
concepts explained in the :doc:`Overview <overview>`.

.. toctree::
  :maxdepth: 2

  overview
  developers/index
  users/index
  changelog

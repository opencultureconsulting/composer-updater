.. title:: User Guide

User Guide
##########

This part of the documentation is for people who run an application which comes with the self-updater, and want to
upgrade it to a newer version. You don't need to know anything about the plugin itself: if your application's
documentation mentions `composer app:upgrade`, this guide is for you.

.. code-block:: shell

  # Show the installed version and all available versions
  composer app:upgrade --list

  # Upgrade to the latest stable release
  composer app:upgrade

Before upgrading, please read the release notes of the new version, and make sure you have a backup of your
application's data (especially its database). The plugin restores the application's *files* if the upgrade fails, but
it can't restore data changed by the application's upgrade tasks.

Table of Contents
=================

.. toctree::
  :maxdepth: 2

  upgrading
  examples
  troubleshooting

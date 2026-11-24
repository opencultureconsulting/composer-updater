.. title:: Changelog

Changelog
#########

.. sidebar:: Table of Contents
  .. contents::

All available versions as well as further information about requirements and dependencies can be found in the
`Packagist repository <https://packagist.org/packages/opencultureconsulting/updater>`_ and on the
`GitHub releases page <https://github.com/opencultureconsulting/composer-updater/releases>`_.

v1.0.0
======

**Initial Release**

* `app:upgrade` command to list, download and install any version of a Composer-based application
* Automatic selection of the newest stable release meeting the server's platform requirements
* Configurable replace rules (`copy`, `mirror`, `preserve`, `ignore`) in `extra.composer-updater`
* `pre-upgrade-cmd` and `post-upgrade-cmd` scripts for application-specific upgrade tasks
* Automatic rollback if the upgrade fails
* Reliable detection of the installed version after `composer create-project`

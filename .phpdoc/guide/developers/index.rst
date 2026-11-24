.. title:: Developer Guide

Developer Guide
###############

This part of the documentation is for developers who want to add the self-updater to their application. It explains
how to install the plugin, how to configure the way your application's files are replaced, how to hook your own upgrade
tasks into the process, and what to keep in mind when publishing releases.

Adding the plugin takes two commands. Everything else is optional and only needed if the defaults don't fit your
application:

.. code-block:: shell

  composer require opencultureconsulting/updater
  composer config allow-plugins.opencultureconsulting/updater true

Make sure you have read the :doc:`Overview <../overview>` first, as it explains the concepts this guide builds on.

Table of Contents
=================

.. toctree::
  :maxdepth: 2

  installation
  configuration
  scripts
  releasing
  examples

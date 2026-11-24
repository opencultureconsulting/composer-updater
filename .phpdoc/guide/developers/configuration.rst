.. title:: File Replacement Rules

File Replacement Rules
######################

.. sidebar:: Table of Contents
  .. contents::

When upgrading, the plugin replaces the installed application's files with those of the new version. By default, all
files are overwritten and all directories are merged, so nothing is ever deleted. You can change this for any path with
the *replace rules* in the `extra.composer-updater` section of your `composer.json`.

Configuration
=============

The configuration is an object with up to four keys, each holding a list of paths:

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "copy": ["public"],
              "mirror": ["src", "templates", "public/build"],
              "preserve": ["config/local.php", ".env"],
              "ignore": ["var", "public/uploads"]
          }
      }
  }

All keys are optional. Paths are relative to the project directory and may point to files or directories at any depth.
They must not leave the project directory (`..` is not allowed). Both `/` and `\\` are accepted as separators, and
leading or trailing separators are ignored.

.. important::

  The configuration is always read from the version *being installed*. This means each release defines how it is
  installed, and you can change the rules from one release to the next. It also means that a change of the rules only
  takes effect when upgrading *to* the release containing it.

The Rules
=========

`copy`
------

This is the default for every path that isn't configured otherwise. Files are overwritten with those of the new version,
and directories are merged. Files which exist in the installation but not in the new version are kept.

Use it for paths that may contain additional files added by the administrator, e.g. a `public` directory with custom
`robots.txt` or verification files. You only need to configure `copy` explicitly to override a less specific rule, e.g.
for a subdirectory of a mirrored directory.

`mirror`
--------

Files are overwritten like with `copy`, but files which exist in the installation but not in the new version are
deleted, so the path matches the new version exactly.

If a mirrored path doesn't exist in the new version at all, it is deleted from the installation completely. This lets
you remove or rename directories between releases (see the :ref:`restructuring example <example-restructuring>`).

Use it for all directories containing your application's code, e.g. `src`, `templates` or `bin`, and for build artifacts
with changing file names. Otherwise, files you removed from your application would remain in every installation
forever.

`preserve`
----------

Existing files are never touched. Files which don't exist in the installation yet are added.

Use it for files the administrator is expected to edit, e.g. local configuration files. Your release can contain a
default version of the file, which is used for new installations and for installations where the file doesn't exist
yet, but never overwrites the administrator's changes.

.. note::

  `preserve` also means that changes to the default file in new releases never reach existing installations. If your
  configuration format changes, consider the :ref:`configuration template pattern <example-config-template>`, or
  migrate the configuration in your `post-upgrade-cmd` script.

`ignore`
--------

The path is neither copied nor deleted.

Use it for directories holding data created at runtime, e.g. caches, logs, sessions or user uploads. Files from the new
version are not copied into ignored paths either, so empty placeholder files like `.gitkeep` in your release aren't
added to the installation.

Precedence
==========

Every path is handled by the *most specific* rule configured for it, i.e. the rule configured for the path itself or
for its closest parent directory. Paths not covered by any rule use `copy`. This lets you define exceptions for files
and subdirectories:

.. code-block:: json

  {
      "extra": {
          "composer-updater": {
              "mirror": ["src", "config"],
              "preserve": ["config/local.php"],
              "copy": ["src/Plugins"]
          }
      }
  }

With this configuration

* `src/Controller/HomeController.php` is mirrored (rule of `src`),
* `src/Plugins/Custom.php` is copied, so custom plugins added by the administrator are kept (rule of `src/Plugins`),
* `config/services.php` is mirrored (rule of `config`),
* `config/local.php` is preserved (rule of the file itself), and it is also kept when mirroring `config`, and
* `README.md` is copied (default rule).

.. caution::

  Inside a mirrored directory, a subdirectory with the `copy` rule only keeps additional files as long as the new
  version contains that subdirectory. If it is missing from the new version, it is deleted like everything else in the
  mirrored directory. Ship such directories with a placeholder file (e.g. `.gitkeep`), or use `preserve` or `ignore`
  instead.

Preserved and ignored paths are protected from less specific rules in both directions:

* They are never deleted by a mirror rule of a parent directory, not even if the parent directory doesn't exist in the
  new version at all. Only the unprotected files around them are removed.
* More specific rules inside a preserved or ignored directory are still applied. With `"preserve": ["config"]` and
  `"copy": ["config/defaults.php"]`, the defaults are updated while the rest of `config` stays untouched.

If the new version contains a *file* where the installation has a directory with preserved or ignored paths inside, the
upgrade is aborted (and rolled back), because the file can't be installed without deleting protected data.

Note that a path is only compared with complete path segments: a rule for `src` applies to `src/Foo.php`, but not to
`srcfoo/Bar.php`.

Paths that are Always Ignored
=============================

These paths are ignored regardless of the configuration:

`vendor`
  The vendor directory (or wherever `config.vendor-dir` points to) is managed by `composer install`. It is backed up
  before the upgrade, so it can be restored in case of a rollback.

`.git`, `.hg`, `.svn`
  VCS metadata is never copied. This matters if a release is downloaded from source instead of as an archive, e.g. for
  development branches.

`.composer-updater-backup`
  The backup directory created during the upgrade.

Symbolic Links
==============

Symbolic links in your release are copied as links. In the installation, symbolic links to *directories* are kept: if
the administrator replaced a directory with a link, e.g. to move uploads to shared storage, the new version's files are
merged into the directory the link points to. Symbolic links to files are replaced like regular files.

.. tip::

  Directories which administrators commonly move to shared storage should be configured as `ignore` anyway.

What Else is Copied
===================

The plugin installs whatever Composer downloads for the release, i.e. the *dist* archive (preferred) or the source
checkout. Composer respects `export-ignore` rules in your `.gitattributes` when creating archives on GitHub, GitLab and
Bitbucket. Use them to keep tests, tool configurations and other development files out of your users' installations:

.. code-block:: text

  /.github/         export-ignore
  /tests/           export-ignore
  /phpunit.xml.dist export-ignore
  /.gitattributes   export-ignore
  /.gitignore       export-ignore

.. caution::

  If your release doesn't contain a `composer.lock`, the existing lock file of the installation is removed and
  `composer install` resolves the dependencies from scratch. Your users might get untested versions of your
  dependencies then. Always commit your `composer.lock` and don't exclude it with `export-ignore`.

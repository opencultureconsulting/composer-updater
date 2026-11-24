# Updater for Composer-based Applications

This [Composer](https://getcomposer.org/) plugin adds an `app:upgrade` command to any application deployed with `composer create-project`. It lists the available versions of the application, downloads and installs any of them, installs the new version's dependencies and finally runs the application's own upgrade tasks (e.g. database migrations).

This application follows the highest coding standards of [PHPStan](https://phpstan.org/), [Psalm](https://psalm.dev/), [PHP Mess Detector](https://phpmd.org/), [PHP_CodeSniffer](https://github.com/PHPCSStandards/PHP_CodeSniffer/), and complies to [PSR-12](https://www.php-fig.org/psr/psr-12/) code style guidelines to make sure it is reliable, maintainable and easily reusable.

## Quick Start

### For application developers

Require the plugin in your application and allow it to run:

```shell
composer require opencultureconsulting/updater
composer config allow-plugins.opencultureconsulting/updater true
```

Make sure your `composer.json` has a `name` and your application is available from a Composer repository (usually [Packagist](https://packagist.org/)). That's it! Optionally, you can

- define a `pre-upgrade-cmd` script which is run before each upgrade (e.g. to enable a maintenance mode or back up the database),
- define a `post-upgrade-cmd` script which is run after each upgrade (e.g. to run database migrations), and
- configure how the application's files are replaced.

```json
{
    "scripts": {
        "pre-upgrade-cmd": "@php bin/console app:maintenance --enable",
        "post-upgrade-cmd": [
            "@php bin/console doctrine:migrations:migrate --no-interaction",
            "@php bin/console cache:clear",
            "@php bin/console app:maintenance --disable"
        ]
    },
    "extra": {
        "composer-updater": {
            "mirror": ["src", "templates"],
            "preserve": ["config/local.php", ".env"],
            "ignore": ["data", "var"]
        }
    }
}
```

The `pre-upgrade-cmd` script is taken from the version *currently installed*. The `post-upgrade-cmd` script and the configuration are taken from the version *being installed*, so each release defines how it is installed. Both scripts can read the environment variables `COMPOSER_UPDATER_PREVIOUS_VERSION` and `COMPOSER_UPDATER_VERSION`, which hold the versions upgraded from and to.

Make sure to commit your `composer.lock`, so every installation of a release gets exactly the dependencies you tested.

### For users of the application

```shell
composer app:upgrade --list          # list available versions
composer app:upgrade --list --dev    # include development branches
composer app:upgrade                 # upgrade to the latest stable release
composer app:upgrade 2.1.0           # install a specific version
composer app:upgrade dev-main --dev  # install a development branch
```

Use `--force` to reinstall the current version or to downgrade, `--ignore-platform-reqs` to install a version although your PHP version or extensions do not meet its requirements, `--no-backup` to skip the backup (which disables the automatic rollback) and `--no-interaction` to skip the confirmation prompt.

## How it works

1. The available versions of the application are fetched from the repositories configured in its `composer.json` (Packagist by default, including any authentication configured for Composer).
2. Unless a version is given explicitly, the newest stable release whose platform requirements (PHP version and extensions) are met is selected.
3. The selected version is downloaded and extracted to a temporary directory.
4. The vendor directory (and the bin directory, if configured outside of it) is backed up to `.composer-updater-backup` in the project directory.
5. The `pre-upgrade-cmd` script of the current version is run, if defined.
6. The application's files are replaced according to the rules in `extra.composer-updater` of the new version's `composer.json`. Every replaced or deleted file is moved to the backup first. Every path is handled by the most specific rule configured for it:
   - **copy** (default): Files are overwritten, directories are merged, so files not part of the new version are kept.
   - **mirror**: Like *copy*, but files not part of the new version are deleted.
   - **preserve**: Existing files are never touched, missing ones are added (e.g. local configuration).
   - **ignore**: The path is neither copied nor deleted.

   The vendor directory and VCS metadata (`.git` etc.) are never touched. If the new version does not contain a `composer.lock`, the existing one is removed, so the new version's dependencies are resolved from scratch.
7. `composer install` is run for the new version (with `--no-dev` unless the dev dependencies were installed before).
8. The backup is removed and the `post-upgrade-cmd` script of the new version is run, if defined.

### Rollback

If any of the steps 5 to 7 fails, all files and the vendor directory are restored from the backup, so the previous version keeps working. Anything the `pre-upgrade-cmd` script did (e.g. enabling a maintenance mode) is *not* undone.

A failing `post-upgrade-cmd` script does not trigger a rollback, because its tasks (e.g. database migrations) may have been run partially and the previous version might not work with them anymore. Fix the problem and run `composer run-script post-upgrade-cmd` manually.

If the rollback itself fails, the backup is kept in `.composer-updater-backup` and further upgrades are refused (even with `--no-backup`) until it is removed.

### Version detection

Composer cannot detect the version of an application installed without VCS metadata (which is the case after `composer create-project`). Therefore, the plugin records the installed version in `vendor/composer-updater.json` whenever it is known for sure, i.e. after `composer create-project` and after each upgrade. If that file is missing, the version detected by Composer is used, which can be overridden by setting the environment variable `COMPOSER_ROOT_VERSION`.

## Full Documentation

The full documentation is available on [GitHub Pages](https://code.opencultureconsulting.com/composer-updater/).

## Quality Gates

[![PHPCS](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpcs.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpcs.yml)
[![PHP CS Fixer](https://github.com/opencultureconsulting/composer-updater/actions/workflows/php-cs-fixer.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/php-cs-fixer.yml)
[![PHPMD](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpmd.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpmd.yml)
[![PHPStan](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpstan.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpstan.yml)
[![Psalm](https://github.com/opencultureconsulting/composer-updater/actions/workflows/psalm.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/psalm.yml)
[![PHPUnit](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpunit.yml/badge.svg)](https://github.com/opencultureconsulting/composer-updater/actions/workflows/phpunit.yml)

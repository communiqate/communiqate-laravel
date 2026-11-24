# Contributing to CommuniQate for Laravel

## Development

Run the test suite from the Laravel package directory with the stable PHP SDK from Packagist.

```sh
composer validate --strict
composer install
composer test
```

To test changes in both packages together, check out `communiqate-php` beside this repository and run the commands below. Composer uses a local manifest and symlinks the SDK working tree, including untagged feature branches.

```sh
(
    export COMPOSER=composer.local.json
    cp composer.json "$COMPOSER"
    composer config repositories.local-sdk '{"type":"path","url":"../communiqate-php","options":{"symlink":true}}'
    composer require 'communiqate/communiqate-php:*@dev'
    realpath vendor/communiqate/communiqate-php
    composer test
)
```

To restore the regular installation, remove the symlink through the local manifest and reinstall from the package manifest.

```sh
COMPOSER=composer.local.json composer remove communiqate/communiqate-php
composer install
```

The tests exercise the Laravel mail manager, notification dispatcher and HTTP router with simulated API responses. To test a particular framework major locally, run `composer update --with 'laravel/framework:^12.0'` or `composer update --with 'laravel/framework:^13.0'`, then `composer test`. Run compatibility changes on PHP 8.3 through 8.6. Laravel 12 currently emits upstream deprecation notices on PHP 8.6.

## Releases

Use a Conventional Commit title for the squash merge into `main`. A `fix:` proposes a patch, a `feat:` a minor release, and a breaking change (for example `feat!:` with a migration note) a major release. After package changes merge into `main`, Release Please opens or updates a release PR with the next version and the English changelog. Merging that PR publishes the `vX.Y.Z` Git tag and GitHub Release. Each package is versioned independently. [Release Please](https://github.com/googleapis/release-please-action)

### Packagist

When a release requires a new PHP SDK version, check that Packagist lists the compatible SDK tag before publishing the Laravel release. Enable the GitHub hook to index future tags automatically. [PHP SDK on Packagist](https://packagist.org/packages/communiqate/communiqate-php) · [GitHub hook setup](https://packagist.org/about#how-to-update-packages)

### GitHub Actions settings

Enable GitHub Actions and allow it to create pull requests. If your branch rules require CI on bot-created PRs without manual approval, set a repository-scoped `RELEASE_PLEASE_TOKEN` with contents, pull request and issue write access. The workflow otherwise uses the repository token.

For automatic publication after an ordinary package PR merges, enable **Allow auto-merge** and **Allow squash merging** in GitHub, set `RELEASE_PLEASE_TOKEN`, and set the Actions variable `AUTO_MERGE_RELEASE_PRS=true`. The workflow enables auto-merge on its generated release PR. Required checks and reviews still apply. With the variable unset, a maintainer merges the release PR when ready.

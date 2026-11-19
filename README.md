# KK Content Exporter

[![CI](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/ilclaudio/kk-content-exporter)](LICENSE)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)

A WordPress plugin that exposes published content as configurable, read-only JSON feeds for an
external importer. An administrator picks a post type, configures one or more feeds and maps the
content fields freely to JSON keys; each feed is served at `GET /wp-json/kkce/v1/<feed-slug>`.

> **Status:** in development. Feeds can be created and configured in the admin, and their mapping of
> the core fields can be built, previewed and saved; the endpoint `kkce/v1/<feed-slug>` and the sources
> beyond the core fields (meta, taxonomies, images, ACF, CMB2) are not implemented yet.

This file is for developers. The description for site administrators is in [`readme.txt`](readme.txt)
(WordPress.org format).

## Requirements

- WordPress 6.4 or later, PHP 8.3 or later.
- No runtime dependencies. Composer is used only for development tools and is never shipped.

## Development setup

Tools needed on the development machine: PHP 8.3+ and Composer for the static checks and the unit
tests; in addition, for the integration and end-to-end tests in Docker, Node 24 and Docker Desktop.

```sh
composer install                 # PHP tools: PHPCS, PHPStan, PHPUnit, Brain Monkey, WP-CLI i18n
composer run hooks:install       # Git hooks: secret scan, lint, PHPCS, PHPStan, unit tests
npm ci                           # test tools: wp-env, Playwright, axe-core
npx playwright install chromium  # browser of the end-to-end tests (once per machine)
```

None of these tools is shipped: the plugin has no runtime dependencies.

## Checks and tests

```sh
composer run check             # lint + PHPCS + PHPStan + unit tests: the fast gate before every commit
composer run test:integration  # integration tests in Docker
composer run test:e2e               # end-to-end tests in Chromium
```

The three test levels, every command and when to run it: [`tests/README.md`](tests/README.md).

## Release

`composer run build:zip` builds `build/kk-content-exporter-<version>.zip` from a clean working tree;
the files left out of the package are listed in [`.distignore`](.distignore). Run
[Plugin Check](https://wordpress.org/plugins/plugin-check/) on the built package, not on the working
folder.

## Contributing, security and license

- [`CONTRIBUTING.md`](CONTRIBUTING.md): rules for contributions.
- [`SECURITY.md`](SECURITY.md): how to report a vulnerability.
- [`CHANGELOG.md`](CHANGELOG.md): changes per version.
- License: GPL-3.0-or-later, see [`LICENSE`](LICENSE).

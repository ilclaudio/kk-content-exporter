# KK Content Exporter

[![CI](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/ilclaudio/kk-content-exporter)](LICENSE)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)

A WordPress plugin that exposes published content as configurable, read-only JSON feeds for an
external importer. An administrator picks a post type, configures one or more feeds and maps the
content fields freely to JSON keys; each feed is served at `GET /wp-json/kkce/v1/<feed-slug>`.

> **Status:** version 0.0.1 contains only the plugin bootstrap. The feed features are under
> development and no feed can be configured yet.

This file is for developers. The description for site administrators is in [`readme.txt`](readme.txt)
(WordPress.org format).

## Requirements

- WordPress 6.4 or later, PHP 8.3 or later.
- No runtime dependencies. Composer is used only for development tools and is never shipped.

## Development setup

```sh
composer install            # development tools (PHPCS, PHPStan, PHPUnit, Brain Monkey, WP-CLI i18n)
composer run hooks:install  # Git hooks: secret scan, lint, PHPCS, PHPStan, unit tests
```

## Checks and tests

```sh
composer run check             # lint + PHPCS + PHPStan + unit tests (the fast local gate)
composer run test:integration  # integration tests on the WordPress test suite
```

Test levels, commands, setup of the integration suite and how to add a test:
[`tests/README.md`](tests/README.md).

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

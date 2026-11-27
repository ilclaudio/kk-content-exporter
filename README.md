# KK Content Exporter

[![CI](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/ilclaudio/kk-content-exporter)](LICENSE)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)
[![Version](https://img.shields.io/github/v/tag/ilclaudio/kk-content-exporter?sort=semver)](https://github.com/ilclaudio/kk-content-exporter/tags)

Share published WordPress content through custom, read-only JSON feeds. Use them to import content
into other WordPress sites, applications or external systems, with fields and values tailored to the
destination.

Combine posts, pages and custom post types in one feed with a common structure. Define shared mapping
rules and exceptions for each post type, so different source fields produce the same output keys.

For installation and a first-feed walkthrough, see [`readme.txt`](readme.txt). For the API contract and
importer guidance, see [`Reading a feed`](docs/reading-a-feed.md).

## Project status

Version 1.0.0 is being prepared for the WordPress.org Plugin Directory.

## Requirements

- WordPress 6.4 or later, PHP 8.3 or later.
- No runtime dependencies. Composer is used only for development tools and is never shipped.

## How it works

1. Select one or more public post types and the fields to share: core fields, simple post meta,
   featured image URLs, taxonomies and language fields (item languages require Polylang).
2. Build the output with source fields, fixed values, templates, lookup tables and conditions.
   Use per-post-type exceptions to handle different fields or values within the same feed.
3. Check the live JSON preview, then publish and enable the feed.
4. Let the receiving system read the feed and handle importing and updating its own content.

For example, news and events can share `title`, `description` and `url` in one feed, even when their
descriptions come from different custom fields.

Only published, non-password-protected items and mapped fields are exported. Every mapped key is
present, with `null` when no value is available. Source post types do not need `show_in_rest`.
The plugin leaves source content and WordPress's native REST API unchanged and makes no external requests.

## Screenshots

The Mapping box: each row writes one JSON key, with a live preview of the items a reader will receive.

![Mapping box with JSON preview](assets/screenshots/screenshot-1.jpg)

More screenshots: [feed settings](assets/screenshots/screenshot-2.jpg),
[feed list](assets/screenshots/screenshot-3.jpg), [global settings](assets/screenshots/screenshot-4.jpg),
[built-in guide](assets/screenshots/screenshot-5.jpg), [public JSON feed](assets/screenshots/screenshot-6.jpg).

## Using the REST API

Each feed has its own endpoint: `GET /wp-json/kkce/v1/<feed-slug>`. For a public feed:

```sh
curl 'https://example.com/wp-json/kkce/v1/news?page=1&per_page=2'
```

Example response:

```json
{
  "items": [
    { "title": "Site news", "url": "https://example.com/site-news/" },
    { "title": "Community event", "url": "https://example.com/community-event/" }
  ],
  "pagination": { "page": 1, "per_page": 2, "total": 2, "total_pages": 1 },
  "meta": { "feed": "news", "generated_at": "2026-11-20T12:00:00Z" }
}
```

Use `page` and `per_page` for pagination, within the maximum set by the administrator. Items are ordered
by modified date in UTC, then ID, both ascending. Date filters (`modified_after`, `modified_before`,
`published_after`) support incremental reads; optional `lang` selects a Polylang language.

Authenticated feeds require HTTPS and an Application Password for a user with `kkce_read_export`.
A browser login alone is not enough. With curl, enter the Application Password when prompted:

```sh
curl --user export-reader 'https://example.com/wp-json/kkce/v1/news'
```

See the [feed reader guide](docs/reading-a-feed.md) for authentication, filter semantics, error codes
and synchronization limits, including changes made only to meta or terms and deleted items.

## Development setup

Tools needed on the development machine: PHP 8.3+ and Composer for the static checks and the unit
tests; in addition, for the integration and end-to-end tests in Docker, Node 24 and Docker Desktop.

```sh
composer install                 # PHP tools: PHPCS, PHPStan, PHPUnit, Brain Monkey, WP-CLI i18n
composer run hooks:install       # Git hooks: secret scan, lint, PHPCS, PHPStan, unit tests
npm ci                           # test tools: wp-env, Playwright, axe-core
npx playwright install chromium  # browser of the end-to-end tests (once per machine)
```

## Code layout

| Path | Purpose |
|---|---|
| `kk-content-exporter.php`, `includes/class-plugin.php` | Bootstrap, autoloading setup and hook registration |
| `includes/admin/` | Feed list, editor and settings screens |
| `includes/sources/` | Catalog of selectable fields and providers that read their values |
| `includes/output/` | Mapping validation, rule evaluation, value conversion and response structure |
| `includes/rest/` | Feed endpoints, access decisions and editor catalog/preview routes |
| `assets/` | Admin JavaScript and CSS, with no build step |
| `tests/` | Unit, WordPress integration and browser tests |
| `bin/` | Development and release scripts, excluded from the plugin package |

Pure mapping and validation logic is separated from WordPress hooks, queries and REST handling.

## Checks and tests

```sh
composer run check             # lint + PHPCS + PHPStan + unit tests: the fast gate before every commit
composer run test:integration  # integration tests in Docker
composer run test:e2e          # end-to-end tests in Chromium
```

CI checks code and configuration, runs integration tests, builds the ZIP and audits dependencies.
E2E tests have a separate manual workflow; Dependabot proposes monthly tool updates.

Test setup, isolated Docker environments and commands: [`tests/README.md`](tests/README.md).
Manual API checks: [`tests/routes/README.md`](tests/routes/README.md).

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

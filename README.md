# KK Content Exporter

[![CI](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/ilclaudio/kk-content-exporter/actions/workflows/ci.yml)
[![License](https://img.shields.io/github/license/ilclaudio/kk-content-exporter)](LICENSE)
[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](https://phpstan.org/)

KK Content Exporter makes published WordPress content available as read-only JSON feeds through a
REST API. Other websites, applications and systems can read these feeds or use them to import data.

Choose which content to include, which fields to expose and what to call them. Give different content
types a common structure so other systems can use them without knowing how they are organized inside
WordPress.

For installation and a first-feed walkthrough, see [`readme.txt`](readme.txt). The sections below cover
the plugin's operation, REST API and development workflow.

## Project status

In development. Feed configuration, the mapping editor with preview and save, WordPress core fields
and selected post meta are available, along with public and authenticated feeds with pagination.
Date filters and additional sources (taxonomies, featured images, language, ACF and CMB2 fields)
are still being developed.

## Requirements

- WordPress 6.4 or later, PHP 8.3 or later.
- No runtime dependencies. Composer is used only for development tools and is never shipped.

## How it works

1. An administrator creates a feed and selects one or more public post types.
2. Mapping rows define each output key and its value: a source field, fixed value, template, lookup
   table or condition. Fields from different post types can supply the same output key.
3. The preview and the feed use the same mapping engine. It reads the selected sources, evaluates
   the rules and converts values to the chosen output types.
4. The feed endpoint returns published, non-password-protected items in a shared JSON structure.
   Only mapped fields are included; each mapped key is present, with `null` when no value is available.

For example, a site stores news and events with different fields. A feed can present both with the
same fields, such as title, description and link, so another system can read them in the same way.

Feeds are stored in the private `kkce_feed` post type, with configuration in post meta. The plugin
queries source content directly, so a source post type does not need `show_in_rest`. It leaves the
original content and its visibility unchanged, and does not replace WordPress's native REST API.
The receiving system handles reading or importing the data.

## Global settings

Administrators manage **Content Exporter > Settings** through the WordPress Settings API. The option
`kkce_settings` holds these values; `KKCE\Settings` defines their defaults and validation, and
`KKCE\Admin\Settings_Page` renders the controls. The user guide in [`readme.txt`](readme.txt) explains
each control and the diagnostic messages.

| Setting | Option key | Default | Effect |
|---|---|---|---|
| Endpoints | `enabled` | `true` | Registers the feed route when on; when off, requests return 404 and feed configuration is retained. |
| REST API index | `show_in_index` | `true` | Includes the feed route and parameter descriptions in REST discovery. Turning it off does not disable or protect feed URLs. |
| Authentication | `require_auth` | `false` | Overrides per-feed access and requires Application Password authentication for every feed. |
| Maximum items per page | `max_per_page` | `100` | Positive integer, with no fixed upper ceiling. Caps a feed's configured page size; an explicit `per_page` above it is rejected. |
| Uninstall | `remove_data` | `false` | On plugin deletion, removes feeds, configuration and the export capability from roles and users. Source content is retained; deactivation does not perform cleanup. |

Diagnostics check for blocked anonymous REST access, unavailable Application Passwords and enabled
feeds with confirmed sensitive sources. They report warnings without changing settings or making
external requests. Feed-based diagnostics inspect at most 100 feeds; they are not a complete audit
of every feed on a large site.

## Using the REST API

Each feed has its own endpoint: `GET /wp-json/kkce/v1/<feed-slug>`. It must be published and enabled
before it can be read. Requests have no body; use the exact feed URL shown in the editor.

### Public feeds

For a public feed with the slug `news` on an example site:

```sh
curl 'https://example.com/wp-json/kkce/v1/news?page=1&per_page=2'
```

### Response format and pagination

A feed mapped to `title` and `url` might return:

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

`items` contains the mapped data, `pagination` describes the result pages, and `meta` identifies the
feed. Its `generated_at` value is the UTC timestamp when the response was generated.

Use `page` to read successive pages; `per_page` cannot exceed the maximum in the plugin settings.
Items are ordered by modified date in UTC, then ID, both ascending.

### Authenticated feeds

For an authenticated feed, use HTTPS and a WordPress Application Password belonging to a user with
the `kkce_read_export` capability. Administrators receive that capability on plugin activation.
For example, with a user named `export-reader`:

```sh
curl --user export-reader 'https://example.com/wp-json/kkce/v1/news'
```

When curl prompts for a password, enter the Application Password, not the user's normal login
password. A browser login alone does not authorize an authenticated feed.

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

The three test levels, every command and when to run it: [`tests/README.md`](tests/README.md).

Docker environments use separate configurations: `.wp-env.inttest.json` creates
`wp-env-kk-content-exporter-inttest-<hash>` for integration tests (port 8891), and
`.wp-env.e2etest.json` creates `wp-env-kk-content-exporter-e2etest-<hash>` for end-to-end tests (port 8890).
Use the Composer commands to select the right environment; direct wp-env commands require `--config`.

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

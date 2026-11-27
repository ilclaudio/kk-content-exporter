# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

Allowed TAGS: Added, Changed, Deprecated, Removed, Fixed, Security.


## [1.0.0] - Unreleased
### Added
- Guide for the developers of a program that reads the feeds, in `docs/` on the repository.
### Changed
- Plugin description: one or more post types.
- Italian translation reviewed for consistent terms.
- Development: shared helpers of the test runners; mappings normalized once during validation.
- Development: workflow validation, bounded CI runs, monthly dependency updates and stricter npm auditing.
- Development: an own list check instead of `array_is_list()`, which Plugin Check reports as above WordPress 6.4.
### Removed
- The development note in the row of the plugin on the Plugins screen.
### Fixed
- Post meta registered for every post type keep their declared type (for example true or false, not text).
- With the output type Automatic, a key has the same JSON type in every item, also across post types.
- Very large pages and page sizes answer with an empty page instead of an error.
### Security
- The preview warns when an exported author display name is still the login name.
- Development: the route runner no longer prints parts of an invalid configuration file, which holds credentials.

## [0.0.4] - 2026-11-25
### Added
- Feed endpoint `GET /wp-json/kkce/v1/<slug>` with pages, stable order, public or Application Password access.
- Date filters `modified_after`, `modified_before`, `published_after`; optional `lang` with Polylang.
- Fields: featured image URL, taxonomy terms, site and item language, translation group.
- Guide page with one tab per rule; FAQ on a user that can only read the feeds.
- Setting to hide the endpoint from the REST API index; warnings for unpublished feeds and author logins.
- Development: Insomnia route runner, Polylang integration suite.
### Changed
- Mapping editor redesigned: rows laid out as a form, collapsible, compact preview.
- Feed status shown as an on/off switch.
- A page of a feed costs the same queries whatever its number of items.
### Fixed
- Only the feed editor changes a feed slug; a restored feed never shares another feed's address.
- Preview: no "not known" error for fields of another post type, no unsaved-changes warning on item choice.
- 401 responses of the plugin routes include a Basic authentication challenge.

## [0.0.3] - 2026-11-19
### Added
- Post meta fields in the mapping: the keys in use, hidden keys listed apart, and any key typed by hand.
### Changed
- Development tooling: the end-to-end tests run with `composer run test:e2e`, like the other test levels.
- Development tooling: Docker test environments are named explicitly for integration (`inttest`) and end-to-end (`e2etest`) tests.

## [0.0.2] - 2026-11-15
### Changed
- Development tooling: CI checks whitespace, builds the ZIP, audits the npm tools and runs the integration tests on WordPress 7.1.4 and 6.4.
### Fixed
- A refused mapping shown again keeps its unfinished rows editable.
- A sensitive field typed in a template shows its confirmation box at once.

## [0.0.1] - 2026-11-15
### Added
- Plugin bootstrap, autoloader and lifecycle hooks.
- Feeds (private post type `kkce_feed`) with slug, one or more post types, access, active flag and items per page.
- Capability `kkce_read_export` for administrators.
- List of the feeds with endpoint, post types, access and status.
- Settings page: endpoints on/off, authentication for all feeds, maximum items per page, data removal on uninstall, diagnostics.
- Uninstall that removes the data only when the setting is on.
- Mapping of a feed: rules Field, Fixed value, Template, Lookup table and Condition, with exceptions per post type.
- Mapping editor in the feed editor, with live preview, help texts and save.
- Core WordPress fields as sources; author e-mail and login never offered.
- Sensitive fields exported only after an explicit confirmation, and marked in the list and the settings.
- Values normalized to the output type; content and excerpt as clean HTML; optional maximum text length.
- Every key always present in every item, `null` when there is no value.
- Italian translation.
- Development tooling: PHPCS, PHPStan level 8, PHPUnit, Git hooks with secret scan, CI with actions pinned to SHAs, AI context.
- Development tooling: integration tests in Docker (wp-env) and Playwright end-to-end tests with axe-core.
### Security
- No external HTTP request while content is rendered.

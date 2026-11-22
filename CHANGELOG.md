# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

Allowed TAGS: Added, Changed, Deprecated, Removed, Fixed, Security.


## [0.0.4] - Unreleased
### Added
- Feed endpoint `GET /wp-json/kkce/v1/<slug>` for published, enabled feeds.
- Pages of a feed with `page` and `per_page`, in a stable order by modified date (GMT) and ID.
- Authenticated feeds: read with an Application Password of a user with the capability `kkce_read_export`; items and totals are the same a visitor would get.
- Setting to show or hide the feed endpoint in the REST API index (shown by default).
- Date filters `modified_after`, `modified_before` and `published_after` (years 1000 to 9999), each one can be turned off in the settings.
- Warning in the editor and "Active, not published" in the list for an enabled feed that is not published.
### Changed
- A page of a feed costs the same database queries whatever the number of its items.
- The development note in the Plugins screen no longer says that feeds are not served.
### Fixed
- Choosing an item in the preview no longer triggers an unsaved-changes warning.
- 401 responses of the plugin's REST routes include a Basic authentication challenge when Application Passwords are available.

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

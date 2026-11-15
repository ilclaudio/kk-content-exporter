# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

Allowed TAGS: Added, Changed, Deprecated, Removed, Fixed, Security.

## [0.0.2] - Unreleased
No changes yet.

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

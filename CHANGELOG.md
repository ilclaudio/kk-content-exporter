# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

Allowed TAGS: Added, Changed, Deprecated, Removed, Fixed, Security.


## [1.0.1] - 2026-11-28
### Fixed
- The editor survives malformed rows in a refused mapping draft.
- A valid items-per-page value is saved as validated.
### Removed
- Bundled Italian translation: translations come from translate.wordpress.org.
### Security
- A refused mapping is kept as a draft only after decoding and re-encoding, never as submitted.

## [1.0.0] - 2026-11-27
### Added
- Guide for developers of a feed reader (`docs/`, repository only).
### Changed
- Italian translation reviewed for consistent terms.
- Development: stricter CI, monthly dependency updates, code clean-ups.
### Removed
- Development note on the Plugins screen.
### Fixed
- Post meta registered for every post type keep their declared type.
- Output type Automatic: a key has the same JSON type in every item.
- Very large page numbers or sizes return an empty page instead of an error.
### Security
- The preview warns when an author display name is the login name.

## [0.0.4] - 2026-11-25
### Added
- Feed endpoint `GET /wp-json/kkce/v1/<slug>`: pages, stable order, public or Application Password access.
- Date filters `modified_after`, `modified_before`, `published_after`; optional `lang` with Polylang.
- Fields: featured image URL, taxonomy terms, site and item language, translation group.
- Guide page with one tab per rule; setting to hide the endpoint from the REST API index.
- Warnings for unpublished feeds and author logins; FAQ on a read-only feed user.
- Development: Insomnia route runner, Polylang integration suite.
### Changed
- Mapping editor redesigned (form layout, collapsible rows, compact preview); feed status as an on/off switch.
- A page of a feed costs the same queries whatever its size.
### Fixed
- Only the feed editor changes a feed slug; a restored feed never takes another feed's address.
- Preview: no "not known" error for fields of another post type, no unsaved-changes warning on item choice.
- 401 responses of the plugin routes include a Basic authentication challenge.

## [0.0.3] - 2026-11-19
### Added
- Post meta fields in the mapping: keys in use, hidden keys listed apart, any key typed by hand.
### Changed
- Development: `composer run test:e2e`; Docker environments named `inttest` and `e2etest`.

## [0.0.2] - 2026-11-15
### Changed
- Development: CI checks whitespace, builds the ZIP, audits npm tools, runs integration tests on WordPress 7.1.4 and 6.4.
### Fixed
- A refused mapping shown again keeps its unfinished rows editable.
- A sensitive field typed in a template asks for confirmation at once.

## [0.0.1] - 2026-11-15
### Added
- Feeds (private post type `kkce_feed`): slug, one or more post types, access, active flag, items per page.
- Mapping rules Field, Fixed value, Template, Lookup table and Condition, with exceptions per post type.
- Mapping editor with live preview; list of the feeds; settings page with diagnostics.
- Core WordPress fields as sources; author e-mail and login never offered; sensitive fields only after confirmation.
- Values normalized to the output type; every key in every item, `null` without a value.
- Capability `kkce_read_export` for administrators; data removed on uninstall only when the setting is on.
- Italian translation.
- Development: PHPCS, PHPStan level 8, PHPUnit, Git hooks with secret scan, CI with SHA-pinned actions, wp-env and Playwright tests.
### Security
- No external HTTP request while content is rendered.

# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

Allowed TAGS: Added, Changed, Deprecated, Removed, Fixed, Security.

## [Unreleased]

### Added

- Private post type `kkce_feed` for the feed configurations: not public, not exposed in REST, managed
  by administrators only.
- Configuration meta of a feed (source post type, access, active flag, items per page): sanitized on
  every write, hidden from REST and editable by administrators only. New feeds are public-access,
  disabled, with 50 items per page. A stored access value that is not exactly `public` or `authenticated`
  is treated as authenticated.
- Capability `kkce_read_export`, granted to administrators on activation (idempotent) and kept on
  deactivation.
- The list of the feeds shows the slug, the source post type, the access and the status of every feed.
  Italian translation of the new strings.
- Editor of a feed: a "Feed settings" box with the slug, the source post type, the access, the active flag
  and the items per page. Saving requires a nonce and the capability to edit the feed. A value that is not
  valid keeps its previous value and is reported in a notice; the other values are saved. A slug that is
  reserved, malformed or used by another feed is refused. A feed can be enabled only with a slug and a source
  post type. Quick Edit is removed from the feeds. A feed restored from the trash starts disabled, and a feed
  cannot be enabled on a slug that is malformed, reserved or used by another feed.

### Changed

- The class autoloader accepts only plain identifiers in every part of a class name.
- Development tooling: PHPStan now runs at level 8 (was 6).
- Development tooling: the secret scan of the pre-commit hook recognizes more credential formats and is
  covered by a test.
- Development tooling: the GitHub Actions of the CI are pinned to commit SHAs.

## [0.0.1] - 2026-11-06

### Added

- Plugin bootstrap: main file with the complete header, `KKCE_VERSION`, autoloader of the `KKCE`
  namespace, activation, deactivation and uninstall entry points.
- Internationalization: English source strings, `.pot` template and Italian (`it_IT`) translation.
- Development tooling: PHPCS (WordPress Coding Standards, PHPCompatibilityWP), PHPStan level 6,
  PHPUnit unit (Brain Monkey) and integration (WordPress test suite) suites, Git hooks.
- AI collaboration context in `AGENTS.md`, `CLAUDE.md` and `AGENTS/`.

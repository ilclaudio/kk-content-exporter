# Changelog

All notable changes to KK Content Exporter are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
follows [Semantic Versioning](https://semver.org/). The version of the first entry must match
`VERSION.txt`, the plugin header, `KKCE_VERSION` and the `Stable tag` of `readme.txt`
(checked by `KKCE_Release_Version_Unit_Test`).

## [Unreleased]

### Added

- Private post type `kkce_feed` for the feed configurations: not public, not exposed in REST, managed
  by administrators only.

## [0.0.1] - 2026-11-06

### Added

- Plugin bootstrap: main file with the complete header, `KKCE_VERSION`, autoloader of the `KKCE`
  namespace, activation, deactivation and uninstall entry points.
- Internationalization: English source strings, `.pot` template and Italian (`it_IT`) translation.
- Development tooling: PHPCS (WordPress Coding Standards, PHPCompatibilityWP), PHPStan level 6,
  PHPUnit unit (Brain Monkey) and integration (WordPress test suite) suites, Git hooks.
- AI collaboration context in `AGENTS.md`, `CLAUDE.md` and `AGENTS/`.

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
- Settings page (Content Exporter > Settings): endpoints on/off, authentication required for all feeds,
  maximum items per page (100 by default, no upper limit; a feed cannot exceed it) and removal of the data
  on uninstall. The page reports a REST API blocked for anonymous requests and authenticated feeds on a
  site without Application Passwords. Italian translation of the new strings.
- Uninstall: when the setting is on, deleting the plugin removes the feeds with their settings, the plugin
  settings and the capability `kkce_read_export`; otherwise nothing is removed.
- A feed reads one or more post types: the editor shows one checkbox per public post type, and the list of
  the feeds shows all of them. A list with a post type that is not available is refused as a whole and the
  previous one is kept; a feed can be enabled only with at least one post type. Feeds saved with a single
  post type keep working. A saved post type that is no longer available (for example its plugin was
  deactivated) stays checked, marked as not available, and is kept until it is unchecked. Italian
  translation of the new strings.
- The editor of a feed shows the full address of the feed under the slug field and updates it while the slug
  is typed (it follows the permalink settings of the site); a slug that cannot be used is reported at once.
  The editor warns before leaving the page with unsaved changes in the feed settings.
- Format of the mapping of a feed (versioned JSON: output key, rule kind `field` or `fixed`, expected type)
  with its validation and the hidden meta `_kkce_mapping`. A mapping that is not valid
  is never stored. A source whose name looks sensitive (a password, a token, a key of a service, an e-mail
  address) must be confirmed explicitly in its row, otherwise the mapping is not stored. Not used by the
  editor or by any endpoint yet.
- Catalog of the sources a mapping can use, with the core fields of WordPress common to every post type:
  ID, slug, title, content, excerpt (the one written by hand), permalink, short link, publish and modified
  dates (UTC), author ID, display name and archive URL (to confirm explicitly), post type slug and label.
  The author e-mail and login are never offered. Italian translation of the new strings.
- Evaluation of the mapping rules `field` (a chain of sources per post type; the first source with a value
  wins, and `0` or `false` are values) and `fixed`, and export of an item as an anonymous visitor, so a
  preview or an authenticated feed never shows what only a logged-in user sees. Not used by any screen or
  endpoint yet.
- Normalization of the exported values to the type of their row: plain text (tags removed, entities
  decoded), HTML, number, boolean, date in UTC ISO 8601 (dates without time kept as `YYYY-MM-DD`), absolute
  http or https URL, list. With the `auto` type a key keeps the same JSON type for every item. A value that
  cannot be converted gives `null`.
- The mapping is a contract: every exported item has every key of the mapping, in the same order, with
  `null` when there is no value (an empty text stays `""`, an empty list `[]`).
- Content and excerpt are exported as clean HTML: blocks rendered without their comments, paragraphs for
  content written with the classic editor, relative links and images made absolute. Shortcodes run only
  when the row asks for it (otherwise they are removed), embeds are not resolved (no request to external
  sites), and a raw variant gives the stored text. Other plugins' content filters are not run.
- Admin-only REST routes for the mapping editor (`kkce-admin/v1`): the catalog of the sources for the
  post types of a feed, and the preview of a mapping, saved or not, on a published item. Only users who
  can edit feeds reach them; the preview shows what a visitor would get, and never a draft, private or
  password-protected item, or an item of another post type.
- "Mapping" box in the feed editor (first part): rows with the output key, the field to read (grouped by
  post type), the output type and what to do when there is no value; rows can be added, removed and moved,
  by keyboard too. The fields follow the post types checked in the settings box. The mapping is not saved
  yet, and without JavaScript the saved mapping stays unchanged.
- "Mapping" box, second part: a row reads a chain of fields (the first one with a value wins, the next ones
  are fallbacks), can use a different chain for a single post type, or writes a fixed value (also different
  per post type). Content and excerpt offer "Run the shortcodes" and "Raw content" with their warnings, and
  fields that need a confirmation show a warning.
- Rule "Template" in the mapping: a text that joins fields and words, with the fields between braces
  (`{core.title} – {meta.place}`), fallbacks inside a placeholder (`{a|b|"text"}`), a different template per
  post type, plain text or HTML output, and an "Insert field" button. A key whose placeholders all have no
  value is written with null.
- Rule "Lookup table" in the mapping: reads a field (a different one per post type if needed) and
  translates its value through a table of lines "value → fixed text or field", compared exactly; when no
  line matches, a default is used (nothing, a fixed text, a field or the value read). Lists cannot be read,
  and the same value cannot appear twice in a table.
- Rule "Condition" in the mapping: one check on one field (is empty, is not empty, is equal to, is different
  from, is one of, contains) and an output for true and one for false (nothing, a fixed text, a field or the
  value checked). "Contains" looks for a text inside the value, or for an element of a list, such as the
  categories; the editor offers lists only for "is empty", "is not empty" and "contains".
- The "Feed settings" and "Mapping" boxes explain what they are for; the second one also explains how the
  rows work.
- Live preview in the "Mapping" box, shown as soon as there is a row: the JSON of one item for each checked post type, computed with the rules
  of the box (saved or not) as a visitor would get it, updated shortly after every change. Another published
  item can be chosen from the latest ones; errors of the mapping are listed by row. On a wide screen the
  preview stays beside the rules.
- A filter above the source post types when there are more than ten of them.
- The feed editor asks for confirmation before leaving with unsaved changes in the mapping too.

### Changed

- Development tooling: disable the PHPCS meta-query performance heuristic centrally and remove its
  redundant inline suppressions from integration tests.
- The list of the feeds shows the relative address of the endpoint (for example `/wp-json/kkce/v1/news`, the
  absolute one in the tooltip) instead of the slug, and no address for a feed without a usable slug.
- The slug and at least one source post type of a feed are required: an empty slug is no longer taken from
  the title, it is reported and the feed stays disabled.
- The class autoloader accepts only plain identifiers in every part of a class name.
- Development tooling: PHPStan now runs at level 8 (was 6).
- Development tooling: the secret scan of the pre-commit hook recognizes more credential formats and is
  covered by a test.
- Development tooling: the GitHub Actions of the CI are pinned to commit SHAs.

### Fixed

- Malformed feed slugs are refused before text sanitization can silently rename them; the saved slug
  is kept and the editor reports the existing format error.
- Exported blocks and shortcodes use the current item's post context, with the previous user and loop
  globals restored even when rendering fails.
- Plain text keeps literal comparison signs; integer text outside the PHP integer range and dates
  containing null bytes give `null`.
- Image `srcset` URLs keep embedded commas, including data URLs, while relative candidates become absolute.
- Scanner tests prefer Git Bash on Windows and accept a `KKCE_TEST_BASH` executable override.
- Integration tests keep uploads in the repository cache instead of the WordPress framework checkout.

### Security

- Block WordPress HTTP API requests throughout content rendering, including dynamic blocks and opted-in
  shortcodes, and remove the temporary guard even when rendering fails.

## [0.0.1] - 2026-11-06

### Added

- Plugin bootstrap: main file with the complete header, `KKCE_VERSION`, autoloader of the `KKCE`
  namespace, activation, deactivation and uninstall entry points.
- Internationalization: English source strings, `.pot` template and Italian (`it_IT`) translation.
- Development tooling: PHPCS (WordPress Coding Standards, PHPCompatibilityWP), PHPStan level 6,
  PHPUnit unit (Brain Monkey) and integration (WordPress test suite) suites, Git hooks.
- AI collaboration context in `AGENTS.md`, `CLAUDE.md` and `AGENTS/`.

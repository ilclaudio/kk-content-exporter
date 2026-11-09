# PROJECT.md


## Project Overview

KK Content Exporter is a WordPress plugin, installed on a source site, that exposes published
content as configurable, read-only JSON feeds for an external importer. An administrator configures
one or more feeds; each feed reads one or more post types and maps their fields freely to JSON keys.
The plugin queries WordPress directly: the post types do not need `show_in_rest`, and the native REST
API is not changed.

The plugin is only an exporter. The consumer (the importer) is responsible for upserts, image
downloads, timeouts, import logs and scheduling.


## Identity and requirements

| Property | Value |
|---|---|
| Name | KK Content Exporter |
| Slug and text domain | `kk-content-exporter` (also the folder name) |
| Prefix | `KKCE_` for constants; `kkce_` for functions, options, meta, hooks, capabilities, post types; `KKCE\` namespace for classes |
| License | GPL-3.0-or-later (`LICENSE`) |
| Requirements | WordPress 6.4 or later, PHP 8.3 or later |
| Current version | `VERSION.txt` (see *Version alignment* in `AGENTS/GIT_WORKFLOW.md`) |
| Distribution | Public Git repository and the official WordPress.org Plugin Directory |
| Languages | English source strings; Italian (`it_IT`) translation included |


## Scope of version 1.0.0 (summary)

- Feeds stored in the private administrative post type `kkce_feed` (not exposed in REST): title for the
  name, post meta for mapping and options; no revisions (the post type supports only the title, and core
  revisions do not store post meta): a restore of the configuration is not a requirement.
- One public, read-only REST endpoint per feed: `GET /wp-json/kkce/v1/<feed-slug>`; slug unique, feed
  can be enabled or disabled; new and imported feeds start disabled.
- Post types per feed: one or more (one is the particular case). Items of different types share the
  response, its order and its pagination; a field with the post type of the item is always selectable.
- Mapping rows, starting from the output: free JSON key (proposed equal to the source name), a **value
  rule** with a declared kind, expected type (`auto`, string, HTML, number, boolean, date/time, URL,
  simple list) and missing-value policy (omit by default, or `null`). The mapping format is versioned and
  has one evaluation point, shared by the preview and the endpoint. Rule kinds of V1: Field (a source per
  post type, with a fallback chain: the first value that is present wins, so `0` and `false` stay values),
  Fixed value (also per post type), Template (text with placeholders, for concatenation), Lookup table
  (value to output, the switch) and Condition (simple if-then-else). Rules are structures filled in by the
  admin interface, not text to parse: there is no expression language in V1.
- Sources: WordPress fields shared by every post type (ID, title, content, excerpt, permalink,
  publish/modified dates, public author attributes) listed once; per post type, explicitly selected post
  meta, simple ACF and CMB2 fields, taxonomies and terms (a JSON list of term names), featured image (the
  absolute URL of the original image; ID, alt and modified date may be added later), language (fixed per feed
  or Polylang). Fields of different post types are never merged by name:
  the administrator decides when they correspond. Rules can only use these selectable sources.
  Repeater, flexible content, groups, relationships and serialized structures are out of V1.
- Response: `{ "items": [...], "pagination": {...}, "meta": {...} }`; dates in ISO 8601 UTC (date-only
  fields keep `YYYY-MM-DD`); pagination `page` / `per_page` (default 50 per feed; maximum set in the plugin settings, 100 by default, no ceiling); stable order by
  modified date GMT and ID; date filters `modified_after`, `modified_before` and `published_after`,
  each of which can be turned off in the settings (a disabled filter gives HTTP 400).
- Access: public (anonymous, published data only) or authenticated (HTTPS + Application Password of a
  technical user with the dedicated capability `kkce_read_export`).
- Never exported: drafts, private or password-protected content, unmapped meta, sensitive keys
  (passwords, tokens, sessions, capabilities), author e-mail or login. The plugin never writes to
  source content and makes no external request.
- Source post types: public ones only (`public => true`), `attachment` excluded.
- Settings page (Settings API), submenu "Settings" of the Content Exporter menu, option `kkce_settings`:
  master switch of the endpoints, authentication required for every feed, maximum items per page (100 by
  default, no ceiling; a feed cannot exceed it), removal of data on uninstall (off by default). Log retention
  is added in Phase 3 together with the log. The page also reports what could keep the feeds from working
  (REST API blocked for anonymous requests, Application Passwords unavailable); it changes nothing by itself.
- Uninstall: nothing is removed unless the setting is on; then feeds with their meta, the settings and the
  capability `kkce_read_export` (roles and users) are deleted.
- Admin interface: native WordPress markup and vanilla JavaScript, no build step.
- Multisite: network activation is not supported in V1 (single-site operation).

Out of V1 (Phase 6 of the plan): taxonomy/meta filters, cache with ETag/Last-Modified, WPML, an
endpoint outside `/wp-json/`, cursors, network activation, and an Expression rule kind (a real expression
language), which is added only for a concrete case that the other rule kinds cannot solve.

Plan phases: 1 foundations (done), 2 multiple post types and the base of the rule mapping, 3 endpoints,
4 additional rule kinds, 5 release 1.0.0, 6 later evolutions.


## Optional integrations (read-only)

ACF, CMB2 and Polylang are **optional** and never required: the plugin may *read* simple fields of
other post types when those plugins are active, and must work without them. The plugin never uses
ACF or CMB2 for its own fields or meta: its own configuration (for example of `kkce_feed`) uses core
APIs (`register_post_meta()`, native meta boxes, the Settings API). See *Optional integrations* in
`AGENTS/CODING_STANDARDS.md`.


## Private reference documents

Available only on the maintainer machine through the `DEV/` symlink (ignored by Git, never copied
into public documentation):

- `DEV/TODO/Plugin_WordPress_Export.md`: functional specification (what the plugin does).
- `DEV/TODO/TODO_KK_Content_Exporter_Plan.md`: development plan in phases (how and when).
- `DEV/TODO/README.md`: index of the operational plans (read at bootstrap); each plan is a
  `DEV/TODO/TODO_<Name>.md` file (rules: *Operational plans* in `AGENTS/AI_BEHAVIOR.md`).
- `DEV/PROCEDURES/README.md`: index of the release and publication procedures (`PROC_<Name>.md`).

When the specification and the code disagree, stop and ask the operator which one is right.


## Development site

The maintainer runs a Local (by Flywheel) site `kkce` at http://kkce.local with `WP_DEBUG` enabled;
the plugin folder is its working copy. Integration tests never use the site database (see
`tests/integration/README.md`).


## License

See `LICENSE` (GNU General Public License v3.0 or later).

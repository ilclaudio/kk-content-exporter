# PROJECT.md


## Project Overview

KK Content Exporter is a WordPress plugin, installed on a source site, that exposes published
content as configurable, read-only JSON feeds for an external importer. An administrator chooses a
post type, configures one or more feeds and maps the fields of the content freely to JSON keys. The
plugin queries WordPress directly: the post type does not need `show_in_rest`, and the native REST
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
- Mapping rows: source, free JSON key (proposed equal to the source name), expected type
  (`auto`, string, HTML, number, boolean, date/time, URL, simple list), missing-value policy (omit by
  default, or `null`), fixed values.
- Sources: WordPress fields (ID, title, content, excerpt, permalink, publish/modified dates, public
  author attributes), explicitly selected post meta, simple ACF and CMB2 fields, taxonomies and terms,
  featured image (ID, absolute URL, alt, modified date), language (fixed per feed or Polylang).
  Repeater, flexible content, groups, relationships and serialized structures are out of V1.
- Response: `{ "items": [...], "pagination": {...}, "meta": {...} }`; dates in ISO 8601 UTC (date-only
  fields keep `YYYY-MM-DD`); pagination `page` / `per_page` (default 50 per feed; maximum set in the plugin settings, 100 by default, no ceiling); stable order by
  modified date GMT and ID; incremental filter `modified_after`.
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

Out of V1 (Phase 4 of the plan): taxonomy/meta filters, cache with ETag/Last-Modified, WPML, an
endpoint outside `/wp-json/`, cursors.


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

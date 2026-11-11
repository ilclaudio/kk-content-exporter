# ARCHITECTURE.md


## Architecture Notes

KK Content Exporter is a classic WordPress plugin (no blocks, no build step). PHP classes live in
the `KKCE` namespace under `includes/` and are loaded by the plugin's own autoloader; Composer is a
development tool only and is never shipped. The code contains the bootstrap, the private feed post type with its configuration meta, the
feed list, the feed editor, the settings page, the uninstall cleanup, the mapping engine (format, validation,
core sources, evaluation, normalization, rendering) and the admin REST routes of the mapping editor: the other
features of the plan (mapping interface, feed endpoints, log) are not implemented yet. Update this file in the same change that adds them.


## Key Directories and Files

| Path | Content |
|---|---|
| `kk-content-exporter.php` | Main file: plugin header, `ABSPATH` guard, constants, autoloader, lifecycle hooks |
| `uninstall.php` | Uninstall routine (guarded by `WP_UNINSTALL_PLUGIN`): loads the autoloader and calls `KKCE\Uninstaller::run()` |
| `includes/` | First-party classes of the `KKCE` namespace (`class-*.php`) |
| `includes/class-autoloader.php` | `KKCE\Autoloader`: maps `KKCE\<Sub>\<Class_Name>` to `includes/<sub>/class-<class-name>.php` |
| `includes/class-plugin.php` | `KKCE\Plugin`: registers the hooks, loads the text domain; `Plugin::REST_NAMESPACE` (`kkce/v1`) and `Plugin::feed_url()`, the only place that builds the address of a feed (empty for a slug that cannot serve a feed) |
| `includes/class-feed-post-type.php` | `KKCE\Feed_Post_Type`: registers the private post type `kkce_feed` (administrators only, no REST), its capabilities (all mapped to `manage_options`) and disables a feed restored from the trash |
| `includes/class-feed-meta.php` | `KKCE\Feed_Meta`: keys, registration (`register()`, on `init`) and sanitizers of the configuration meta of a feed (`_kkce_post_type`, multiple, one row per source post type; `_kkce_mapping`, JSON text; `_kkce_access`, `_kkce_active`, `_kkce_per_page`), the readers `post_types()` and `mapping()` and `save_mapping()` (goes through `wp_slash()`) |
| `includes/output/class-mapping.php` | `KKCE\Output\Mapping`: pure. Format of the mapping (version 1): vocabulary (kinds, output types, source groups; rows have no missing-value policy), limits, `decode()` of the JSON text, `suggest_key()` |
| `includes/output/class-mapping-validator.php` | `KKCE\Output\Mapping_Validator`: pure. `validate()` lists every error of a decoded mapping (`row`, `field`, `code`), `normalize()` gives the canonical form. Refuses a sensitive source that the row does not list in `confirmed`. Does not check that a source exists (the catalog does, at save time) |
| `includes/output/class-sensitive-keys.php` | `KKCE\Output\Sensitive_Keys`: pure. Whether a name (or the name of a source `group.name`) looks sensitive: split into words, compared with a list of words and of adjacent pairs. Judges the name, never the value |
| `includes/output/class-rule-evaluator.php` | `KKCE\Output\Rule_Evaluator`: pure. The only place that computes the value of a mapping row (`field`: chain of the post type of the item, which replaces the general `*`, first present value wins, `0`/`false` are values, empty values pass on; `fixed`: value of the post type or `*`; other kinds give no value) and the object of an item (`evaluate_mapping()`: values normalized to the type of the row, `auto` = common declared type of all the sources of the row, otherwise text; the key of every row is always written, in the order of the rows, with `null` when the row has no value: a source that does not exist, a value that cannot be converted, an unconfirmed sensitive source, an unknown kind; an empty text or list keeps `""` or `[]`). Never reads an unconfirmed sensitive source. Sources are read through a function given by the caller, with the options of the row (null = does not exist) |
| `includes/output/class-item-exporter.php` | `KKCE\Output\Item_Exporter`: exports one item with a mapping, reading every source once from the provider of its group; computes as an anonymous visitor (current user set to 0 for the whole item, always restored in `finally`) |
| `includes/output/class-normalizer.php` | `KKCE\Output\Normalizer`: pure. Converts a value to the output type of its row (`string` plain text without tags and with entities decoded, `html`, `number`, `boolean`, `datetime` in UTC ISO 8601 or `YYYY-MM-DD`, `url` absolute http/https, root-relative made absolute with the origin of the site, `list`); a value that cannot be converted gives no value and `not_convertible`; `common_type()` resolves `auto` |
| `includes/output/class-content-renderer.php` | `KKCE\Output\Content_Renderer`: controlled rendering of content and excerpt, never the full `the_content` filter: `do_blocks()` (no block comments), `wpautop()` only for content without blocks (never for the excerpt), shortcodes only with the row option `shortcodes` (otherwise the registered ones are removed), no embed (oEmbed would be an external request), absolute URLs; `raw` gives the stored text |
| `includes/output/class-url-absolutizer.php` | `KKCE\Output\Url_Absolutizer`: relative `href`, `src` and `srcset` made absolute with `WP_HTML_Tag_Processor` and `WP_Http::make_absolute_url()` against the permalink of the content; absolute, protocol-relative, anchors and other schemes unchanged |
| `includes/sources/class-source.php` | `KKCE\Sources\Source`: pure value object describing a source (identifier `group.name`, label, post type or common, declared type, accepted options, whether it needs a confirmation) |
| `includes/sources/class-catalog.php` | `KKCE\Sources\Catalog`: pure. Sources of a feed for its post types (common ones once, then those of each post type), `find()` of a source for a post type or `*`, `suggested_rows()` (the `type` row in feeds with two or more post types) |
| `includes/sources/class-catalog-factory.php` | `KKCE\Sources\Catalog_Factory`: `for_post_types()` builds the `Catalog` of a feed from the providers (core now); the only place that knows which providers exist |
| `includes/rest/class-admin-routes.php` | `KKCE\Rest\Admin_Routes`: admin-only REST routes of the mapping editor in the namespace `kkce-admin/v1` (`GET /catalog`, `POST /preview`), described in *Public Endpoints and Hooks* |
| `includes/sources/class-core-provider.php` | `KKCE\Sources\Core_Provider`: the core sources common to every post type and their values (stored title, content and excerpt through `Content_Renderer` with the options of the row, GMT dates, author ID, display name and archive URL only; never e-mail or login) |
| `includes/admin/class-feed-list.php` | `KKCE\Admin\Feed_List`: columns of the `kkce_feed` list (relative endpoint address computed by `rest_url()`, source, access, status), read through the sanitizers and escaped |
| `includes/class-settings.php` | `KKCE\Settings`: option `kkce_settings` (defaults, safe reading, processing of the form: pure; `get()` and `sanitize_option()` are the only WordPress calls) |
| `includes/class-uninstaller.php` | `KKCE\Uninstaller`: deletes feeds (every status, with meta), the option and the capability when the setting asks for it |
| `includes/admin/class-settings-page.php` | `KKCE\Admin\Settings_Page`: submenu page built with the Settings API, and the diagnostics |
| `includes/admin/class-feed-editor.php` | `KKCE\Admin\Feed_Editor`: the "Feed settings" meta box of the `kkce_feed` editor (opened by a text that says what a feed is and what the box is for) and its saving (nonce per feed, `edit_post` capability, slug set through `wp_insert_post_data`, meta written on `save_post_kkce_feed`, errors shown once in a notice), the live address of the feed under the slug field, the filter of the post types (printed hidden above `FILTER_THRESHOLD`, 10, post types and shown by the script), `is_feed_screen()` (the edit and create screens of a feed, shared with `Mapping_Box`), and the loading of `assets/js/feed-editor.js` |
| `includes/admin/class-mapping-box.php` | `KKCE\Admin\Mapping_Box`: the "Mapping" meta box (below the settings box), opened by a text that says what the mapping is and a `<details>` section "How the rows work" (key, rules, output type, preview): a container with the initial data of the mapping editor in an escaped `data-kkce-mapping` attribute (saved canonical mapping, `Admin_Routes::describe_catalog()` of the saved post types, catalog, preview and items routes, and the rules of the validator: maximum rows, key length, `Mapping_Validator::KEY_FORMAT`, `MAX_CHAIN`, `VALUE_MAX_LENGTH`, so the script copies none of them), a warning that the mapping is not saved yet (removed when step 11 of the plan saves it), a `noscript` message, and the loading of `assets/js/feed-mapping.js` and `assets/css/feed-mapping.css` with their translations. It prints no mapping field |
| `assets/js/feed-mapping.js` | Vanilla JavaScript of the mapping editor (handle `kkce-feed-mapping`, footer, depends on `wp-api-fetch` and `wp-i18n`, translated through `wp_set_script_translations()`): one `fieldset` per row (output key, rule `field` or `fixed` in a native `select`, output type, move up/down, remove). A `field` row has the general chain (`*`) and optional exceptions for single post types ("Different for"), each a chain of native `select` with `optgroup` (add fallback, move, remove); a `fixed` row has a text value per scope, converted by the output type of the row. The options of the fields (`raw`, `shortcodes`) appear only when a field of the row accepts them, with their warnings (`raw` disables `shortcodes`); a source that needs a confirmation shows a warning (the confirmation itself is asked when saving). Changing the rule keeps the settings of the other rule in memory until the page is left; only the chosen rule is written. The hidden field gets only what was chosen: empty places of a chain, empty chains and empty fixed values are left out, focus kept on the edited control, changes announced in an `aria-live` region, key checks shown as help only. It creates the hidden field `kkce_feed[mapping]` (JSON text): without JavaScript the field is not sent. A new catalog is asked to `GET /catalog` when the checked post types change. The proposed rows of the catalog fill an empty mapping until it is touched; the key of a source is proposed while the key is empty or still the proposed one. Rows it cannot edit (a rule of a later version, or a damaged shape) are kept unchanged. Labels used by several controls are constants, so every translatable string has one translators comment. The DOM is built with `createElement` and `textContent` only |
| `assets/js/feed-mapping.js` (preview panel) | Inside the mapping editor, `createPreview()`: shown only when the mapping has at least one row (without rows the rules use the whole width); the JSON of one item per checked post type, from `POST /preview` with the mapping of the hidden field (saved or not); computed again 600 ms after the last change and with "Refresh"; one request per post type, so each type can use its own item, chosen from `GET /items` (default: the latest modified item; a chosen item that is no longer published goes back to the latest). Errors of the mapping (`kkce_invalid_mapping`) are listed as sentences (row, key, setting, reason) and announced; the JSON is shown, not announced. Choosing the item is not an unsaved change of the page (the event does not reach the box). Diagnostics (fields always empty, failed conversions) are a later step |
| `assets/css/feed-mapping.css` | Layout of the rows of the mapping editor and of the preview: two columns (rules, preview kept in view with `position: sticky`) when the preview is shown (`.kkce-has-preview`) and the box is at least 900 px wide (container query on `.kkce-mapping`), one column otherwise (colors and focus from the WordPress admin) |
| `assets/js/feed-editor.js` | Vanilla JavaScript of the feed editor, loaded only on the edit and create screens of `kkce_feed` (handle `kkce-feed-editor`, footer, version `KKCE_VERSION`): updates the address of the feed while the slug is typed (slug rules and messages printed by the server in the box, nothing duplicated) and filters a long list of post types (it sets `style.display`, because the admin style gives those labels `display: inline-block`, which wins over the `hidden` attribute), and asks for confirmation before leaving with unsaved changes in the settings and mapping boxes (the mapping editor signals its changes with a bubbling `change` event). Progressive enhancement: the box works without it. Shipped in the release ZIP and required by `kkce_release_missing_files()` |
| `includes/class-feed-form.php` | `KKCE\Feed_Form`: pure processing of the submitted editor values (which value is saved, which error is reported) |
| `includes/class-feed-validator.php` | `KKCE\Feed_Validator`: pure validation (no WordPress call) of the slug, the items per page, the access and the list of source post types; returns error codes, the editor writes the messages |
| `languages/` | `kk-content-exporter.pot`, `kk-content-exporter-it_IT.po` / `.mo`, and the generated JSON translations of the admin scripts (`kk-content-exporter-<locale>-<md5>.json`) |
| `readme.txt` | WordPress.org readme (Stable tag, requirements, changelog) |
| `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md` | Repository documentation |
| `VERSION.txt` | Current version (see *Version alignment* in `AGENTS/GIT_WORKFLOW.md`) |
| `bin/` | Development command-line scripts: `php-lint.php`, `build-zip.php`, `svn-prepare.php`, `release-functions.php`; never shipped |
| `.distignore` | Files and folders left out of the release ZIP |
| `build/` | Generated release folder, ZIP and SVN working copy (ignored by Git) |
| `tests/unit/`, `tests/bootstrap-unit.php`, `phpunit.xml` | Unit tests (PHPUnit + Brain Monkey, no WordPress) |
| `tests/integration/`, `tests/bootstrap-integration.php`, `phpunit-integration.xml` | Integration tests (WordPress test suite, dedicated database) |
| `tests/phpstan/` | PHPStan bootstrap (runtime constants) |
| `.githooks/` | Git hooks (pre-commit, commit-msg) |
| `phpcs.xml.dist`, `phpstan.neon.dist` | Coding standards and static analysis configuration |
| `AGENTS/`, `AGENTS.md`, `CLAUDE.md` | AI collaboration context |
| `DEV/` | Symlink to private working material; ignored by Git, never shipped |

`includes/admin/` holds the classes of the administration screens (the feed list, the editor with its settings and mapping boxes, and the settings page).
`includes/output/` holds the logic of the output: the mapping format and its validation, the sensitive names, the
rule evaluator (pure) and the export of an item (the link with WordPress); the normalizer and the serialization later. `includes/sources/` holds the sources a mapping can read: the catalog and the providers (core now; meta,
taxonomies, image, language, ACF and CMB2 later). `includes/rest/` holds the REST routes: the admin routes of
the mapping editor now, the feed endpoints in Phase 3.


## Plugin Lifecycle

1. **Load** (`kk-content-exporter.php`): exits without `ABSPATH`; defines `KKCE_VERSION`,
   `KKCE_PLUGIN_FILE`, `KKCE_PLUGIN_DIR`; registers `KKCE\Autoloader`; registers activation and
   deactivation hooks; hooks `KKCE\Plugin::boot()` on `plugins_loaded`.
2. **Boot** (`plugins_loaded`): `Plugin::boot()` only registers hooks and is idempotent. No
   translatable string is evaluated before `init` (WordPress 6.7+ reports translations loaded too early).
3. **Init** (`init`): `Plugin::load_textdomain()` loads the bundled translations. The post type `kkce_feed` is registered by
   `Feed_Post_Type::register()` and its configuration meta by `Feed_Meta::register()` (both on `init`).
   WordPress returns stored meta as strings (`'1'` or `''` for the flag, `'20'` for the number): readers convert
   them with `sanitize_active()` and `sanitize_per_page()`. The source post types are a multiple meta
   (`single => false`, no default, no serialization, so feeds can be found by post type with a `meta_query`):
   read them with `Feed_Meta::post_types()`, which gives a clean list without empty values or duplicates
   (a feed saved with one row gives a list of one); the editor deletes and rewrites the rows when the list changes.
   The mapping is the meta `_kkce_mapping`, a JSON text with a version (format in `Output\Mapping`). The sanitizer
   checks it with `Output\Mapping_Validator` and stores the canonical form, or an empty string when it is not valid
   (a sanitizer cannot refuse a value; the editor reports the errors before saving); `Feed_Meta::mapping()` checks it again
   on every read, so a damaged stored value gives an empty mapping. WordPress removes the backslashes of a meta value:
   write it with `Feed_Meta::save_mapping()`, which goes through `wp_slash()`. The text is ASCII only (`wp_json_encode()`). `sanitize_access()` fails closed: only the exact value
   `public` opens a feed, any other stored value gives `authenticated` (a feed without the meta gets the registered
   default `public`). REST routes are registered on `rest_api_init` (`Rest\Admin_Routes::register()`).
4. **Activation** (`Plugin::activate()`): grants the capability `kkce_read_export` (`Plugin::READ_EXPORT_CAPABILITY`)
   to the administrator role. It is idempotent, creates no content and does not flush rewrite rules (the
   `kkce_feed` post type has none). A role added later does not receive the capability until the plugin is
   activated again.
5. **Deactivation** (`Plugin::deactivate()`): never deletes the feed configuration or the capability.
6. **Uninstall** (`uninstall.php`): the only place where data is removed, and only when the setting "Delete all
   the data of the plugin when the plugin is deleted" is on (off by default; a missing or damaged value means
   keep). The plugin is not loaded at that time, so the file registers the autoloader and the feeds are found by
   the name of the post type. Feeds in every status (trash included) are deleted with their meta, then the option
   `kkce_settings` and the capability `kkce_read_export` from every role and every user who received it directly.
   Multisite network activation is not supported in V1.

Add new hooks in `Plugin::boot()` (or in a class it wires), never as side effects of including a file.


## Conventions and Intentional Patterns

- Namespace `KKCE\` with WordPress-style class and file names (for example `KKCE\Feed_Repository` in
  includes/class-feed-repository.php); global names are prefixed `kkce_` / `KKCE_`. This is a deliberate
  decision (2026-11-07): no PSR-4, no Composer autoloader at runtime, no `vendor/` in the package (see
  *Code structure* in `AGENTS/CODING_STANDARDS.md`).
- Pure logic (mapping, normalization, validation) lives in classes that call no WordPress function;
  WordPress access is confined to thin wiring classes (hooks, source providers, REST controllers).
- Hook callbacks are static methods referenced as `array( Class::class, 'method' )`, so tests can check
  them with `has_action()` / `has_filter()`.
- Every PHP file in `includes/` starts with `defined( 'ABSPATH' ) || exit;`.
- The plugin's own data uses core APIs only (custom post type, `register_post_meta()`, native meta
  boxes, Settings API); ACF, CMB2 and Polylang are read only through guarded, optional integrations.
- Text domain loading on `init` with `load_plugin_textdomain()` is intentional (see *Internationalization*
  in `AGENTS/CODING_STANDARDS.md`): do not remove it to silence Plugin Check.


## Public Endpoints and Hooks

No public endpoint, shortcode, cron job or WP-CLI command yet, and the plugin offers no hook of its own.
Admin hooks used (all in `Plugin::boot()`): `rest_api_init` (`Rest\Admin_Routes::register()`), `manage_kkce_feed_posts_columns`, `manage_kkce_feed_posts_custom_column`,
`post_row_actions` (removes Quick Edit from the feeds, because it could change a slug without validation),
`add_meta_boxes_kkce_feed` (`Feed_Editor` and `Mapping_Box`), `wp_insert_post_data`, `save_post_kkce_feed`, `admin_notices`, `untrashed_post`,
`admin_enqueue_scripts` (`Feed_Editor::enqueue_assets()` and `Mapping_Box::enqueue_assets()`), `admin_menu` and `admin_init` (the settings page)
(`Feed_Post_Type::disable_restored_feed()`: a feed restored from the trash starts disabled).
`wp_insert_post_data` runs for every post type and returns at once for anything but `kkce_feed`.

Rules of the editor: a field with an invalid value keeps its previous value and is reported in a notice, the
submitted slug is validated, never repaired: `filter_var()` with `FILTER_VALIDATE_REGEXP` and `Feed_Validator::SLUG_PATTERN`
gives it back unchanged or false, so malformed or non-string input gives the format error and keeps the stored slug
(no text sanitizer can turn it into another valid slug, and no `phpcs:ignore` is needed);
other fields are saved; the slug and at least one source post type are required (an empty slug is an error, never taken from the title: it is the public address of the feed; the box marks both as required with visible text and `aria-required`, never the HTML `required`, which would block saving a draft); a feed can be enabled only with them and a usable slug (when the submitted
slug is refused, the stored one is kept but checked with the same rules, because WordPress can assign a slug
outside the editor: on restore from the trash, or from the title of a published post without one); a slug that is invalid,
reserved or used by another feed is refused, never renamed silently (WordPress alone would add "-2", so the
slugs it renames itself, `feed`, `rss`, `rss2`, `rdf`, `atom` and `embed`, are reserved too); every source
post type must be a public post type, `attachment` excluded (a list with one that is not is refused as a whole and
the previous list is kept; duplicates are saved once), or one already saved for the feed: a saved post type that is no
longer selectable (its plugin deactivated) stays checked with the note "not available" and is kept until it is unchecked; the items per page of a feed cannot exceed the
global maximum of the settings (a feed that already exceeds a lowered maximum keeps its value, and the editor
reports it at every save until it is lowered).

Settings page: the warnings are shown on the page only, not on every admin screen. The REST check asks a new
`WP_REST_Server` object to `check_authentication()` (the core method that runs the filter `rest_authentication_errors`)
as an anonymous user: no `rest_api_init` runs and no request is made; the Application Passwords check asks
`wp_is_application_passwords_available()` and looks at enabled feeds only (first 100).

Admin REST routes (`Rest\Admin_Routes`, namespace `kkce-admin/v1`, kept out of the `/wp-json/` index), used by
the mapping editor. Permission: the capability `edit_posts` of the `kkce_feed` post type (today `manage_options`,
the same as the editor); anonymous requests get 401, other users 403. The editor JavaScript authenticates with the
cookie and the REST nonce (`wp_rest`). Arguments are validated by their schema (400 on error); `post_types` is a
list of 1 to 100 keys (`[a-z0-9_-]{1,20}`), and the ones that are not selectable (not public, `attachment`,
unknown) are left out.
- `GET /catalog?post_types[]=...`: `Admin_Routes::describe_catalog()`, also printed by the mapping box as its initial
  data: `post_types` (selectable ones), `unavailable` (the others), `labels` (post type => label), `sources` (id, label,
  group, post type, declared type, options, `needs_confirmation`, `suggested_key` from `Mapping::suggest_key()`) from
  `Catalog_Factory`, `suggested_rows`.
- `POST /preview` with `mapping` (JSON text, read with `Mapping::decode()` and `Mapping_Validator`, the same limits as
  the saved mapping), `post_types` and an optional `post_id`. An unreadable or invalid mapping gives 400
  `kkce_invalid_mapping` with every error (`row`, `field`, `code`; `json` for unreadable text); no selectable post
  type gives 400 `kkce_no_post_types`. With `post_id`, the item must be published, without a password and of a
  selectable post type of the request, otherwise 404 `kkce_item_not_found`; without it, the latest modified
  published item without a password of every post type. Response: `items` (`post` with id, type and stored title;
  `data`, the object of `Item_Exporter`, always a JSON object) and `empty_types` (post types without items). The
  preview never checks that the sources exist in the catalog: an unknown source just gives a missing value.
- `GET /items?post_type=...`: the latest modified items of a selectable post type that the preview can use (published,
  without a password; at most `Admin_Routes::RECENT_ITEMS`, 20), newest first, each with `id`, stored `title` and
  `modified` (GMT, ISO 8601). A post type that is missing, malformed or not selectable gives 400 (`kkce_no_post_types`
  when not selectable). The preview uses the same query for its latest item.

Planned: REST `GET kkce/v1/<feed-slug>` (read-only, public or authenticated) registered on
`rest_api_init`; capability `kkce_read_export`; private post type `kkce_feed`. No shortcodes, cron jobs or
WP-CLI commands are planned for V1.


### Export rendering scope

`Item_Exporter` temporarily establishes the exported post and the loop globals populated by
`setup_postdata()`, alongside the anonymous user. It restores the exact previous values and unset
state in `finally`, including after a rendering exception. The scoped PHPCS suppression on the
assignment of `$GLOBALS['post']` is intentional: WordPress block context and template tags require
that global, and `setup_postdata()` alone does not set it. A second line-scoped suppression permits
restoring the saved WordPress loop globals: their names belong to core and cannot be prefixed.

`Content_Renderer` short-circuits WordPress HTTP API calls with `pre_http_request` throughout block,
shortcode and URL rendering. Each call uses its own temporary callback, removed in `finally`, so
nested rendering keeps the outer guard. This covers HTTP API requests from dynamic blocks as well
as opted-in shortcodes; arbitrary third-party PHP that bypasses the WordPress HTTP API is outside
this guard. `Url_Absolutizer` reads srcset URLs up to ASCII whitespace and keeps internal commas,
including those in data URLs.

`Normalizer` preserves literal comparisons in plain text, refuses integer text outside the PHP
integer range (including values with leading zeros), and refuses null bytes before parsing dates.
These invalid numbers and dates give `null`, like every row without a value.

## Technology Stack

- Platform: WordPress 6.4+ (development site on WordPress 7.1), PHP 8.3+.
- No runtime dependencies; no JavaScript or CSS build: the admin scripts are vanilla JavaScript (decision
  2026-11-07) and no CSS framework is used.
- Development tools (Composer): PHP_CodeSniffer with WordPress Coding Standards and
  PHPCompatibilityWP; PHPStan with `szepeviktor/phpstan-wordpress` and `php-stubs/wordpress-stubs`;
  PHPUnit 9.6 with Yoast PHPUnit Polyfills; Brain Monkey; WP-CLI with `i18n-command`.


## Quality checks

`WordPress.DB.SlowDBQuery` is disabled centrally in PHPCS by operator decision (2026-11-11): meta-query
performance is assessed through query counts and representative data, rather than argument-name
warnings. Inline suppressions for this sniff are unnecessary; other database checks remain active.

Canonical list of quality-check commands for AI agents and contributors; run them from the plugin
root. All scripts are non-interactive and can be reused by a future CI.

| Command | What it does | Run by the pre-commit hook |
|---|---|---|
| `composer run lint` | `php -l` on every first-party PHP file (`bin/php-lint.php`) | Staged PHP files only |
| `composer run phpcs` | PHPCS with `phpcs.xml.dist`; target: 0 errors and 0 warnings | Staged PHP files, errors only |
| `composer run phpcbf` | Automatic PHPCS fixes | No |
| `composer run phpstan` | PHPStan on the whole plugin at the level of `phpstan.neon.dist` (8), no baseline | Yes |
| `composer run test:unit` | Unit suite (includes the contract tests on versions, PHPStan coverage and AGENTS documents) | Yes |
| `composer run test:integration` | Integration suite on the WordPress test suite, database `kkce_wordpress_test` (see `tests/integration/README.md`) | No |
| `composer run test:all` | Unit then integration suites | No |
| `composer run check` | `lint`, `phpcs`, `phpstan`, `test:unit` | — |
| `composer run make-pot` | Regenerates `languages/kk-content-exporter.pot` | No |
| `composer run i18n` | `make-pot`, `update-po`, `make-mo` | No |
| `composer run hooks:install` | Activates `.githooks/` in the clone | — |
| `composer run build:zip` | Builds `build/kk-content-exporter-<version>.zip` (clean tree, `check`, `.distignore`, package verification) | No |
| `composer run svn:prepare` | Prepares the WordPress.org SVN working copy from the build (dry run by default, `-- --apply`; never commits) | No |
| `composer audit` | Known vulnerabilities in the Composer dependencies | No |
| Plugin Check on the built package (`wp plugin check <plugin-root>/build/kk-content-exporter` from the Local site root) | WordPress.org directory checks; complements PHPCS (details in *WordPress.org Plugin Directory Rules*, `AGENTS/CODING_STANDARDS.md`) | No; before every release |

The commit-msg hook also blocks AI attribution (`Co-Authored-By` trailers, "Generated with" lines) and
first lines without an allowed prefix or of 72 characters or more. CI on GitHub Actions (`.github/workflows/ci.yml`)
runs `composer run check` on PHP 8.3 and 8.4 and `composer audit --locked`; it must keep calling these
Composer scripts. Its actions are pinned to a full commit SHA with the tag in a comment
(`KKCE_CI_Workflow_Unit_Test`): to update one, read the commit of the new tag (`git ls-remote --tags`) and change
the SHA and the comment together. The integration suite and Plugin Check are not in CI yet.


## References

- Project scope and boundaries: `AGENTS/PROJECT.md`.
- Rules and checklists: `AGENTS/CODING_STANDARDS.md`.
- Tests: `tests/README.md`, `tests/unit/README.md`, `tests/integration/README.md`.
- Translations: `AGENTS/I18N.md`.

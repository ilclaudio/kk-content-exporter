# ARCHITECTURE.md


## Architecture Notes

KK Content Exporter is a classic WordPress plugin (no blocks, no build step). PHP classes live in
the `KKCE` namespace under `includes/` and are loaded by the plugin's own autoloader; Composer is a
development tool only and is never shipped. The code contains the bootstrap, the private feed post type with its configuration meta, the
feed list, the feed editor, the settings page and the uninstall cleanup: the other features of the plan
(field mapping, REST endpoints, log) are not implemented yet. Update this file in the same change that adds them.


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
| `includes/output/class-mapping.php` | `KKCE\Output\Mapping`: pure. Format of the mapping (version 1): vocabulary (kinds, output types, missing-value policies, source groups), limits, `decode()` of the JSON text, `suggest_key()` |
| `includes/output/class-mapping-validator.php` | `KKCE\Output\Mapping_Validator`: pure. `validate()` lists every error of a decoded mapping (`row`, `field`, `code`), `normalize()` gives the canonical form. Refuses a sensitive source that the row does not list in `confirmed`. Does not check that a source exists (the catalog does, at save time) |
| `includes/output/class-sensitive-keys.php` | `KKCE\Output\Sensitive_Keys`: pure. Whether a name (or the name of a source `group.name`) looks sensitive: split into words, compared with a list of words and of adjacent pairs. Judges the name, never the value |
| `includes/sources/class-source.php` | `KKCE\Sources\Source`: pure value object describing a source (identifier `group.name`, label, post type or common, declared type, accepted options, whether it needs a confirmation) |
| `includes/sources/class-catalog.php` | `KKCE\Sources\Catalog`: pure. Sources of a feed for its post types (common ones once, then those of each post type), `find()` of a source for a post type or `*`, `suggested_rows()` (the `type` row in feeds with two or more post types) |
| `includes/sources/class-core-provider.php` | `KKCE\Sources\Core_Provider`: the core sources common to every post type and their values (stored title and excerpt, GMT dates, author ID, display name and archive URL only; never e-mail or login) |
| `includes/admin/class-feed-list.php` | `KKCE\Admin\Feed_List`: columns of the `kkce_feed` list (relative endpoint address computed by `rest_url()`, source, access, status), read through the sanitizers and escaped |
| `includes/class-settings.php` | `KKCE\Settings`: option `kkce_settings` (defaults, safe reading, processing of the form: pure; `get()` and `sanitize_option()` are the only WordPress calls) |
| `includes/class-uninstaller.php` | `KKCE\Uninstaller`: deletes feeds (every status, with meta), the option and the capability when the setting asks for it |
| `includes/admin/class-settings-page.php` | `KKCE\Admin\Settings_Page`: submenu page built with the Settings API, and the diagnostics |
| `includes/admin/class-feed-editor.php` | `KKCE\Admin\Feed_Editor`: the "Feed settings" meta box of the `kkce_feed` editor and its saving (nonce per feed, `edit_post` capability, slug set through `wp_insert_post_data`, meta written on `save_post_kkce_feed`, errors shown once in a notice), the live address of the feed under the slug field, and the loading of `assets/js/feed-editor.js` |
| `assets/js/feed-editor.js` | Vanilla JavaScript of the feed editor, loaded only on the edit and create screens of `kkce_feed` (handle `kkce-feed-editor`, footer, version `KKCE_VERSION`): updates the address of the feed while the slug is typed (slug rules and messages printed by the server in the box, nothing duplicated) and asks for confirmation before leaving with unsaved changes in the box. Progressive enhancement: the box works without it. Shipped in the release ZIP and required by `kkce_release_missing_files()` |
| `includes/class-feed-form.php` | `KKCE\Feed_Form`: pure processing of the submitted editor values (which value is saved, which error is reported) |
| `includes/class-feed-validator.php` | `KKCE\Feed_Validator`: pure validation (no WordPress call) of the slug, the items per page, the access and the list of source post types; returns error codes, the editor writes the messages |
| `languages/` | `kk-content-exporter.pot`, `kk-content-exporter-it_IT.po` / `.mo` |
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

`includes/admin/` holds the classes of the administration screens (the feed list, the editor and the settings page).
`includes/output/` holds the pure logic of the output (the mapping format and its validation now; the rule
evaluator, the normalizer and the serialization later). `includes/sources/` holds the sources a mapping can read: the catalog and the providers (core now; meta,
taxonomies, image, language, ACF and CMB2 later). Planned folder (created when its first class arrives):
`includes/rest/` (endpoints).


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
   default `public`). REST routes (on `rest_api_init`) will be registered from here.
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
Admin hooks used (all in `Plugin::boot()`): `manage_kkce_feed_posts_columns`, `manage_kkce_feed_posts_custom_column`,
`post_row_actions` (removes Quick Edit from the feeds, because it could change a slug without validation),
`add_meta_boxes_kkce_feed`, `wp_insert_post_data`, `save_post_kkce_feed`, `admin_notices`, `untrashed_post`,
`admin_enqueue_scripts` (`Feed_Editor::enqueue_assets()`), `admin_menu` and `admin_init` (the settings page)
(`Feed_Post_Type::disable_restored_feed()`: a feed restored from the trash starts disabled).
`wp_insert_post_data` runs for every post type and returns at once for anything but `kkce_feed`.

Rules of the editor: a field with an invalid value keeps its previous value and is reported in a notice, the
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

Settings page: the warnings are shown on the page only, not on every admin screen. The REST check runs the
core filter `rest_authentication_errors` as an anonymous user (the only `phpcs:ignore` of the project, on that
line: invoking a core hook is intentional) and no request is made; the Application Passwords check asks
`wp_is_application_passwords_available()` and looks at enabled feeds only (first 100).

Planned: REST `GET kkce/v1/<feed-slug>` (read-only, public or authenticated) registered on
`rest_api_init`; capability `kkce_read_export`; private post type `kkce_feed`. No shortcodes, cron jobs or
WP-CLI commands are planned for V1. The admin preview and the mapping editor (Phase 2) will need an
admin-only REST or AJAX call: document it here when it is added.


## Technology Stack

- Platform: WordPress 6.4+ (development site on WordPress 7.1), PHP 8.3+.
- No runtime dependencies; no JavaScript or CSS build: the admin scripts are vanilla JavaScript (decision
  2026-11-07) and no CSS framework is used.
- Development tools (Composer): PHP_CodeSniffer with WordPress Coding Standards and
  PHPCompatibilityWP; PHPStan with `szepeviktor/phpstan-wordpress` and `php-stubs/wordpress-stubs`;
  PHPUnit 9.6 with Yoast PHPUnit Polyfills; Brain Monkey; WP-CLI with `i18n-command`.


## Quality checks

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

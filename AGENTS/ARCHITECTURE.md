# ARCHITECTURE.md


## Architecture Notes

KK Content Exporter is a classic WordPress plugin (no blocks, no build step). PHP classes live in
the `KKCE` namespace under `includes/` and are loaded by the plugin's own autoloader; Composer is a
development tool only and is never shipped. Version 0.0.1 contains only the bootstrap: the features
of the plan (feed post type, mapping, REST endpoints) are not implemented yet. Update this file in the
same change that adds them.


## Key Directories and Files

| Path | Content |
|---|---|
| `kk-content-exporter.php` | Main file: plugin header, `ABSPATH` guard, constants, autoloader, lifecycle hooks |
| `uninstall.php` | Uninstall routine (guarded by `WP_UNINSTALL_PLUGIN`) |
| `includes/` | First-party classes of the `KKCE` namespace (`class-*.php`) |
| `includes/class-autoloader.php` | `KKCE\Autoloader`: maps `KKCE\<Sub>\<Class_Name>` to `includes/<sub>/class-<class-name>.php` |
| `includes/class-plugin.php` | `KKCE\Plugin`: registers the hooks, loads the text domain |
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

Planned folders (created when their first class arrives): `includes/admin/` (feed list and editor),
`includes/rest/` (endpoints), `includes/sources/` (field providers: core, meta, ACF, CMB2, taxonomies,
image, language), `includes/output/` (normalization and serialization).


## Plugin Lifecycle

1. **Load** (`kk-content-exporter.php`): exits without `ABSPATH`; defines `KKCE_VERSION`,
   `KKCE_PLUGIN_FILE`, `KKCE_PLUGIN_DIR`; registers `KKCE\Autoloader`; registers activation and
   deactivation hooks; hooks `KKCE\Plugin::boot()` on `plugins_loaded`.
2. **Boot** (`plugins_loaded`): `Plugin::boot()` only registers hooks and is idempotent. No
   translatable string is evaluated before `init` (WordPress 6.7+ reports translations loaded too early).
3. **Init** (`init`): `Plugin::load_textdomain()` loads the bundled translations. Post types, post
   meta and REST routes (on `rest_api_init`) will be registered from here.
4. **Activation** (`Plugin::activate()`): currently empty. Will add the capability `kkce_read_export`
   and flush rewrite rules only if needed. Activation must be idempotent and must not create content.
5. **Deactivation** (`Plugin::deactivate()`): never deletes the feed configuration.
6. **Uninstall** (`uninstall.php`): the only place where data is removed. The removal of the
   configuration will be optional (an explicit setting), never implicit.

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

None yet. Planned: REST `GET kkce/v1/<feed-slug>` (read-only, public or authenticated) registered on
`rest_api_init`; capability `kkce_read_export`; private post type `kkce_feed`. No shortcodes, AJAX
handlers, cron jobs or WP-CLI commands are planned for V1.


## Technology Stack

- Platform: WordPress 6.4+ (development site on WordPress 7.1), PHP 8.3+.
- No runtime dependencies; no JavaScript or CSS build yet (the admin layout is still to be decided:
  no CSS framework).
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
| `composer run phpstan` | PHPStan on the whole plugin at the level of `phpstan.neon.dist` (6), no baseline | Yes |
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
| Plugin Check on the built package (`wp plugin check <plugin-root>/build/kk-content-exporter` from the Local site root) | WordPress.org directory checks; complements PHPCS. On the working folder it also reports the development files (see *WordPress.org Plugin Directory Rules* in `AGENTS/CODING_STANDARDS.md`) | No; before every release |

The commit-msg hook also blocks AI `Co-Authored-By` trailers. CI on GitHub Actions is planned but not
configured yet: when it is added, it must call these Composer scripts.


## References

- Project scope and boundaries: `AGENTS/PROJECT.md`.
- Rules and checklists: `AGENTS/CODING_STANDARDS.md`.
- Tests: `tests/README.md`, `tests/unit/README.md`, `tests/integration/README.md`.
- Translations: `AGENTS/I18N.md`.

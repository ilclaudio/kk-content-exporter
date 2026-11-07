# CODING_STANDARDS.md


## Purpose
Coding rules for this plugin. Write code according to the official WordPress Coding Standards, the
WordPress.org Plugin Directory guidelines and the project conventions below.


## Quality Priorities
- Correctness and robustness (especially of what is exported: types, dates, missing values).
- Security and privacy first: never expose non-public or sensitive data.
- Accessibility of the admin screens (WCAG 2.2 AA oriented, native controls first).
- Readability and maintainability.
- Compliance with WordPress Coding Standards and WordPress.org guidelines.


## Compatibility
- WordPress: minimum 6.4; PHP: minimum 8.3 (header, `readme.txt`, `composer.json`, `phpcs.xml.dist`,
  `phpstan.neon.dist`; kept aligned by `KKCE_Release_Version_Unit_Test`).
- PHP 8.3 features are allowed and encouraged where they help: scalar and union types, `readonly`
  properties and classes, enums, `match`, typed class constants, first-class callables.
- Do not call WordPress functions introduced after 6.4 without a guard (`function_exists()`) and a
  fallback; check the `@since` of the function. Do not raise a minimum version without the operator's
  confirmation.


## Core PHP/WordPress Rules

### Formatting and documentation
- Tabs for indentation; LF line endings (`.editorconfig`, `.gitattributes`).
- File header docblock with `@package KKCE`; docblocks for every class, method and function, with
  `@param` / `@return` types precise enough for PHPStan level 8 (`array<string, mixed>`, `list<int>`, ...).
- Comments and docblocks in English.

### Naming conventions
- Namespace: `KKCE\` for every class, with sub-namespaces by area (`KKCE\Admin`, `KKCE\Rest`,
  `KKCE\Sources`, `KKCE\Output`).
- Classes: `Class_Name_With_Underscores`; file `class-class-name-with-underscores.php` in the folder of
  its sub-namespace (for example `KKCE\Admin\Feed_Editor` in includes/admin/class-feed-editor.php).
  Interfaces and traits: `interface-*.php`, `trait-*.php` (extend the autoloader when the first one is added).
- Functions, methods, variables: `snake_case`. Constants: `UPPER_CASE`.
- Everything global carries the project prefix: constants `KKCE_`; functions, options, transients,
  post meta keys, hooks (actions and filters), capabilities, post types, taxonomies, nonces,
  REST namespace and script/style handles `kkce_` / `kkce-` / `kkce/v1`.
- Post meta of the plugin's own objects use a leading underscore when they must not appear in the
  Custom Fields box (`_kkce_mapping`).

### Code structure
- Small, focused classes and methods; early returns; shallow nesting.
- Keep WordPress-dependent code (queries, hooks, options) separate from pure logic (mapping,
  normalization, validation), so that the pure logic is unit-testable without WordPress. Pure-logic
  classes receive their data as arguments and call no WordPress function; their tests use no mocks.
  Brain Monkey is reserved for the wiring layer (see *When to use which level* in `AGENTS/AI_BEHAVIOR.md`).
- Class loading is a deliberate decision (2026-11-07): WPCS file names (`class-*.php`) loaded by
  `KKCE\Autoloader`, no PSR-4 and no Composer autoloader at runtime, so that the whole plugin passes
  `WordPress.Files.FileName` without exclusions and the release ZIP contains no `vendor/`. The plugin
  has no runtime Composer dependencies; revisit this decision only if one becomes necessary (it would
  then need prefixing, for example with Strauss).
- Register hooks only from `KKCE\Plugin::boot()` or a class it wires; never as side effects of including
  a file.
- Prefer existing shared code or a scoped refactor over copying logic; do not create an abstraction
  until at least two concrete consumers need it.


## WordPress Security Checklist
- **Input**: `wp_unslash()` then sanitize every `$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`, `$_SERVER`
  value (`sanitize_text_field()`, `sanitize_key()`, `absint()`, `esc_url_raw()`, ...). Validate against
  allow-lists where possible (post types, mapping types, JSON key pattern).
- **Output**: escape late and by context (`esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`,
  `wp_json_encode()`); i18n with escaping helpers (`esc_html__()`, `esc_attr_e()`, ...).
- **Authorization**: `current_user_can()` before every privileged operation; admin screens and saves
  need a dedicated capability check (not only being logged in).
- **Intent**: nonces (`wp_nonce_field()` / `check_admin_referer()` / `wp_verify_nonce()`) on every
  state-changing admin action. A nonce is not authorization: always check capabilities too.
- **REST**: every route has an explicit `permission_callback`; `__return_true` only for intentionally
  public, read-only feeds with validated and bounded arguments (`args` with `type`, `minimum`,
  `maximum`, `sanitize_callback`, `validate_callback`). Authenticated feeds require the capability
  `kkce_read_export`; distinguish 401 (not authenticated) from 403 (not allowed). Errors use
  `WP_Error` with a proper `status`.
- **Export safety** (core rule of this plugin):
  - query only `publish` status, exclude password-protected posts (`has_password => false`), also for
    authenticated requests;
  - export only the mapped fields, never the whole meta set;
  - block sensitive keys (passwords, tokens, keys, secrets, sessions, capabilities, user levels,
    e-mail, login) at mapping time and again at output time;
  - author: only selected public attributes (display name, ID, URL), never e-mail or login;
  - no write to source content; the endpoints are read-only.
- **SQL**: prefer `WP_Query` / meta and term APIs; for dynamic SQL use `$wpdb->prepare()`.
- **Never**: `eval`, `extract`, `unserialize()` on untrusted data, shell commands, arbitrary PHP in
  the admin UI, remote requests without the operator's decision and user consent.
- **Files**: every PHP file under `includes/` starts with `defined( 'ABSPATH' ) || exit;`;
  `uninstall.php` checks `WP_UNINSTALL_PLUGIN`.
- **Logs**: never log credentials, Application Passwords, personal data or exported content.


## WordPress.org Plugin Directory Rules
(Guidelines: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/; use the
`wp-plugin-directory-guidelines` skill.)
- GPL-compatible code and assets only; record the license of every bundled third-party file.
- No external calls, tracking or remote assets without explicit user consent; no remote code.
- No obfuscated code; human-readable source shipped.
- Prefix everything global; do not pollute or override other plugins' data.
- No admin nags or upsells that hijack the dashboard; notices only when relevant and dismissible.
- `readme.txt` in the WordPress.org format, with `Stable tag` equal to the released version.
- The release ZIP contains no development tools or test data (see *Release package* in
  `AGENTS/GIT_WORKFLOW.md`).
- **Plugin Check** (https://wordpress.org/plugins/plugin-check/) complements PHPCS: run it on the
  Local site before every release and fix every error. Run it on the **built package**, not on the
  working folder: after `composer run build:zip`, from the site root,
  `wp plugin check <plugin-root>/build/kk-content-exporter`. On the working folder
  (`wp plugin check kk-content-exporter`, or Tools → Plugin Check) it also reports the development
  files (`bin/`, `tests/`, dotfiles, `AGENTS.md`), which `.distignore` keeps out of the package.
  Its known, accepted warning is
  `PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound` (see *Internationalization*).


## Optional integrations (ACF, CMB2, Polylang)
- They are optional and never required: the plugin activates and works without them, and never
  declares them in `Requires Plugins`.
- Detect them at runtime (`function_exists( 'get_field' )`, `class_exists( 'CMB2' )`,
  `function_exists( 'pll_get_post_language' )`) inside a dedicated source provider class; never call
  their functions outside it.
- Read only simple fields (text, HTML, number, boolean, date/time, URL, select, simple lists).
  Repeater, flexible content, groups, relationships and serialized structures are rejected with a clear
  message in V1.
- When the CMB2 configuration cannot be detected, fall back to manual entry of the meta key.
- **Never use ACF or CMB2 for the plugin's own fields or meta**: the configuration of `kkce_feed` and
  every plugin setting use core APIs (`register_post_type()`, `register_post_meta()` with
  `show_in_rest => false` and an `auth_callback`, native meta boxes, the Settings API).
- PHPStan stubs for these plugins are added in Phase 2, together with the first code that uses them.


## Internationalization (i18n)
- Source language: English. Every user-facing string goes through an i18n function with the
  **literal** text domain `'kk-content-exporter'` (never a variable or constant).
- Use the escaping variants when printing (`esc_html__()`, `esc_html_e()`, `esc_attr__()`).
- Use `sprintf()` / `printf()` with placeholders, never concatenated sentences; add a
  `/* translators: ... */` comment right before every call whose string has placeholders, explaining
  each one; use numbered placeholders (`%1$s`) when there is more than one; use `_n()` for plurals and
  `_x()` when a word needs context.
- Do not translate before `init` (WordPress 6.7+ reports `_load_textdomain_just_in_time` notices).
- Loading: `KKCE\Plugin::load_textdomain()` calls `load_plugin_textdomain()` on `init` with the
  `languages/` folder. This is a **deliberate decision** (2026-11-06): the plugin ships its own
  `it_IT` translation and supports WordPress 6.4, and just-in-time loading only looks in
  `wp-content/languages/`. Language packs from translate.wordpress.org, when present, take precedence.
  Plugin Check reports the call as a warning: accepted and documented, do not remove the call.
  Covered by `KKCE_Environment_Integration_Test::test_bundled_italian_translation_is_loaded`.
- Translation workflow (new strings, new language, JavaScript): `AGENTS/I18N.md`.


## Admin UI and accessibility
- No CSS framework; the layout choice is pending. Prefer native WordPress admin markup and classes
  (`wrap`, `form-table`, `notice`, `button`), native controls before ARIA.
- Every control has a visible label; errors are announced in text, not only by color; keyboard
  operable, visible focus, logical heading order.
- Enqueue assets only on the plugin's own screens, with versioned handles prefixed `kkce-`.


## Verification

Pick the checks that match the change, run them, and report the ones that could not run and why.
Never state that something was verified when the check did not run. The canonical command list is in
`AGENTS/ARCHITECTURE.md` (*Quality checks*).

| Change | Check | Notes |
|---|---|---|
| Any PHP file | `composer run lint` (or `php -l <file>`) | Syntax |
| Any PHP file | `composer run phpcs` | 0 errors and 0 warnings; fix with `composer run phpcbf`; never weaken the ruleset |
| Any PHP file | `composer run phpstan` | Level 8, no baseline, no `ignoreErrors` |
| PHP, configuration or release files | `composer run test:unit` | Also run by the pre-commit hook |
| WordPress behavior (hooks firing, post types, meta, capabilities, queries, REST, activation, translations) | `composer run test:integration` | Dedicated database `kkce_wordpress_test`; see `tests/integration/README.md` |
| New or changed strings | `composer run i18n` | Regenerates `.pot`, merges `.po`, compiles `.mo`; then translate the new `it_IT` entries |
| Runtime behavior | Activate/use the plugin on http://kkce.local with `WP_DEBUG` | No notices, warnings or deprecations in `wp-content/debug.log` |
| Before a release | Plugin Check, `composer audit` | See *WordPress.org Plugin Directory Rules* |

### PHPStan quality gate
Level 8 on every first-party PHP file (`phpstan.neon.dist`; `KKCE_PHPStan_Config_Unit_Test` fails when
a PHP file or folder is not analyzed, when the level is lowered or when a baseline appears). Model
WordPress's dynamic APIs with precise PHPDoc, runtime guards and, when needed, stubs in `tests/phpstan/`.
Any unavoidable exception is limited to one identifier and path, documented with its reason.

### Tests
- Unit tests in `tests/unit/`, named `KKCE_<Name>_Unit_Test.php` with a class of the same name extending
  `KKCE_Unit_Test_Case` (Brain Monkey set up and torn down for every test). Pure-logic tests do not
  use Brain Monkey expectations; mock WordPress functions only in tests of the wiring layer.
- Integration tests in `tests/integration/`, named `KKCE_<Name>_Integration_Test.php`, extending
  `WP_UnitTestCase` (each test runs in a transaction that is rolled back).
- `tests/` and `bin/` are excluded from PHPCS: follow the style of the surrounding files and `.editorconfig`.
- A failing contract test (versions, requirements, PHPStan coverage, AGENTS documents) means a file
  was updated and another one forgotten: fix the data, not the test.

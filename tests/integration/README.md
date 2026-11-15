# Integration tests (WordPress test suite)

Tests that need a real WordPress: hooks firing, post types and meta, capabilities, queries, REST
routes, translations. They run on the WordPress PHPUnit test framework and on a **dedicated, empty
database `kkce_wordpress_test`**, which the framework empties at every run.

They can run in two ways; both use the same configuration (`wp-tests-config.php`) and the same guard.

| | In Docker (recommended) | Local |
|---|---|---|
| Command | `composer run test:integration:docker` | `composer run test:integration` |
| WordPress | 7.1.4 with PHP 8.3, in the wp-env test environment | the `src/` of a `wordpress-develop` checkout, with the PHP of the machine |
| Database | `kkce_wordpress_test` in the MySQL server of the environment, created on start | `kkce_wordpress_test` on a MariaDB/MySQL server you prepare |
| Needs | Docker Desktop, Node 24, `npm ci`, `composer install` | PHP 8.3+ with `mysqli`, `composer install`, `wordpress-develop`, the database |

**Safety:** `tests/bootstrap-integration.php` loads the configuration first and refuses to run when
`DB_NAME` is not `kkce_wordpress_test`, because the framework drops every table of the database it is
given. Never point it to the database of a site.

## In Docker

```sh
composer run test:integration:docker                               # whole suite
composer run test:integration:docker -- --filter KKCE_Feed_Editor  # arguments after -- reach PHPUnit
composer run env:tests:stop                                        # stop the environment when done (env:stop:all stops both)
```

`tests/bin/integration.mjs` starts the environment of `.wp-env.tests.json` (http://localhost:8891; the
first start downloads WordPress and the Docker images, then it takes about a minute), creates the
database if it is missing and runs `vendor/bin/phpunit -c phpunit-integration.xml` inside the `cli`
container, in the folder of the plugin. It passes the connection through environment variables (host
`mysql`, user `root`, password `password`: the public defaults of wp-env, no real data) and points
`ABSPATH` to the WordPress of the container. The WordPress test framework comes with wp-env (`WP_TESTS_DIR`).

The environment is independent from the one of the end-to-end tests (`.wp-env.json`, port 8890): own
containers, own database. Local changes to it go in `.wp-env.tests.override.json` (ignored by Git).

## Local

### One-time setup on Local (Windows)

1. Start the Local site `kkce` (its MariaDB server must be running). In Local, *Database* tab of the
   site: note the port (for example `10064`), user and password (Local default: `root` / `root`).
2. Create the database, from *Open Adminer* in Local or with the MySQL client of Local's site shell
   (*Open site shell*):

   ```sql
   CREATE DATABASE IF NOT EXISTS kkce_wordpress_test
     CHARACTER SET utf8mb4
     COLLATE utf8mb4_unicode_ci;
   ```

   If the user is not `root`: `GRANT ALL PRIVILEGES ON kkce_wordpress_test.* TO 'user'@'localhost';`.
3. Copy `tests/integration/wp-tests-config.local-sample.php` to
   `tests/integration/wp-tests-config.local.php` (ignored by Git) and set `host` (`127.0.0.1:<port>`),
   `user` and `password`.
4. Get `wordpress-develop` if it is not on the machine:
   `git clone --depth=1 https://github.com/WordPress/wordpress-develop.git C:/WordpressDEV/wordpress-develop`
   (any other folder works with `WP_TESTS_DIR`). No build step is needed; it is never modified.

### Running

```sh
composer run test:integration
composer run test:integration -- --filter test_bundled_italian_translation_is_loaded
```

## Configuration and environment variables

| Variable | Meaning | Default |
|---|---|---|
| `WP_TESTS_DIR` | Folder of the WordPress test framework (`tests/phpunit` of `wordpress-develop`; set by wp-env in Docker) | `C:/WordpressDEV/wordpress-develop/tests/phpunit` |
| `WP_TESTS_CONFIG_FILE_PATH` | Alternative test configuration (must still use `kkce_wordpress_test`) | `tests/integration/wp-tests-config.php` |
| `KKCE_TEST_ABSPATH` | WordPress folder, when it is not the `src/` next to the framework (Docker: `/var/www/html/`) | `src/` of `wordpress-develop` |
| `KKCE_TEST_DB_HOST` | Database host and port | value of `wp-tests-config.local.php`, else `localhost` |
| `KKCE_TEST_DB_USER` | Database user | value of `wp-tests-config.local.php`, else `root` |
| `KKCE_TEST_DB_PASSWORD` | Database password | value of `wp-tests-config.local.php`, else empty |

Environment variables take precedence over the local file. Example of a local run with variables
(PowerShell, current session only): `$env:KKCE_TEST_DB_HOST = '127.0.0.1:10064'`; Git Bash:
`KKCE_TEST_DB_HOST=127.0.0.1:10064 composer run test:integration`.

`wp-tests-config.php` (versioned, no credentials) points `WP_PLUGIN_DIR` to the parent folder of this
plugin, so that `plugin_basename()` returns `kk-content-exporter/kk-content-exporter.php` as on a real site.

## How a run behaves

Exit code `0` when everything passes, `1` otherwise. If a requirement is missing, the suite stops at once
with a message saying what is missing. Each test runs in a transaction that is rolled back, so a single
test does not depend on the others and leaves no data behind. Test uploads go to
`.phpunit.cache/integration-uploads/` inside this repository.

The number of assertions can differ by a few between the two ways: some tests make one assertion per
item that WordPress provides (for example one per REST route), and the two WordPress versions differ.

## What it covers

| File | What it checks |
|---|---|
| `KKCE_Activation_Integration_Test.php` | The export capability is granted to administrators on activation, idempotently, and kept on deactivation |
| `KKCE_Admin_Routes_Integration_Test.php` | The admin REST routes of the mapping editor: permissions, catalog, preview of published public data only, mapping errors |
| `KKCE_Content_Renderer_Integration_Test.php` | Content and excerpt as clean HTML: blocks, shortcodes only on request, raw text, no external request |
| `KKCE_Core_Provider_Integration_Test.php` | Values of the core fields of posts and pages: GMT dates, public author attributes only |
| `KKCE_Environment_Integration_Test.php` | The plugin is loaded, wired and translatable (bundled `it_IT` translation) |
| `KKCE_Feed_Editor_Integration_Test.php` | Saving a feed and its mapping: nonce, capability, validation, refused mapping kept as a draft, activation rules |
| `KKCE_Feed_List_Integration_Test.php` | Columns of the list of the feeds, escaped, with the mark of the feeds that export sensitive fields |
| `KKCE_Feed_Meta_Integration_Test.php` | Configuration meta: registered, hidden from REST, sanitized, editable by administrators only |
| `KKCE_Feed_Post_Type_Integration_Test.php` | The post type `kkce_feed`: private, without REST routes, title only, administrators only |
| `KKCE_Feed_Url_Integration_Test.php` | The REST address of a feed, and no address for a slug that cannot serve a feed |
| `KKCE_Item_Exporter_Integration_Test.php` | An item is exported with the values a visitor would see; the current user is restored |
| `KKCE_Mapping_Box_Integration_Test.php` | The Mapping box: initial data, refused mapping shown again, script and style on the feed screens only |
| `KKCE_Post_Type_Filter_Integration_Test.php` | The filter of the post types appears only when the list is long |
| `KKCE_Settings_Page_Integration_Test.php` | The settings page: administrators only, sanitized values, diagnostics |
| `KKCE_Uninstaller_Integration_Test.php` | Nothing is removed unless the administrator chose it; then feeds, settings and capability go |

## Adding a test

1. Create `tests/integration/KKCE_<Area>_Integration_Test.php` with a class of the same name extending
   `WP_UnitTestCase`; use `self::factory()` to create posts, users and terms.
2. Optional plugins (ACF, CMB2, Polylang) are not loaded yet: when the plan needs them, add them to the
   wp-env configuration and document it here.
3. Update the table above, and run the suite in Docker before proposing the change.

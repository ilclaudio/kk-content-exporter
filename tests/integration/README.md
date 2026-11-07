# Integration tests (WordPress test suite)

Tests that need a real WordPress: hooks firing, post types and meta, capabilities, queries, REST
routes, translations. They run on the WordPress test framework of a `wordpress-develop` checkout and on
a **dedicated, empty database** that the framework empties at every run.

## Requirements

- PHP 8.3+ with `mysqli`, and `composer install`.
- A checkout of `wordpress-develop` (default: `C:/WordpressDEV/wordpress-develop`). Only its
  `tests/phpunit/` framework and `src/` are used; it is never modified.
- An empty database `kkce_wordpress_test` on a MariaDB/MySQL server. **Never the database of a site.**

## One-time setup on Local (Windows)

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
   (any other folder works with `WP_TESTS_DIR`). No build step is needed.

**Safety:** `tests/bootstrap-integration.php` loads the configuration first and refuses to run when
`DB_NAME` is not `kkce_wordpress_test`, because the framework drops every table of the database it
is given.

## Configuration and environment variables

| Variable | Meaning | Default |
|---|---|---|
| `WP_TESTS_DIR` | `tests/phpunit` folder of `wordpress-develop` | `C:/WordpressDEV/wordpress-develop/tests/phpunit` |
| `WP_TESTS_CONFIG_FILE_PATH` | Alternative test configuration (must still use `kkce_wordpress_test`) | `tests/integration/wp-tests-config.php` |
| `KKCE_TEST_DB_HOST` | Database host and port | value of `wp-tests-config.local.php`, else `localhost` |
| `KKCE_TEST_DB_USER` | Database user | value of `wp-tests-config.local.php`, else `root` |
| `KKCE_TEST_DB_PASSWORD` | Database password | value of `wp-tests-config.local.php`, else empty |

Environment variables take precedence over the local file, so a CI can configure everything without
files. Example (PowerShell, current session only): `$env:KKCE_TEST_DB_HOST = '127.0.0.1:10064'`;
Git Bash: `KKCE_TEST_DB_HOST=127.0.0.1:10064 composer run test:integration`.

`tests/integration/wp-tests-config.php` (versioned, no credentials) points `ABSPATH` to the
`src/` of `wordpress-develop` and `WP_PLUGIN_DIR` to the parent folder of this plugin, so that
`plugin_basename()` returns `kk-content-exporter/kk-content-exporter.php` as on a real site.

## Running

```sh
composer run test:integration
composer run test:integration -- --filter test_bundled_italian_translation_is_loaded
```

Exit code `0` when everything passes, `1` otherwise. If a requirement is missing, the suite stops at
once with a message saying what is missing. Each test runs in a transaction that is rolled back, so a
single test does not depend on the others and leaves no data behind.

## What it covers

| File | What it checks |
|---|---|
| `KKCE_Environment_Integration_Test.php` | The plugin loads (constants, classes, `plugin_basename()`, `boot()` hooked on `plugins_loaded`) and the bundled `it_IT` translation is loaded when the locale is Italian |

## Adding a test

1. Create `tests/integration/KKCE_<Area>_Integration_Test.php` with a class of the same name extending
   `WP_UnitTestCase`; use `self::factory()` to create posts, users and terms.
2. Optional plugins (ACF, CMB2, Polylang) are not loaded yet: when Phase 2 needs them, add a support
   script that prepares them in the ignored folder `tests/integration/.cache/` and document it here.
3. Update the table above.

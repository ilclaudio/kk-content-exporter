<?php
/**
 * Configuration of the WordPress test framework for the integration suite.
 *
 * Read by the bootstrap of the WordPress test framework (`$WP_TESTS_DIR/includes/bootstrap.php`), inside
 * the CLI container of the wp-env test environment (`.wp-env.tests.json`, see tests/integration/README.md).
 * Every value has the default of that environment and can be changed with an environment variable:
 * KKCE_TEST_DB_HOST, KKCE_TEST_DB_USER, KKCE_TEST_DB_PASSWORD (connection; the defaults are the public ones
 * of wp-env, the environment holds no real data) and KKCE_TEST_ABSPATH (WordPress folder).
 *
 * SAFETY: the framework drops every table of the database it is given, so the database name is
 * fixed to `kkce_wordpress_test` and the suite refuses any other name. Never point it to the
 * database of a site.
 *
 * @package KKCE
 */

/**
 * A setting: the environment variable when it is set, otherwise the default.
 *
 * This file can be included from inside a function (PHPUnit loads the bootstrap that way) and on its own
 * by the installer subprocess of the framework: it keeps no global state and depends on nothing else.
 *
 * @param string $env_name Environment variable.
 * @param string $default  Default value.
 * @return string
 */
function kkce_test_setting( $env_name, $default ) {
	$value = getenv( $env_name );

	return false !== $value && '' !== $value ? $value : $default;
}

define( 'KKCE_TEST_DB_NAME', 'kkce_wordpress_test' );

// WordPress of the container; the test framework comes with wp-env (WP_TESTS_DIR).
define( 'ABSPATH', rtrim( str_replace( '\\', '/', kkce_test_setting( 'KKCE_TEST_ABSPATH', '/var/www/html/' ) ), '/' ) . '/' );

// The plugin is loaded from its own folder: its parent acts as the plugins directory, so that
// plugin_basename() returns `kk-content-exporter/kk-content-exporter.php` as on a real site.
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__, 3 ) ) );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', KKCE_TEST_DB_NAME );
define( 'DB_USER', kkce_test_setting( 'KKCE_TEST_DB_USER', 'root' ) );
define( 'DB_PASSWORD', kkce_test_setting( 'KKCE_TEST_DB_PASSWORD', 'password' ) );
define( 'DB_HOST', kkce_test_setting( 'KKCE_TEST_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

// Test-only keys: the test database holds no real data.
define( 'AUTH_KEY', 'kkce-tests' );
define( 'SECURE_AUTH_KEY', 'kkce-tests' );
define( 'LOGGED_IN_KEY', 'kkce-tests' );
define( 'NONCE_KEY', 'kkce-tests' );
define( 'AUTH_SALT', 'kkce-tests' );
define( 'SECURE_AUTH_SALT', 'kkce-tests' );
define( 'LOGGED_IN_SALT', 'kkce-tests' );
define( 'NONCE_SALT', 'kkce-tests' );

$table_prefix = 'wptests_'; // Required name of the test framework.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'KKCE Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

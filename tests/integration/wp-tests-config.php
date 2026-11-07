<?php
/**
 * Configuration of the WordPress test framework for the integration suite.
 *
 * Read by wordpress-develop/tests/phpunit/includes/bootstrap.php. Connection values come, in
 * order of precedence, from the environment (KKCE_TEST_DB_HOST, KKCE_TEST_DB_USER,
 * KKCE_TEST_DB_PASSWORD), from the git-ignored file wp-tests-config.local.php next to this one
 * (copy wp-tests-config.local-sample.php), or from the defaults below. No credential is versioned.
 *
 * SAFETY: the framework drops every table of the database it is given, so the database name is
 * fixed to `kkce_wordpress_test` and the suite refuses any other name. Never point it to the
 * database of a site.
 *
 * @package KKCE
 */

/**
 * A connection setting: environment variable, then local file, then default.
 *
 * This file can be included from inside a function (PHPUnit loads the bootstrap that way), so it
 * keeps no global state: the local file is read here.
 *
 * @param string $env_name  Environment variable.
 * @param string $local_key Key in wp-tests-config.local.php.
 * @param string $default   Default value.
 * @return string
 */
function kkce_test_setting( $env_name, $local_key, $default ) {
	static $local = null;

	$value = getenv( $env_name );
	if ( false !== $value && '' !== $value ) {
		return $value;
	}

	if ( null === $local ) {
		$file  = __DIR__ . '/wp-tests-config.local.php';
		$local = is_readable( $file ) ? require $file : array();
		$local = is_array( $local ) ? $local : array();
	}

	return isset( $local[ $local_key ] ) ? (string) $local[ $local_key ] : $default;
}

define( 'KKCE_TEST_DB_NAME', 'kkce_wordpress_test' );

// This file is also loaded on its own by the installer subprocess of the framework
// (wordpress-develop/tests/phpunit/includes/install.php): it must not depend on the bootstrap.
$kkce_wp_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $kkce_wp_tests_dir || '' === $kkce_wp_tests_dir ) {
	$kkce_wp_tests_dir = 'C:/WordpressDEV/wordpress-develop/tests/phpunit';
}
$kkce_wp_tests_dir = rtrim( str_replace( '\\', '/', $kkce_wp_tests_dir ), '/' );

// WordPress sources of the wordpress-develop checkout that provides the test framework.
define( 'ABSPATH', dirname( $kkce_wp_tests_dir, 2 ) . '/src/' );

// The plugin is loaded from its own folder: its parent acts as the plugins directory, so that
// plugin_basename() returns `kk-content-exporter/kk-content-exporter.php` as on a real site.
define( 'WP_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__, 3 ) ) );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', KKCE_TEST_DB_NAME );
define( 'DB_USER', kkce_test_setting( 'KKCE_TEST_DB_USER', 'user', 'root' ) );
define( 'DB_PASSWORD', kkce_test_setting( 'KKCE_TEST_DB_PASSWORD', 'password', '' ) );
define( 'DB_HOST', kkce_test_setting( 'KKCE_TEST_DB_HOST', 'host', 'localhost' ) );
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

$table_prefix = 'wptests_'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Required name of the test framework.

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'KKCE Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );

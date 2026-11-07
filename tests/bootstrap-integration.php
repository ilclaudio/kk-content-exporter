<?php
/**
 * Bootstrap of the integration test suite.
 *
 * Loads the WordPress test framework of a `wordpress-develop` checkout, with the configuration
 * in tests/integration/wp-tests-config.php (dedicated database `kkce_wordpress_test`), and loads
 * the plugin on `muplugins_loaded`, before WordPress finishes booting.
 *
 * Environment variables (all optional on the maintainer machine):
 * - WP_TESTS_DIR: `tests/phpunit` folder of wordpress-develop
 *   (default `C:/WordpressDEV/wordpress-develop/tests/phpunit`).
 * - WP_TESTS_CONFIG_FILE_PATH: alternative `wp-tests-config.php`.
 * - KKCE_TEST_DB_HOST, KKCE_TEST_DB_USER, KKCE_TEST_DB_PASSWORD: database connection
 *   (see tests/integration/README.md).
 *
 * @package KKCE
 */

define( 'KKCE_TEST_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__ ) ) );

$kkce_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $kkce_tests_dir || '' === $kkce_tests_dir ) {
	$kkce_tests_dir = 'C:/WordpressDEV/wordpress-develop/tests/phpunit';
}
$kkce_tests_dir = rtrim( str_replace( '\\', '/', $kkce_tests_dir ), '/' );

if ( ! file_exists( $kkce_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test framework not found in $kkce_tests_dir (set WP_TESTS_DIR).\n" );
	exit( 1 );
}

$kkce_config = getenv( 'WP_TESTS_CONFIG_FILE_PATH' );
if ( false === $kkce_config || '' === $kkce_config ) {
	$kkce_config = __DIR__ . '/integration/wp-tests-config.php';
}
define( 'WP_TESTS_CONFIG_FILE_PATH', $kkce_config );

// Safety net: the framework drops every table of the configured database. Load the configuration
// first (the framework then skips it, it uses require_once) and refuse any database but the
// dedicated one.
if ( ! is_readable( WP_TESTS_CONFIG_FILE_PATH ) ) {
	fwrite( STDERR, 'Test configuration not found: ' . WP_TESTS_CONFIG_FILE_PATH . "\n" );
	exit( 1 );
}
require_once WP_TESTS_CONFIG_FILE_PATH;
if ( ! defined( 'DB_NAME' ) || 'kkce_wordpress_test' !== DB_NAME ) {
	fwrite( STDERR, "Refusing to run: DB_NAME must be 'kkce_wordpress_test' (the framework empties the database).\n" );
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', KKCE_TEST_PLUGIN_DIR . '/vendor/yoast/phpunit-polyfills' );

require_once KKCE_TEST_PLUGIN_DIR . '/vendor/autoload.php';
require_once $kkce_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin before WordPress boots, as an active plugin would be.
 *
 * @return void
 */
function kkce_tests_load_plugin() {
	require KKCE_TEST_PLUGIN_DIR . '/kk-content-exporter.php';
}
tests_add_filter( 'muplugins_loaded', 'kkce_tests_load_plugin' );

require $kkce_tests_dir . '/includes/bootstrap.php';

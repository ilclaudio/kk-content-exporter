<?php
/**
 * Bootstrap of the integration test suite.
 *
 * Runs inside the CLI container of the wp-env test environment (`composer run test:integration`, see
 * tests/integration/README.md). Loads the WordPress test framework that wp-env provides (`WP_TESTS_DIR`),
 * with the configuration in tests/integration/wp-tests-config.php (dedicated database
 * `kkce_wordpress_test`), and loads the plugin on `muplugins_loaded`, before WordPress finishes booting.
 *
 * Environment variables:
 * - WP_TESTS_DIR: folder of the WordPress test framework (set by wp-env).
 * - WP_TESTS_CONFIG_FILE_PATH: alternative `wp-tests-config.php`.
 * - KKCE_TEST_DB_HOST, KKCE_TEST_DB_USER, KKCE_TEST_DB_PASSWORD, KKCE_TEST_ABSPATH: see
 *   tests/integration/wp-tests-config.php.
 *
 * @package KKCE
 */

define( 'KKCE_TEST_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__ ) ) );

$kkce_tests_dir = rtrim( str_replace( '\\', '/', (string) getenv( 'WP_TESTS_DIR' ) ), '/' );

if ( '' === $kkce_tests_dir || ! file_exists( $kkce_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test framework not found: run the suite with `composer run test:integration` (it runs in the wp-env test environment, see tests/integration/README.md).\n" );
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
	add_filter( 'upload_dir', 'kkce_tests_upload_dir' );
	require KKCE_TEST_PLUGIN_DIR . '/kk-content-exporter.php';
}
tests_add_filter( 'muplugins_loaded', 'kkce_tests_load_plugin' );

/**
 * Keeps all test uploads inside the repository, without writing to the WordPress of the container.
 *
 * @param array<string, mixed> $uploads Upload directory configuration.
 * @return array<string, mixed>
 */
function kkce_tests_upload_dir( array $uploads ): array {
	$directory = KKCE_TEST_PLUGIN_DIR . '/.phpunit.cache/integration-uploads';
	wp_mkdir_p( $directory );
	$uploads['basedir'] = $directory;
	$uploads['path']    = $directory . $uploads['subdir'];

	return $uploads;
}

require $kkce_tests_dir . '/includes/bootstrap.php';

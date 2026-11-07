<?php
/**
 * Bootstrap of the unit test suite.
 *
 * The unit tests run without WordPress. WordPress functions are mocked per test with Brain Monkey
 * (see tests/unit/support/class-kkce-unit-test-case.php). The plugin main file is not loaded,
 * because it registers hooks as soon as it is included: the classes are reached through the
 * plugin autoloader and the constants of the main file are defined here.
 *
 * @package KKCE
 */

define( 'KKCE_TEST_PLUGIN_DIR', str_replace( '\\', '/', dirname( __DIR__ ) ) );

// The plugin files exit when ABSPATH is not defined.
define( 'ABSPATH', KKCE_TEST_PLUGIN_DIR . '/tests/unit/fake-wordpress/' );

define( 'KKCE_VERSION', '0.0.0-unit' );
define( 'KKCE_PLUGIN_FILE', KKCE_TEST_PLUGIN_DIR . '/kk-content-exporter.php' );
define( 'KKCE_PLUGIN_DIR', KKCE_TEST_PLUGIN_DIR . '/' );

require_once KKCE_TEST_PLUGIN_DIR . '/vendor/autoload.php';
require_once KKCE_TEST_PLUGIN_DIR . '/includes/class-autoloader.php';

\KKCE\Autoloader::register();

require_once __DIR__ . '/unit/support/class-kkce-unit-test-case.php';

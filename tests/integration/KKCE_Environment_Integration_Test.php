<?php
/**
 * Smoke tests of the integration environment: the plugin loads inside the WordPress test framework.
 *
 * @package KKCE
 */

use KKCE\Plugin;

/**
 * The plugin is loaded, wired and translatable on a real WordPress.
 */
class KKCE_Environment_Integration_Test extends WP_UnitTestCase {

	/**
	 * The main file was loaded and the plugin constants and classes are available.
	 */
	public function test_plugin_is_loaded() {
		$this->assertTrue( defined( 'KKCE_VERSION' ) );
		$this->assertTrue( class_exists( Plugin::class ) );
		// The folder name is chosen at install time; the main file name is fixed.
		$this->assertSame( basename( dirname( KKCE_PLUGIN_FILE ) ) . '/kk-content-exporter.php', plugin_basename( KKCE_PLUGIN_FILE ) );
		// The main file wires boot(); the hooks boot() adds are covered by KKCE_Plugin_Unit_Test.
		$this->assertSame( 10, has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
	}
}

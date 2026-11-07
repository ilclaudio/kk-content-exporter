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

	/**
	 * The bundled Italian translation is used when the locale is it_IT
	 * (load_plugin_textdomain() on init, see AGENTS/CODING_STANDARDS.md).
	 */
	public function test_bundled_italian_translation_is_loaded() {
		// switch_to_locale() needs the core it_IT language pack, absent from the test checkout:
		// force the locale instead and reload the text domain as init does.
		$force_italian = static function () {
			return 'it_IT';
		};
		add_filter( 'determine_locale', $force_italian );
		add_filter( 'locale', $force_italian );
		unload_textdomain( Plugin::TEXT_DOMAIN );
		Plugin::load_textdomain();

		try {
			$this->assertSame(
				'Versione di sviluppo: non è ancora possibile configurare feed.',
				Plugin::development_notice()
			);
		} finally {
			remove_filter( 'determine_locale', $force_italian );
			remove_filter( 'locale', $force_italian );
			unload_textdomain( Plugin::TEXT_DOMAIN );
			Plugin::load_textdomain();
		}

		$this->assertSame( 'Development version: no feeds can be configured yet.', Plugin::development_notice() );
	}
}

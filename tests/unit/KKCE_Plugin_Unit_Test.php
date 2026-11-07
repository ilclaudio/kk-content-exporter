<?php
/**
 * Unit tests of the main plugin class, with WordPress mocked by Brain Monkey.
 *
 * @package KKCE
 */

use Brain\Monkey\Functions;
use KKCE\Plugin;

/**
 * Hook registration and the Plugins screen row.
 */
class KKCE_Plugin_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * boot() registers the text domain loader on init and the row meta filter.
	 */
	public function test_boot_registers_hooks_without_translating_early() {
		Functions\expect( '__' )->never();

		Plugin::boot();

		$this->assertSame( 10, has_action( 'init', array( Plugin::class, 'load_textdomain' ) ) );
		$this->assertSame( 10, has_filter( 'plugin_row_meta', array( Plugin::class, 'filter_plugin_row_meta' ) ) );
	}

	/**
	 * The text domain is loaded from the languages folder of the plugin.
	 */
	public function test_load_textdomain_uses_the_bundled_languages_folder() {
		Functions\when( 'plugin_basename' )->justReturn( 'kk-content-exporter/kk-content-exporter.php' );
		Functions\expect( 'load_plugin_textdomain' )
			->once()
			->with( 'kk-content-exporter', false, 'kk-content-exporter/languages' );

		Plugin::load_textdomain();
	}

	/**
	 * The development notice is added only to the row of this plugin.
	 */
	public function test_row_meta_note_is_added_only_for_this_plugin() {
		Functions\when( 'plugin_basename' )->justReturn( 'kk-content-exporter/kk-content-exporter.php' );

		$own   = Plugin::filter_plugin_row_meta( array( 'Version 0.0.1' ), 'kk-content-exporter/kk-content-exporter.php' );
		$other = Plugin::filter_plugin_row_meta( array( 'Version 1.0' ), 'hello-dolly/hello.php' );

		$this->assertSame( array( 'Version 0.0.1', Plugin::development_notice() ), $own );
		$this->assertSame( array( 'Version 1.0' ), $other );
	}

	/**
	 * A row meta value that is not an array is returned unchanged.
	 */
	public function test_row_meta_with_unexpected_type_is_returned_unchanged() {
		Functions\when( 'plugin_basename' )->justReturn( 'kk-content-exporter/kk-content-exporter.php' );

		$this->assertSame( 'not-an-array', Plugin::filter_plugin_row_meta( 'not-an-array', 'kk-content-exporter/kk-content-exporter.php' ) );
	}
}

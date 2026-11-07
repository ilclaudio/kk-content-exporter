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
		$this->assertNotFalse( has_action( 'init', array( \KKCE\Feed_Post_Type::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'init', array( \KKCE\Feed_Post_Type::class, 'register_meta' ) ) );
		$this->assertSame( 10, has_filter( 'manage_kkce_feed_posts_columns', array( \KKCE\Admin\Feed_List::class, 'columns' ) ) );
		$this->assertSame( 10, has_action( 'manage_kkce_feed_posts_custom_column', array( \KKCE\Admin\Feed_List::class, 'render_column' ) ) );
	}

	/**
	 * Activation grants the export capability to the administrator role, once.
	 */
	public function test_activate_grants_the_export_capability_to_administrators() {
		$role = \Mockery::mock();
		$role->shouldReceive( 'add_cap' )->once()->with( 'kkce_read_export' );

		Functions\expect( 'get_role' )->once()->with( 'administrator' )->andReturn( $role );

		Plugin::activate();

		$this->assertSame( 'kkce_read_export', Plugin::READ_EXPORT_CAPABILITY );
	}

	/**
	 * Activation does not fail when the administrator role does not exist.
	 */
	public function test_activate_does_nothing_without_the_administrator_role() {
		Functions\expect( 'get_role' )->once()->with( 'administrator' )->andReturn( null );

		Plugin::activate();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Deactivation never touches roles or capabilities.
	 */
	public function test_deactivate_does_not_touch_roles() {
		Functions\expect( 'get_role' )->never();
		Functions\expect( 'remove_role' )->never();

		Plugin::deactivate();

		$this->addToAssertionCount( 1 );
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

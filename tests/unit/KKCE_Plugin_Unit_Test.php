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
	 * boot() registers the hooks of the plugin, and no loader of translation files: WordPress loads the
	 * language packs of translate.wordpress.org by itself.
	 */
	public function test_boot_registers_hooks_without_translating_early() {
		Functions\expect( '__' )->never();

		Plugin::boot();

		$this->assertFalse( method_exists( Plugin::class, 'load_textdomain' ) );
		// Version 1.0.0: no development note in the row of the plugin any more.
		$this->assertFalse( has_filter( 'plugin_row_meta' ) );
		$this->assertNotFalse( has_action( 'init', array( \KKCE\Feed_Post_Type::class, 'register' ) ) );
		$this->assertNotFalse( has_action( 'init', array( \KKCE\Feed_Meta::class, 'register' ) ) );
		$this->assertSame( 10, has_action( 'untrashed_post', array( \KKCE\Feed_Post_Type::class, 'disable_restored_feed' ) ) );
		$this->assertSame( 10, has_action( 'admin_menu', array( \KKCE\Admin\Settings_Page::class, 'add_menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( \KKCE\Admin\Settings_Page::class, 'register_settings' ) ) );
		$this->assertSame( 10, has_action( 'admin_menu', array( \KKCE\Admin\Guide_Page::class, 'add_menu' ) ) );
		$this->assertSame( 10, has_filter( 'manage_kkce_feed_posts_columns', array( \KKCE\Admin\Feed_List::class, 'columns' ) ) );
		$this->assertSame( 10, has_action( 'manage_kkce_feed_posts_custom_column', array( \KKCE\Admin\Feed_List::class, 'render_column' ) ) );
		$this->assertSame( 10, has_filter( 'post_row_actions', array( \KKCE\Admin\Feed_List::class, 'row_actions' ) ) );
		$this->assertSame( 10, has_action( 'add_meta_boxes_kkce_feed', array( \KKCE\Admin\Feed_Editor::class, 'add_meta_box' ) ) );
		$this->assertSame( 9, has_filter( 'wp_insert_post_data', array( \KKCE\Feed_Post_Type::class, 'protect_slug' ) ), 'Before the editor.' );
		$this->assertSame( 10, has_filter( 'wp_insert_post_data', array( \KKCE\Admin\Feed_Editor::class, 'filter_post_data' ) ) );
		$this->assertSame( 10, has_action( 'save_post_kkce_feed', array( \KKCE\Admin\Feed_Editor::class, 'save' ) ) );
		$this->assertSame( 10, has_action( 'admin_notices', array( \KKCE\Admin\Feed_Editor::class, 'render_notices' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( \KKCE\Admin\Feed_Editor::class, 'enqueue_assets' ) ) );
		$this->assertSame( 10, has_action( 'rest_api_init', array( \KKCE\Rest\Admin_Routes::class, 'register' ) ) );
		$this->assertSame( 10, has_action( 'rest_api_init', array( \KKCE\Rest\Feed_Routes::class, 'register' ) ) );
		$this->assertSame( 10, has_filter( 'rest_post_dispatch', array( \KKCE\Rest\Feed_Routes::class, 'authentication_challenge' ) ) );
		$this->assertSame( 10, has_action( 'add_meta_boxes_kkce_feed', array( \KKCE\Admin\Mapping_Box::class, 'add_meta_box' ) ) );
		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( \KKCE\Admin\Mapping_Box::class, 'enqueue_assets' ) ) );
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
}

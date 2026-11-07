<?php
/**
 * Integration tests of the activation and deactivation of the plugin.
 *
 * @package KKCE
 */

use KKCE\Plugin;

/**
 * The export capability is granted to administrators on activation, idempotently, and kept on
 * deactivation.
 */
class KKCE_Activation_Integration_Test extends WP_UnitTestCase {

	/**
	 * Starts from a clean role: the capability is removed before each test.
	 */
	public function set_up() {
		parent::set_up();

		$this->remove_capability();
	}

	/**
	 * Roles live in memory and in an option: the capability is removed so that no other test sees it.
	 */
	public function tear_down() {
		$this->remove_capability();

		parent::tear_down();
	}

	/**
	 * Removes the export capability from the administrator role.
	 */
	private function remove_capability(): void {
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			$role->remove_cap( Plugin::READ_EXPORT_CAPABILITY );
		}
	}

	/**
	 * Before activation nobody has the capability; after it only administrators do.
	 */
	public function test_activation_grants_the_capability_to_administrators_only() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->assertFalse( user_can( $admin, 'kkce_read_export' ) );

		Plugin::activate();

		$this->assertTrue( user_can( $admin, 'kkce_read_export' ) );
		$this->assertFalse( user_can( $editor, 'kkce_read_export' ) );
		$this->assertFalse( user_can( 0, 'kkce_read_export' ) );
	}

	/**
	 * Activating twice leaves the same state and does not fail.
	 */
	public function test_activation_is_idempotent() {
		Plugin::activate();
		$once = get_role( 'administrator' )->capabilities;

		Plugin::activate();
		$twice = get_role( 'administrator' )->capabilities;

		$this->assertSame( $once, $twice );
		$this->assertTrue( $twice['kkce_read_export'] );
	}

	/**
	 * Activation creates no content.
	 */
	public function test_activation_creates_no_content() {
		$before = wp_count_posts( 'kkce_feed' );

		Plugin::activate();

		$this->assertEquals( $before, wp_count_posts( 'kkce_feed' ) );
		$this->assertSame( 0, (int) wp_count_posts( 'kkce_feed' )->publish );
	}

	/**
	 * Deactivation keeps the capability and the feeds: only uninstall removes data.
	 */
	public function test_deactivation_keeps_capability_and_feeds() {
		Plugin::activate();
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		Plugin::deactivate();

		$this->assertArrayHasKey( 'kkce_read_export', get_role( 'administrator' )->capabilities );
		$this->assertSame( 'kkce_feed', get_post_type( $feed ) );
	}
}

<?php
/**
 * Integration tests of the removal of the plugin data when the plugin is deleted.
 *
 * @package KKCE
 */

use KKCE\Feed_Post_Type;
use KKCE\Plugin;
use KKCE\Settings;
use KKCE\Uninstaller;

/**
 * Nothing is removed unless the administrator chose it; then feeds, settings and capability go.
 */
class KKCE_Uninstaller_Integration_Test extends WP_UnitTestCase {

	/**
	 * Feeds created by the test, one per status.
	 *
	 * @var list<int>
	 */
	private array $feeds = array();

	/**
	 * A regular post with a meta key of the plugin, which must never be touched.
	 *
	 * @var int
	 */
	private int $other_post = 0;

	/**
	 * A user who received the capability directly.
	 *
	 * @var int
	 */
	private int $user = 0;

	/**
	 * Creates the data that the plugin leaves on a site: feeds in every status, the capability on the
	 * administrator role and on a user.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Post_Type::register_meta();
		Plugin::activate();

		$this->feeds = array();
		foreach ( array( 'publish', 'draft', 'private' ) as $status ) {
			$id = self::factory()->post->create( array( 'post_type' => 'kkce_feed', 'post_status' => $status ) );
			update_post_meta( $id, '_kkce_access', 'authenticated' );
			update_post_meta( $id, '_kkce_per_page', 20 );
			$this->feeds[] = $id;
		}
		$trashed = self::factory()->post->create( array( 'post_type' => 'kkce_feed', 'post_status' => 'publish' ) );
		update_post_meta( $trashed, '_kkce_active', true );
		wp_trash_post( $trashed );
		$this->feeds[] = $trashed;

		$this->other_post = self::factory()->post->create( array( 'post_title' => 'Not a feed' ) );
		update_post_meta( $this->other_post, '_kkce_access', 'keep me' );

		$this->user = self::factory()->user->create( array( 'role' => 'editor' ) );
		( new WP_User( $this->user ) )->add_cap( Plugin::READ_EXPORT_CAPABILITY );
	}

	/**
	 * Roles live in memory and in an option: the capability is removed so that no other test sees it.
	 */
	public function tear_down() {
		$role = get_role( 'administrator' );

		if ( null !== $role ) {
			$role->remove_cap( Plugin::READ_EXPORT_CAPABILITY );
		}
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Number of rows of post meta that belong to the feeds created by the test.
	 *
	 * @return int
	 */
	private function feed_meta_rows(): int {
		global $wpdb;

		$ids = implode( ',', array_map( 'intval', $this->feeds ) );

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id IN ({$ids})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Integers only.
	}

	/**
	 * Number of feeds left, in every status.
	 *
	 * @return int
	 */
	private function feeds_left(): int {
		return count(
			get_posts(
				array(
					'post_type'   => 'kkce_feed',
					'post_status' => get_post_stati(),
					'numberposts' => 100,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * Whether anything of the plugin is still on the site.
	 *
	 * @return bool
	 */
	private function something_is_left(): bool {
		return 0 !== $this->feeds_left()
			|| 0 !== $this->feed_meta_rows()
			|| false !== get_option( Settings::OPTION, false )
			|| user_can( $this->user, Plugin::READ_EXPORT_CAPABILITY )
			|| get_role( 'administrator' )->has_cap( Plugin::READ_EXPORT_CAPABILITY );
	}

	/**
	 * Without the setting, deleting the plugin leaves everything in place.
	 */
	public function test_nothing_is_removed_when_the_setting_was_never_saved() {
		Uninstaller::run();

		$this->assertSame( 4, $this->feeds_left() );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Plugin::READ_EXPORT_CAPABILITY ) );
		$this->assertTrue( user_can( $this->user, Plugin::READ_EXPORT_CAPABILITY ) );
	}

	/**
	 * With the removal turned off, deleting the plugin leaves everything in place, settings included.
	 */
	public function test_nothing_is_removed_when_the_setting_is_off() {
		update_option( Settings::OPTION, array( 'enabled' => '1', 'max_per_page' => '250' ) );

		Uninstaller::run();

		$this->assertSame( 4, $this->feeds_left() );
		$this->assertSame( 250, Settings::get()['max_per_page'] );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Plugin::READ_EXPORT_CAPABILITY ) );
	}

	/**
	 * A damaged value of the setting never counts as "remove".
	 */
	public function test_a_damaged_setting_removes_nothing() {
		update_option( Settings::OPTION, array( 'remove_data' => 'yes please' ) );
		// The sanitizer turns it off; store the raw damaged value to be sure the reader is safe too.
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => serialize( array( 'remove_data' => 'yes please' ) ) ), array( 'option_name' => Settings::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( Settings::OPTION, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		Uninstaller::run();

		$this->assertSame( 4, $this->feeds_left() );
	}

	/**
	 * With the removal turned on, feeds in every status, their meta, the settings and the capability go.
	 */
	public function test_everything_is_removed_when_the_setting_is_on() {
		update_option( Settings::OPTION, array( 'enabled' => '1', 'remove_data' => '1' ) );
		$this->assertTrue( $this->something_is_left(), 'The test starts with data on the site.' );

		Uninstaller::run();

		$this->assertSame( 0, $this->feeds_left(), 'Feeds in every status are deleted, the trash included.' );
		$this->assertSame( 0, $this->feed_meta_rows(), 'Their meta are deleted too.' );
		$this->assertFalse( get_option( Settings::OPTION, false ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Plugin::READ_EXPORT_CAPABILITY ) );
		$this->assertFalse( user_can( $this->user, Plugin::READ_EXPORT_CAPABILITY ) );
		$this->assertFalse( $this->something_is_left() );
	}

	/**
	 * The removal does not touch anything that is not a feed, not even a meta key with the same name.
	 */
	public function test_other_content_is_not_touched() {
		update_option( Settings::OPTION, array( 'remove_data' => '1' ) );

		Uninstaller::run();

		$this->assertSame( 'Not a feed', get_post_field( 'post_title', $this->other_post ) );
		$this->assertSame( 'keep me', get_post_meta( $this->other_post, '_kkce_access', true ) );
		$this->assertTrue( user_can( self::factory()->user->create( array( 'role' => 'administrator' ) ), 'manage_options' ), 'Other capabilities are kept.' );
	}

	/**
	 * Running the removal twice is harmless.
	 */
	public function test_the_removal_can_run_twice() {
		update_option( Settings::OPTION, array( 'remove_data' => '1' ) );

		Uninstaller::run();
		Uninstaller::run();

		$this->assertFalse( $this->something_is_left() );
	}

	/**
	 * The uninstall file loads the classes by itself, because the plugin is not loaded when it runs.
	 */
	public function test_the_uninstall_file_runs_the_removal() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'kk-content-exporter/kk-content-exporter.php' );
		}
		update_option( Settings::OPTION, array( 'remove_data' => '1' ) );

		include KKCE_TEST_PLUGIN_DIR . '/uninstall.php';

		$this->assertFalse( $this->something_is_left() );
	}
}

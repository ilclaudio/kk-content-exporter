<?php
/**
 * Integration tests of the protection of the slug of a feed outside its editor.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;

/**
 * The slug of a feed is its public address: only the editor of the feed changes it.
 */
class KKCE_Feed_Slug_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the shared administrator once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Registers the meta and signs in the administrator.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
		wp_set_current_user( self::$admin );
	}

	/**
	 * A published, enabled feed with the given slug.
	 *
	 * @param string $slug Slug.
	 * @return int
	 */
	private function feed( string $slug ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kkce_feed',
				'post_status' => 'publish',
				'post_title'  => 'Feed ' . $slug,
				'post_name'   => $slug,
			)
		);
		update_post_meta( $id, Feed_Meta::META_ACTIVE, true );

		return $id;
	}

	/**
	 * Regression: Quick Edit is removed from the row actions, but a crafted `inline-save` request still reaches
	 * `edit_post()` with a `post_name`. The slug of the feed, and so its address, stays the saved one.
	 */
	public function test_a_quick_edit_request_does_not_change_the_slug() {
		$feed = $this->feed( 'news' );

		edit_post(
			array(
				'post_ID'     => $feed,
				'post_type'   => 'kkce_feed',
				'post_title'  => 'Renamed feed',
				'post_name'   => 'other',
				'post_status' => 'publish',
			)
		);

		$this->assertSame( 'news', get_post( $feed )->post_name );
		$this->assertSame( 'Renamed feed', get_post( $feed )->post_title, 'The other changes are kept.' );
	}

	/**
	 * Any other write outside the editor (code, WP-CLI, another plugin) keeps the slug too.
	 */
	public function test_an_update_outside_the_editor_does_not_change_the_slug() {
		$feed = $this->feed( 'news' );

		wp_update_post(
			array(
				'ID'        => $feed,
				'post_name' => 'other',
			)
		);

		$this->assertSame( 'news', get_post( $feed )->post_name );
	}

	/**
	 * Moving a feed to the trash and restoring it still works: WordPress moves the slug aside in the trash and
	 * gives it back on restore.
	 */
	public function test_trash_and_restore_keep_working() {
		$feed = $this->feed( 'news' );

		wp_trash_post( $feed );
		$this->assertSame( 'news__trashed', get_post( $feed )->post_name );

		wp_untrash_post( $feed );
		$this->assertSame( 'news', get_post( $feed )->post_name );
	}

	/**
	 * Regression: feed A uses `news` and is trashed, feed B takes `news`, A is restored. WordPress restores A as a
	 * draft with its old slug (drafts are not made unique), so two feeds would share one address.
	 */
	public function test_a_restored_feed_does_not_take_the_address_of_another_feed() {
		$first = $this->feed( 'news' );
		wp_trash_post( $first );
		$second = $this->feed( 'news' );

		wp_untrash_post( $first );

		$this->assertSame( 'news', get_post( $second )->post_name, 'The other feed keeps its address.' );
		$this->assertSame( '', get_post( $first )->post_name, 'Two feeds never share an address: the restored one has none.' );
		$this->assertSame( 'news', get_post_meta( $first, '_kkce_taken_slug', true ), 'The slug it could not get back is kept for the editor.' );
		$this->assertFalse( (bool) get_post_meta( $first, '_kkce_active', true ), 'It stays disabled.' );
	}
}

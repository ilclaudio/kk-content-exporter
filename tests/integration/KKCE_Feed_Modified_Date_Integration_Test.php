<?php
/**
 * Integration tests of the modified date that an importer reads with `modified_after`.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Settings;

/**
 * Which changes of an item move its modified date, and so make it appear again in a feed read with
 * `modified_after`: a save of the post (the editors, the REST API of WordPress) does; a change made only to its
 * meta, terms or featured image through the PHP functions of WordPress does not. This is a known limit of the
 * incremental reading, documented for the importer.
 */
class KKCE_Feed_Modified_Date_Integration_Test extends WP_UnitTestCase {

	/**
	 * Moment of the last reading of the importer.
	 */
	private const LAST_READING = '2026-01-01T00:00:00Z';

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the administrator once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Registers the meta again (the test case unregisters them), a meta shown in REST, and the REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Feed_Meta::register();
		register_post_meta( 'post', 'kkce_price', array( 'type' => 'string', 'single' => true, 'show_in_rest' => true ) );
		wp_set_current_user( 0 );
		delete_option( Settings::OPTION );

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$feed = self::factory()->post->create(
			array(
				'post_type'   => Feed_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => 'news',
			)
		);
		add_post_meta( $feed, Feed_Meta::META_POST_TYPE, 'post' );
		update_post_meta( $feed, Feed_Meta::META_ACTIVE, '1' );
		update_post_meta( $feed, Feed_Meta::META_ACCESS, Feed_Meta::ACCESS_PUBLIC );
		Feed_Meta::save_mapping(
			$feed,
			(string) wp_json_encode(
				array(
					'version' => 1,
					'rows'    => array( array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ) ),
				)
			)
		);
	}

	/**
	 * Drops the REST server and the meta.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		unregister_meta_key( 'post', 'kkce_price', 'post' );
		parent::tear_down();
	}

	/**
	 * A published item last modified before the last reading of the importer.
	 *
	 * @return int
	 */
	private function old_item(): int {
		global $wpdb;

		$id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => '2025-06-01 10:00:00',
				'post_modified_gmt' => '2025-06-01 10:00:00',
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );

		return $id;
	}

	/**
	 * Whether the item is in the feed read with `modified_after` the last reading.
	 *
	 * @param int $id Item.
	 * @return bool
	 */
	private function changed_since_last_reading( int $id ): bool {
		$request = new WP_REST_Request( 'GET', '/kkce/v1/news' );
		$request->set_query_params( array( 'modified_after' => self::LAST_READING ) );
		$ids = array_map( static fn( $item ) => $item->id, rest_do_request( $request )->get_data()['items'] );

		return in_array( $id, $ids, true );
	}

	/**
	 * Changes made only through the PHP functions of WordPress keep the modified date: the item does not appear.
	 */
	public function test_meta_terms_and_image_changed_by_code_keep_the_modified_date() {
		$meta  = $this->old_item();
		$terms = $this->old_item();
		$image = $this->old_item();
		$this->assertFalse( $this->changed_since_last_reading( $meta ) );

		update_post_meta( $meta, 'kkce_price', '10' );
		wp_set_post_terms( $terms, array( self::factory()->category->create() ), 'category' );
		set_post_thumbnail( $image, self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $image ) );

		$this->assertFalse( $this->changed_since_last_reading( $meta ), 'Meta changed by code.' );
		$this->assertFalse( $this->changed_since_last_reading( $terms ), 'Terms changed by code.' );
		$this->assertFalse( $this->changed_since_last_reading( $image ), 'Featured image changed by code.' );
	}

	/**
	 * A save of the post moves the modified date, also when nothing but meta or terms changed: the classic editor and
	 * the block editor save the post, the block editor through the REST API of WordPress.
	 */
	public function test_a_save_of_the_post_moves_the_modified_date() {
		$saved = $this->old_item();
		$meta  = $this->old_item();
		$terms = $this->old_item();

		// The classic editor and the code that saves the post call wp_update_post().
		wp_update_post( array( 'ID' => $saved ) );

		// The block editor sends the changed meta or terms to the REST route of the post.
		wp_set_current_user( self::$admin );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $meta );
		$request->set_body_params( array( 'meta' => array( 'kkce_price' => '10' ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $terms );
		$request->set_body_params( array( 'categories' => array( self::factory()->category->create() ) ) );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		wp_set_current_user( 0 );

		$this->assertTrue( $this->changed_since_last_reading( $saved ), 'Post saved.' );
		$this->assertTrue( $this->changed_since_last_reading( $meta ), 'Meta saved through the post.' );
		$this->assertTrue( $this->changed_since_last_reading( $terms ), 'Terms saved through the post.' );
	}
}

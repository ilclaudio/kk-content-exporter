<?php
/**
 * Integration tests of the public endpoint of a feed.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Rest\Feed_Routes;
use KKCE\Settings;

/**
 * `GET /kkce/v1/<slug>` serves a published, enabled feed: published items without a password of its post
 * types, computed as an anonymous visitor, in the fixed container.
 */
class KKCE_Feed_Routes_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Post types registered by a test.
	 *
	 * @var string[]
	 */
	private array $registered = array();

	/**
	 * Creates the users once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Registers the meta again (the test case unregisters them), starts as a visitor and with the default settings.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Feed_Meta::register();
		wp_set_current_user( 0 );
		delete_option( Settings::OPTION );
		$this->start_server();
	}

	/**
	 * Drops the REST server and the post types of the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		foreach ( $this->registered as $post_type ) {
			unregister_post_type( $post_type );
		}
		parent::tear_down();
	}

	/**
	 * Fresh REST server with the routes of the plugin, registered with the current settings.
	 *
	 * @return void
	 */
	private function start_server(): void {
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Saves the settings, changing only the given values.
	 *
	 * @param array<string, mixed> $values Values to change.
	 * @return void
	 */
	private function settings( array $values ): void {
		update_option( Settings::OPTION, array_merge( Settings::defaults(), $values ) );
	}

	/**
	 * GET request to a feed.
	 *
	 * @param string $slug Slug in the address.
	 * @return WP_REST_Response
	 */
	private function get( string $slug ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( 'GET', '/kkce/v1/' . $slug ) );
	}

	/**
	 * Creates a feed.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string, mixed> $config Changes to the default configuration: published, enabled, public,
	 *                                     posts only, ID and title of the items.
	 * @return int ID of the feed.
	 */
	private function feed( string $slug, array $config = array() ): int {
		$config = array_merge(
			array(
				'status'     => 'publish',
				'active'     => '1',
				'access'     => Feed_Meta::ACCESS_PUBLIC,
				'post_types' => array( 'post' ),
				'per_page'   => '50',
				'rows'       => array(
					array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ),
					array( 'key' => 'title', 'kind' => 'field', 'sources' => array( '*' => array( 'core.title' ) ) ),
				),
			),
			$config
		);

		$feed = self::factory()->post->create(
			array(
				'post_type'   => Feed_Post_Type::POST_TYPE,
				'post_status' => $config['status'],
				'post_name'   => $slug,
				'post_title'  => 'Feed ' . $slug,
			)
		);

		foreach ( $config['post_types'] as $post_type ) {
			add_post_meta( $feed, Feed_Meta::META_POST_TYPE, $post_type );
		}
		update_post_meta( $feed, Feed_Meta::META_ACTIVE, $config['active'] );
		update_post_meta( $feed, Feed_Meta::META_ACCESS, $config['access'] );
		update_post_meta( $feed, Feed_Meta::META_PER_PAGE, $config['per_page'] );
		if ( null !== $config['rows'] ) {
			Feed_Meta::save_mapping( $feed, (string) wp_json_encode( array( 'version' => 1, 'rows' => $config['rows'] ) ) );
		}

		return $feed;
	}

	/**
	 * A published post.
	 *
	 * @param array<string, mixed> $args Changes to the post.
	 * @return int
	 */
	private function item( array $args = array() ): int {
		return self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) );
	}

	/**
	 * Asserts that a response is the 404 of a feed that cannot be served.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return void
	 */
	private function assert_not_found( WP_REST_Response $response ): void {
		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( Feed_Routes::ERROR_NOT_FOUND, $response->as_error()->get_error_code() );
	}

	/**
	 * The route is registered in the namespace of the feeds, for GET only.
	 */
	public function test_the_route_is_registered_for_get() {
		$routes = rest_get_server()->get_routes();
		$route  = '/kkce/v1/(?P<slug>' . KKCE\Feed_Validator::SLUG_PATTERN . ')';

		$this->assertArrayHasKey( $route, $routes );
		$this->assertSame( array( 'GET' => true ), $routes[ $route ][0]['methods'] );
	}

	/**
	 * With the endpoints switched off in the settings there is no endpoint at all.
	 */
	public function test_no_endpoint_when_the_endpoints_are_switched_off() {
		$this->feed( 'news' );
		$this->settings( array( 'enabled' => false ) );
		$this->start_server();

		$response = $this->get( 'news' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'rest_no_route', $response->as_error()->get_error_code() );
	}

	/**
	 * A published, enabled, public feed gives its published items without a password, in the fixed container.
	 */
	public function test_a_public_feed_gives_its_published_items() {
		$this->feed( 'news' );
		// A new post takes its date as modified date: the later one comes second, whatever its ID.
		$second = $this->item( array( 'post_title' => 'Second', 'post_date' => '2026-01-02 10:00:00' ) );
		$first  = $this->item( array( 'post_title' => 'First', 'post_date' => '2026-01-01 10:00:00' ) );
		$this->item( array( 'post_status' => 'draft' ) );
		$this->item( array( 'post_status' => 'private' ) );
		$this->item( array( 'post_password' => 'secret' ) );
		$this->item( array( 'post_type' => 'page' ) );

		$response = $this->get( 'news' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'items', 'pagination', 'meta' ), array_keys( $data ) );
		$this->assertEquals(
			array(
				(object) array( 'id' => $first, 'title' => 'First' ),
				(object) array( 'id' => $second, 'title' => 'Second' ),
			),
			$data['items']
		);
		$this->assertSame( array( 'page' => 1, 'per_page' => 50, 'total' => 2, 'total_pages' => 1 ), $data['pagination'] );
		$this->assertSame( 'news', $data['meta']['feed'] );
		$this->assertMatchesRegularExpression( '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $data['meta']['generated_at'] );
	}

	/**
	 * A feed without items answers 200 with an empty list and no pages.
	 */
	public function test_a_feed_without_items_gives_an_empty_list() {
		$this->feed( 'news' );

		$response = $this->get( 'news' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['items'] );
		$this->assertSame( array( 'page' => 1, 'per_page' => 50, 'total' => 0, 'total_pages' => 0 ), $response->get_data()['pagination'] );
	}

	/**
	 * A missing, disabled, unpublished or trashed feed, or one without mapping rows, gives the same 404.
	 */
	public function test_a_feed_that_cannot_be_served_gives_404() {
		$this->feed( 'disabled', array( 'active' => '' ) );
		$this->feed( 'draft', array( 'status' => 'draft' ) );
		$this->feed( 'pending', array( 'status' => 'pending' ) );
		$this->feed( 'private', array( 'status' => 'private' ) );
		$this->feed( 'no-rows', array( 'rows' => array() ) );
		$this->feed( 'no-mapping', array( 'rows' => null ) );
		$trashed = $this->feed( 'trashed' );
		wp_trash_post( $trashed );
		// Damaged in the database, past the sanitizer of the meta: the mapping is checked again when read.
		global $wpdb;
		$damaged = $this->feed( 'damaged' );
		$wpdb->update( $wpdb->postmeta, array( 'meta_value' => '{"version": 1, "rows": "broken"' ), array( 'post_id' => $damaged, 'meta_key' => Feed_Meta::META_MAPPING ) );
		wp_cache_delete( $damaged, 'post_meta' );

		foreach ( array( 'missing', 'disabled', 'draft', 'pending', 'private', 'no-rows', 'no-mapping', 'trashed', 'damaged' ) as $slug ) {
			$this->assert_not_found( $this->get( $slug ) );
		}
	}

	/**
	 * The slug of the address is exact: letters in capitals or another form never reach a feed.
	 */
	public function test_the_slug_must_match_exactly() {
		$this->feed( 'news' );

		$this->assert_not_found( $this->get( 'NEWS' ) );
		$this->assert_not_found( $this->get( 'News' ) );
	}

	/**
	 * An authenticated feed, or any feed when the settings require authentication, answers 401 for now,
	 * also to an administrator: the authentication of the feeds is not available yet.
	 */
	public function test_feeds_that_need_authentication_give_401() {
		$this->feed( 'private-feed', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->feed( 'news' );
		$this->item();

		$this->assertSame( 401, $this->get( 'private-feed' )->get_status() );
		wp_set_current_user( self::$admin );
		$this->assertSame( 401, $this->get( 'private-feed' )->get_status() );

		wp_set_current_user( 0 );
		$this->settings( array( 'require_auth' => true ) );
		$this->assertSame( 401, $this->get( 'news' )->get_status() );
	}

	/**
	 * Items are computed as a visitor: a signed-in administrator gets the same data, and stays signed in.
	 */
	public function test_items_are_the_same_for_a_signed_in_user() {
		$this->feed(
			'news',
			array(
				'rows' => array(
					array( 'key' => 'body', 'kind' => 'field', 'type' => 'html', 'sources' => array( '*' => array( 'core.content' ) ) ),
				),
			)
		);
		$this->item( array( 'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->' ) );

		$anonymous = $this->get( 'news' )->get_data()['items'];
		wp_set_current_user( self::$admin );
		$signed_in = $this->get( 'news' )->get_data()['items'];

		$this->assertEquals( $anonymous, $signed_in );
		$this->assertSame( self::$admin, get_current_user_id() );
	}

	/**
	 * The first page holds the items per page of the feed, never more than the global maximum.
	 */
	public function test_the_first_page_holds_the_items_per_page_within_the_maximum() {
		$this->feed( 'news', array( 'per_page' => '2' ) );
		$this->item();
		$this->item();
		$this->item();

		$data = $this->get( 'news' )->get_data();
		$this->assertCount( 2, $data['items'] );
		$this->assertSame( array( 'page' => 1, 'per_page' => 2, 'total' => 3, 'total_pages' => 2 ), $data['pagination'] );

		$this->settings( array( 'max_per_page' => 1 ) );
		$data = $this->get( 'news' )->get_data();
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( array( 'page' => 1, 'per_page' => 1, 'total' => 3, 'total_pages' => 3 ), $data['pagination'] );
	}

	/**
	 * Every feed gives only its own post types, with its own mapping.
	 */
	public function test_feeds_are_isolated() {
		$this->feed( 'posts' );
		$this->feed(
			'pages',
			array(
				'post_types' => array( 'page' ),
				'rows'       => array( array( 'key' => 'name', 'kind' => 'field', 'sources' => array( '*' => array( 'core.title' ) ) ) ),
			)
		);
		$post = $this->item( array( 'post_title' => 'A post' ) );
		$this->item( array( 'post_type' => 'page', 'post_title' => 'A page' ) );

		$this->assertEquals( array( (object) array( 'id' => $post, 'title' => 'A post' ) ), $this->get( 'posts' )->get_data()['items'] );
		$this->assertEquals( array( (object) array( 'name' => 'A page' ) ), $this->get( 'pages' )->get_data()['items'] );
	}

	/**
	 * Items of different post types share the response; a saved post type that can no longer be exported is skipped.
	 */
	public function test_several_post_types_and_a_post_type_no_longer_exportable() {
		$this->registered[] = 'kkce_event';
		register_post_type( 'kkce_event', array( 'public' => true, 'label' => 'Events' ) );
		$this->feed( 'mixed', array( 'post_types' => array( 'post', 'page', 'kkce_event' ) ) );
		$this->item( array( 'post_title' => 'A post' ) );
		$this->item( array( 'post_type' => 'page', 'post_title' => 'A page' ) );
		$this->item( array( 'post_type' => 'kkce_event', 'post_title' => 'An event' ) );

		$this->assertSame( 3, $this->get( 'mixed' )->get_data()['pagination']['total'] );

		unregister_post_type( 'kkce_event' );
		$data = $this->get( 'mixed' )->get_data();

		$this->assertSame( 2, $data['pagination']['total'] );
		$this->assertEqualsCanonicalizing( array( 'A post', 'A page' ), array_map( static fn( $item ) => $item->title, $data['items'] ) );
	}
}

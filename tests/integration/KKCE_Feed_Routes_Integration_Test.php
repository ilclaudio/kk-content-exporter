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
		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'], $GLOBALS['wp_rest_application_password_uuid'] );
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
	 * @param string               $slug   Slug in the address.
	 * @param array<string, mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $slug, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/kkce/v1/' . $slug );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * Serves a GET through WordPress's authentication, dispatch and header-sending pipeline.
	 *
	 * Only the final PHP header emission is captured: rest_do_request() alone does not exercise it.
	 *
	 * @param string               $slug      Feed slug or path within the requested namespace.
	 * @param array<string, mixed> $params    Query parameters.
	 * @param string               $namespace REST namespace.
	 * @return array{status: int, headers: array<string, string>} Emitted status and lowercase header names.
	 */
	private function served_get( string $slug, array $params = array(), string $namespace = 'kkce/v1' ): array {
		global $wp_rest_server;

		$wp_rest_server = new class() extends WP_REST_Server {
			/** @var array<string, string> Headers emitted by the real serving pipeline. */
			public array $sent_headers = array();

			/** @var int Emitted HTTP status. */
			public int $sent_status = 200;

			/** Captures a header instead of emitting it from the PHPUnit CLI process. */
			public function send_header( $key, $value ) {
				$this->sent_headers[ strtolower( $key ) ] = $value;
			}

			/** Removes a previously captured header. */
			public function remove_header( $key ) {
				unset( $this->sent_headers[ strtolower( $key ) ] );
			}

			/** Captures the status instead of emitting it from the PHPUnit CLI process. */
			protected function set_status( $code ) {
				$this->sent_status = $code;
			}
		};
		do_action( 'rest_api_init', $wp_rest_server );

		$previous_get    = $_GET;
		$previous_post   = $_POST;
		$previous_method = $_SERVER['REQUEST_METHOD'] ?? null;
		$_GET           = $params;
		$_POST          = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		ob_start();
		try {
			$wp_rest_server->serve_request( '/' . $namespace . '/' . $slug );
			return array( 'status' => $wp_rest_server->sent_status, 'headers' => $wp_rest_server->sent_headers );
		} finally {
			ob_end_clean();
			$_GET  = $previous_get;
			$_POST = $previous_post;
			if ( null === $previous_method ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $previous_method;
			}
		}
	}

	/**
	 * Authenticates the next requests with a new Application Password of a user, the way WordPress does for a
	 * REST request with Basic authentication.
	 *
	 * @param int $user_id User.
	 * @return void
	 */
	private function sign_in_with_application_password( int $user_id ): void {
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		list( $password ) = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Importer' ) );
		$_SERVER['PHP_AUTH_USER'] = get_userdata( $user_id )->user_login;
		$_SERVER['PHP_AUTH_PW']   = $password;

		$authenticated = wp_validate_application_password( false );
		$this->assertSame( $user_id, $authenticated );
		$this->assertNotNull( rest_get_authenticated_app_password() );
		wp_set_current_user( $authenticated );
	}

	/**
	 * Sets the modified date of an item, local and GMT, as WordPress would store them.
	 *
	 * @param int    $post_id ID of the item.
	 * @param string $gmt     Modified date in GMT (`Y-m-d H:i:s`).
	 * @param string $local   Modified date in the time zone of the site.
	 * @return void
	 */
	private function modified( int $post_id, string $gmt, string $local ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => $gmt, 'post_modified' => $local ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	/**
	 * IDs of the items of a response (the feed maps `core.id` to `id`).
	 *
	 * @param WP_REST_Response $response Response.
	 * @return list<int>
	 */
	private function ids( WP_REST_Response $response ): array {
		return array_map( static fn( $item ) => $item->id, $response->get_data()['items'] );
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
	 * Regression: with the type `auto`, a key has the same JSON type in every item of a feed, also when its source
	 * is declared with different types in the post types of the feed (here boolean for posts, text for pages): the
	 * type is decided on the whole feed, text when the declarations differ.
	 */
	public function test_an_automatic_key_has_one_json_type_across_the_post_types_of_the_feed() {
		register_post_meta( 'post', 'kkce_flag', array( 'type' => 'boolean', 'single' => true ) );
		register_post_meta( 'page', 'kkce_flag', array( 'type' => 'string', 'single' => true ) );
		$row = array( 'key' => 'flag', 'kind' => 'field', 'type' => 'auto', 'sources' => array( '*' => array( 'meta.kkce_flag' ) ) );
		$this->feed( 'mixed', array( 'post_types' => array( 'post', 'page' ), 'rows' => array( $row ) ) );
		$post = $this->item();
		$page = $this->item( array( 'post_type' => 'page' ) );
		update_post_meta( $post, 'kkce_flag', '1' );
		update_post_meta( $page, 'kkce_flag', '1' );

		$items = $this->get( 'mixed' )->get_data()['items'];
		$types = array_map( static fn( $item ): string => gettype( ( (array) $item )['flag'] ), $items );

		unregister_meta_key( 'post', 'kkce_flag', 'post' );
		unregister_meta_key( 'post', 'kkce_flag', 'page' );
		$this->assertCount( 2, $items );
		$this->assertSame( array( 'string', 'string' ), $types );
	}

	/**
	 * Regression: with a page size without ceiling (allowed by the settings), a page whose offset does not fit in a
	 * whole number answers like a page after the last one: 200, no items, the real total; never an error.
	 */
	public function test_a_page_beyond_the_integer_range_is_empty() {
		$this->settings( array( 'max_per_page' => PHP_INT_MAX ) );
		$this->feed( 'huge' );
		$this->item();
		$this->item();

		$response = $this->get( 'huge', array( 'page' => 3, 'per_page' => PHP_INT_MAX ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['items'] );
		$this->assertSame( 2, $response->get_data()['pagination']['total'] );
		$this->assertSame( 1, $response->get_data()['pagination']['total_pages'] );
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
	 * The route of the feeds is listed in the REST index by default; the setting hides it from the index of the site
	 * and of the namespace, while the feeds keep answering.
	 */
	public function test_the_setting_shows_or_hides_the_route_in_the_rest_index() {
		$this->feed( 'news' );
		$route = '/kkce/v1/(?P<slug>' . KKCE\Feed_Validator::SLUG_PATTERN . ')';

		$this->assertArrayHasKey( $route, rest_do_request( new WP_REST_Request( 'GET', '/' ) )->get_data()['routes'] );
		$this->assertArrayHasKey( $route, rest_do_request( new WP_REST_Request( 'GET', '/kkce/v1' ) )->get_data()['routes'] );

		$this->settings( array( 'show_in_index' => false ) );
		$this->start_server();

		$this->assertArrayNotHasKey( $route, rest_do_request( new WP_REST_Request( 'GET', '/' ) )->get_data()['routes'] );
		$this->assertArrayNotHasKey( $route, rest_do_request( new WP_REST_Request( 'GET', '/kkce/v1' ) )->get_data()['routes'] );
		$this->assertSame( 200, $this->get( 'news' )->get_status() );
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
	 * An authenticated feed, or any feed when the settings require authentication, answers 401 without an
	 * Application Password: also to an administrator signed in another way (cookie).
	 */
	public function test_feeds_that_need_authentication_give_401_without_an_application_password() {
		$this->feed( 'private-feed', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->feed( 'news' );
		$this->item();

		$response = $this->get( 'private-feed' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( Feed_Routes::ERROR_AUTHENTICATION, $response->as_error()->get_error_code() );

		// Signed in with the session cookie and the capability, but without an Application Password.
		get_userdata( self::$admin )->add_cap( 'kkce_read_export' );
		wp_set_current_user( self::$admin );
		$this->assertTrue( current_user_can( 'kkce_read_export' ) );
		$this->assertSame( 401, $this->get( 'private-feed' )->get_status() );

		wp_set_current_user( 0 );
		$this->settings( array( 'require_auth' => true ) );
		$this->assertSame( 401, $this->get( 'news' )->get_status() );
	}

	/**
	 * With an Application Password, a user with the capability of the feeds reads an authenticated feed, and
	 * any feed when the settings require authentication; a user without it gets 403.
	 */
	public function test_an_application_password_opens_the_feed_to_the_capability() {
		$this->feed( 'private-feed', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->feed( 'news' );
		$this->item();
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $reader )->add_cap( 'kkce_read_export' );
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );

		$this->sign_in_with_application_password( $reader );
		$this->assertSame( 200, $this->get( 'private-feed' )->get_status() );
		$this->settings( array( 'require_auth' => true ) );
		$this->assertSame( 200, $this->get( 'news' )->get_status() );

		$this->sign_in_with_application_password( $other );
		$response = $this->get( 'private-feed' );
		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'rest_forbidden', $response->as_error()->get_error_code() );
		$this->assertSame( 403, $this->get( 'news' )->get_status() );
	}

	/**
	 * The technical user documented in the readme: a role with the capability of the feeds only (not even `read`,
	 * so no access to the dashboard) reads an authenticated feed with its Application Password.
	 */
	public function test_a_role_with_only_the_capability_of_the_feeds_reads_an_authenticated_feed() {
		$this->feed( 'private-feed', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->item();
		add_role( 'kkce_reader', 'Feed reader', array( 'kkce_read_export' => true ) );
		$reader = self::factory()->user->create( array( 'role' => 'kkce_reader' ) );

		$this->assertFalse( user_can( $reader, 'read' ), 'No other capability.' );
		$this->sign_in_with_application_password( $reader );
		$this->assertSame( 200, $this->get( 'private-feed' )->get_status() );

		remove_role( 'kkce_reader' );
	}

	/**
	 * A served 401 advertises Basic authentication; successful and forbidden responses do not challenge.
	 *
	 * @dataProvider served_authentication_cases
	 * @param bool $public         Whether the feed is public.
	 * @param bool $authenticated  Whether the request uses a valid Application Password.
	 * @param bool $capability     Whether its user can read exports.
	 * @param int  $expected_status Expected HTTP status.
	 */
	public function test_served_feed_authentication_headers( bool $public, bool $authenticated, bool $capability, int $expected_status ) {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->feed( 'header-check', array( 'access' => $public ? Feed_Meta::ACCESS_PUBLIC : Feed_Meta::ACCESS_AUTHENTICATED ) );
		$had_auth_status = array_key_exists( 'wp_rest_application_password_status', $GLOBALS );
		$auth_status     = $GLOBALS['wp_rest_application_password_status'] ?? null;
		$GLOBALS['wp_rest_application_password_status'] = null;

		try {
			if ( $authenticated ) {
				$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
				if ( $capability ) {
					get_userdata( $user_id )->add_cap( 'kkce_read_export' );
				}
				$this->sign_in_with_application_password( $user_id );
			}

			$response = $this->served_get( 'header-check' );
			$this->assertSame( $expected_status, $response['status'] );
			if ( 401 === $expected_status ) {
				// RFC 9110 section 15.5.2 requires a challenge; Application Passwords use Basic authentication.
				$this->assertArrayHasKey( 'www-authenticate', $response['headers'], 'A served 401 must advertise its authentication scheme.' );
				$this->assertMatchesRegularExpression( '/^Basic\s+.*\brealm="[^"]+"/i', $response['headers']['www-authenticate'] );
			} else {
				$this->assertArrayNotHasKey( 'www-authenticate', $response['headers'] );
			}
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			if ( $had_auth_status ) {
				$GLOBALS['wp_rest_application_password_status'] = $auth_status;
			} else {
				unset( $GLOBALS['wp_rest_application_password_status'] );
			}
		}
	}

	/**
	 * Header cases complement the existing permission tests by serving the complete REST response.
	 *
	 * @return array<string, array{bool, bool, bool, int}> Access, authentication, capability and status.
	 */
	public function served_authentication_cases(): array {
		return array(
			'authenticated feed without credentials'       => array( false, false, false, 401 ),
			'authenticated feed without export capability' => array( false, true, false, 403 ),
			'authenticated feed with export capability'    => array( false, true, true, 200 ),
			'public feed without credentials'              => array( true, false, false, 200 ),
		);
	}

	/**
	 * An anonymous admin request also needs an applicable challenge on its 401 response.
	 */
	public function test_remaining_401_challenges_for_anonymous_admin_requests() {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			$response = $this->served_get( 'items', array( 'post_type' => 'post' ), 'kkce-admin/v1' );
			$this->assertSame( 401, $response['status'] );
			$this->assertArrayHasKey( 'www-authenticate', $response['headers'] );
			$this->assertStringStartsWith( 'Basic ', $response['headers']['www-authenticate'] );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		}
	}

	/**
	 * Cookie failures go through core authentication before the admin permission callback.
	 * Missing nonces are anonymous (401); invalid or stale nonces are forbidden (403).
	 *
	 * @dataProvider admin_cookie_authentication_cases
	 * @param bool   $logged_in       Whether the cookie still identifies the administrator.
	 * @param string $nonce_kind      Valid, invalid or missing REST nonce.
	 * @param int    $expected_status Served HTTP status.
	 */
	public function test_served_admin_cookie_authentication_headers( bool $logged_in, string $nonce_kind, int $expected_status ) {
		$previous_cookie  = $_COOKIE;
		$previous_request = $_REQUEST;
		$previous_server  = $_SERVER;
		$had_cookie_auth  = array_key_exists( 'wp_rest_auth_cookie', $GLOBALS );
		$cookie_auth      = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$had_app_status   = array_key_exists( 'wp_rest_application_password_status', $GLOBALS );
		$app_status       = $GLOBALS['wp_rest_application_password_status'] ?? null;
		$previous_user    = get_current_user_id();
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			// A previous Application Password test can leave a successful authentication status, which
			// makes core skip cookie authentication. This fixture exercises cookie authentication only.
			$GLOBALS['wp_rest_application_password_status'] = null;
			wp_set_current_user( self::$admin );
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( self::$admin, time() + HOUR_IN_SECONDS, 'logged_in' );
			$nonce = wp_create_nonce( 'wp_rest' );
			$_REQUEST = array();
			unset( $_SERVER['HTTP_X_WP_NONCE'] );
			if ( 'missing' !== $nonce_kind ) {
				$_SERVER['HTTP_X_WP_NONCE'] = 'invalid' === $nonce_kind ? 'deliberately-invalid' : $nonce;
			}
			$GLOBALS['wp_rest_auth_cookie'] = $logged_in;
			if ( ! $logged_in ) {
				unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
				wp_set_current_user( 0 );
			}

			$response = $this->served_get( 'items', array( 'post_type' => 'post' ), 'kkce-admin/v1' );
			$this->assertSame( $expected_status, $response['status'] );
			if ( 401 === $expected_status ) {
				$this->assertStringStartsWith( 'Basic ', $response['headers']['www-authenticate'] );
			} else {
				$this->assertArrayNotHasKey( 'www-authenticate', $response['headers'] );
			}
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			$_COOKIE  = $previous_cookie;
			$_REQUEST = $previous_request;
			$_SERVER  = $previous_server;
			if ( $had_cookie_auth ) {
				$GLOBALS['wp_rest_auth_cookie'] = $cookie_auth;
			} else {
				unset( $GLOBALS['wp_rest_auth_cookie'] );
			}
			if ( $had_app_status ) {
				$GLOBALS['wp_rest_application_password_status'] = $app_status;
			} else {
				unset( $GLOBALS['wp_rest_application_password_status'] );
			}
			wp_set_current_user( $previous_user );
		}
	}

	/**
	 * Cookie and nonce cases, including an editor nonce left over after its session ends.
	 *
	 * @return array<string, array{bool, string, int}> User state, nonce kind and status.
	 */
	public function admin_cookie_authentication_cases(): array {
		return array(
			'valid session and nonce'        => array( true, 'valid', 200 ),
			'invalid nonce'                  => array( true, 'invalid', 403 ),
			'missing nonce with a cookie'    => array( true, 'missing', 401 ),
			'stale nonce after session loss' => array( false, 'valid', 403 ),
			'no session or nonce'            => array( false, 'missing', 401 ),
		);
	}

	/**
	 * Invalid credentials fail before public-feed dispatch, but still require a challenge.
	 */
	public function test_remaining_401_challenges_for_invalid_public_feed_credentials() {
		$this->feed( 'public-auth-error' );
		$had_status = array_key_exists( 'wp_rest_application_password_status', $GLOBALS );
		$old_status = $GLOBALS['wp_rest_application_password_status'] ?? null;
		$user_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Header regression' ) );
			$_SERVER['PHP_AUTH_USER'] = get_userdata( $user_id )->user_login;
			$_SERVER['PHP_AUTH_PW']   = 'deliberately-invalid-test-password';
			$this->assertFalse( wp_validate_application_password( false ) );
			$this->assertWPError( $GLOBALS['wp_rest_application_password_status'] );
			$response = $this->served_get( 'public-auth-error' );
			$this->assertSame( 401, $response['status'] );
			$this->assertArrayHasKey( 'www-authenticate', $response['headers'] );
			$this->assertStringStartsWith( 'Basic ', $response['headers']['www-authenticate'] );
		} finally {
			remove_filter( 'application_password_is_api_request', '__return_true' );
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
			if ( $had_status ) {
				$GLOBALS['wp_rest_application_password_status'] = $old_status;
			} else {
				unset( $GLOBALS['wp_rest_application_password_status'] );
			}
		}
	}

	/**
	 * The global filter must preserve non-KKCE responses, including their existing challenge and body.
	 */
	public function test_authentication_challenge_leaves_unrelated_responses_untouched() {
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		// A foreign endpoint can even be registered inside the namespace: ownership is the callback.
		register_rest_route( 'kkce-admin/v1', '/foreign', array(
			'methods' => 'GET', 'callback' => '__return_empty_array', 'permission_callback' => '__return_true',
		) );
		try {
			foreach ( array( '/wp/v2/posts', '/other/v1/items', '/kkce/v10/news', '/kkce-admin/v1/foreign' ) as $route ) {
				$response = new WP_REST_Response( array( 'code' => 'unchanged' ), 401, array( 'X-Other' => 'keep' ) );
				$headers  = $response->get_headers();
				$result   = Feed_Routes::authentication_challenge( $response, rest_get_server(), new WP_REST_Request( 'GET', $route ) );
				$this->assertSame( $response, $result );
				$this->assertSame( 401, $result->get_status() );
				$this->assertSame( $headers, $result->get_headers(), $route );
				$this->assertSame( array( 'code' => 'unchanged' ), $result->get_data() );
			}
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		}
	}

	/**
	 * Existing challenges are retained and WordPress's disabled authentication feature is respected.
	 */
	public function test_authentication_challenge_respects_existing_headers_and_availability() {
		$this->feed( 'challenge-guards', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$request = new WP_REST_Request( 'GET', '/kkce/v1/challenge-guards' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		try {
			$response = new WP_REST_Response( null, 401, array( 'www-authenticate' => 'Basic realm="Existing"' ) );
			$headers  = $response->get_headers();
			Feed_Routes::authentication_challenge( $response, rest_get_server(), $request );
			$this->assertSame( $headers, $response->get_headers() );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		}
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		try {
			$response = new WP_REST_Response( null, 401 );
			Feed_Routes::authentication_challenge( $response, rest_get_server(), $request );
			$this->assertSame( array(), $response->get_headers() );
			$this->assertFalse( wp_is_application_passwords_available() );
		} finally {
			remove_filter( 'wp_is_application_passwords_available', '__return_false' );
		}
	}

	/**
	 * An authenticated feed gives the same data as a public one: a block that shows the current user renders as
	 * for a visitor, and the user of the request is still signed in afterwards.
	 */
	public function test_an_authenticated_feed_gives_the_data_of_a_visitor() {
		register_block_type(
			'kkce-test/viewer',
			array( 'render_callback' => static fn() => '<p>viewer:' . get_current_user_id() . '</p>' )
		);
		$rows = array( array( 'key' => 'body', 'kind' => 'field', 'type' => 'html', 'sources' => array( '*' => array( 'core.content' ) ) ) );
		$this->feed( 'public-feed', array( 'rows' => $rows ) );
		$this->feed( 'private-feed', array( 'rows' => $rows, 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->item( array( 'post_content' => '<!-- wp:kkce-test/viewer /-->' ) );
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $reader )->add_cap( 'kkce_read_export' );

		try {
			$public = $this->get( 'public-feed' )->get_data()['items'];
			$this->sign_in_with_application_password( $reader );
			$private = $this->get( 'private-feed' )->get_data()['items'];
		} finally {
			unregister_block_type( 'kkce-test/viewer' );
		}

		$this->assertStringContainsString( 'viewer:0', $public[0]->body );
		$this->assertEquals( $public, $private );
		$this->assertSame( $reader, get_current_user_id() );
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
	 * User-dependent query filters see a visitor for selection and empty-page counts.
	 */
	public function test_query_visibility_is_anonymous_for_items_and_totals() {
		get_userdata( self::$admin )->add_cap( 'kkce_read_export' );
		$this->feed( 'visitor-public' );
		$this->feed( 'visitor-private', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$visible = $this->item();
		$hidden  = $this->item();
		$viewers = array();
		$filter  = static function ( WP_Query $query ) use ( $hidden, &$viewers ): void {
			if ( true !== $query->get( 'kkce_feed_order' ) ) {
				return;
			}
			$viewers[] = array( get_current_user_id(), 'ids' === $query->get( 'fields' ) );
			if ( 0 === get_current_user_id() ) {
				$query->set( 'post__not_in', array( $hidden ) );
			}
		};
		add_action( 'pre_get_posts', $filter );
		try {
			$public = $this->get( 'visitor-public', array( 'per_page' => 2 ) );
			$this->sign_in_with_application_password( self::$admin );
			$private = $this->get( 'visitor-private', array( 'per_page' => 2 ) );
			$empty   = $this->get( 'visitor-private', array( 'per_page' => 1, 'page' => 3 ) );
			$unrelated = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids' ) );
		} finally {
			remove_action( 'pre_get_posts', $filter );
		}

		$this->assertSame( self::$admin, get_current_user_id() );
		$this->assertSame( 200, $private->get_status() );
		$this->assertSame( array( $visible ), $this->ids( $public ) );
		$this->assertEquals( $public->get_data()['items'], $private->get_data()['items'] );
		$this->assertSame( $public->get_data()['pagination'], $private->get_data()['pagination'] );
		$this->assertSame( array(), $empty->get_data()['items'] );
		$this->assertSame( 1, $empty->get_data()['pagination']['total'] );
		$this->assertSame( 1, $empty->get_data()['pagination']['total_pages'] );
		$this->assertContains( array( 0, true ), $viewers );
		foreach ( $viewers as $viewer ) {
			$this->assertSame( 0, $viewer[0] );
		}
		$this->assertContains( $hidden, $unrelated->posts );
	}

	/**
	 * Both query paths restore the authenticated user and temporary order filter after an exception.
	 *
	 * @dataProvider failing_query_provider
	 * @param bool $ids_only Whether the counting query fails.
	 */
	public function test_query_exception_restores_the_authenticated_user( bool $ids_only ) {
		get_userdata( self::$admin )->add_cap( 'kkce_read_export' );
		$this->feed( 'visitor-exception', array( 'access' => Feed_Meta::ACCESS_AUTHENTICATED ) );
		$this->item();
		$this->sign_in_with_application_password( self::$admin );
		$viewer = null;
		$filter = static function ( WP_Query $query ) use ( $ids_only, &$viewer ): void {
			if ( true === $query->get( 'kkce_feed_order' ) && $ids_only === ( 'ids' === $query->get( 'fields' ) ) ) {
				$viewer = get_current_user_id();
				throw new RuntimeException( 'Visibility query failed.' );
			}
		};
		add_action( 'pre_get_posts', $filter );
		try {
			try {
				$this->get( 'visitor-exception', array( 'per_page' => 1, 'page' => $ids_only ? 3 : 1 ) );
				$this->fail( 'The query exception must propagate.' );
			} catch ( RuntimeException $error ) {
				$this->assertSame( 'Visibility query failed.', $error->getMessage() );
			}
		} finally {
			remove_action( 'pre_get_posts', $filter );
		}

		$this->assertSame( 0, $viewer );
		$this->assertSame( self::$admin, get_current_user_id() );
		$this->assertFalse( has_filter( 'posts_orderby', array( Feed_Routes::class, 'order_by_modified_gmt' ) ) );
	}

	/**
	 * Query paths that must restore the caller's context.
	 *
	 * @return array<string, array{bool}>
	 */
	public function failing_query_provider(): array {
		return array( 'items' => array( false ), 'empty-page count' => array( true ) );
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

	/**
	 * `page` and `per_page` choose the page; the requested items per page replace the ones of the feed.
	 */
	public function test_page_and_per_page_choose_the_page() {
		$this->feed( 'news', array( 'per_page' => '2' ) );
		$ids = array();
		foreach ( array( '01', '02', '03', '04', '05' ) as $day ) {
			$ids[] = $this->item( array( 'post_date' => "2026-01-$day 10:00:00" ) );
		}

		$this->assertSame( array_slice( $ids, 2, 2 ), $this->ids( $this->get( 'news', array( 'page' => 2 ) ) ) );
		$this->assertSame( array( $ids[4] ), $this->ids( $this->get( 'news', array( 'page' => 3 ) ) ) );

		$response = $this->get( 'news', array( 'page' => 2, 'per_page' => 3 ) );
		$this->assertSame( array_slice( $ids, 3, 2 ), $this->ids( $response ) );
		$this->assertSame( array( 'page' => 2, 'per_page' => 3, 'total' => 5, 'total_pages' => 2 ), $response->get_data()['pagination'] );
	}

	/**
	 * A page after the last one is an empty page with the real total, not an error.
	 */
	public function test_a_page_after_the_last_one_is_empty() {
		$this->feed( 'news', array( 'per_page' => '2' ) );
		$this->item();
		$this->item();
		$this->item();

		$response = $this->get( 'news', array( 'page' => 5 ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data()['items'] );
		$this->assertSame( array( 'page' => 5, 'per_page' => 2, 'total' => 3, 'total_pages' => 2 ), $response->get_data()['pagination'] );
	}

	/**
	 * Items per page above the global maximum are refused with the maximum in the message, never reduced.
	 */
	public function test_per_page_above_the_maximum_gives_400() {
		$this->feed( 'news' );
		$this->settings( array( 'max_per_page' => 20 ) );

		$this->assertSame( 200, $this->get( 'news', array( 'per_page' => 20 ) )->get_status() );

		$response = $this->get( 'news', array( 'per_page' => 21 ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( '20', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Without Polylang `lang` is refused, never ignored: a feed filtered by a language it cannot apply would look
	 * complete. Without `lang` the feed answers as usual. With Polylang: tests/integration/polylang.
	 */
	public function test_lang_without_polylang_gives_400() {
		$this->feed( 'news' );

		$this->assertSame( 200, $this->get( 'news' )->get_status() );

		$response = $this->get( 'news', array( 'lang' => 'en' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'Polylang', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Values that are not whole numbers of at least 1 are refused.
	 *
	 * @dataProvider invalid_parameter_provider
	 *
	 * @param array<string, mixed> $params Query parameters.
	 */
	public function test_invalid_parameters_give_400( array $params ) {
		$this->feed( 'news' );

		$response = $this->get( 'news', $params );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
	}

	/**
	 * Data for test_invalid_parameters_give_400().
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_parameter_provider(): array {
		return array(
			'page zero'         => array( array( 'page' => '0' ) ),
			'negative page'     => array( array( 'page' => '-1' ) ),
			'page as text'      => array( array( 'page' => 'two' ) ),
			'decimal page'      => array( array( 'page' => '1.5' ) ),
			'per_page zero'     => array( array( 'per_page' => '0' ) ),
			'per_page as text'  => array( array( 'per_page' => 'all' ) ),
		);
	}

	/**
	 * Items are ordered by modified date in GMT, then by ID: also with the same date, across post types, when
	 * the local times repeat at the end of daylight saving time, and whatever the time zone of the site.
	 */
	public function test_items_are_ordered_by_modified_gmt_then_id() {
		update_option( 'timezone_string', 'Europe/Rome' );
		$this->feed( 'mixed', array( 'post_types' => array( 'post', 'page' ) ) );
		$late  = $this->item();
		$same1 = $this->item( array( 'post_type' => 'page' ) );
		$same2 = $this->item();
		$dst1  = $this->item( array( 'post_type' => 'page' ) );
		$dst2  = $this->item();

		// 25 October 2026, end of daylight saving time in Rome: 02:00-03:00 local happens twice.
		$this->modified( $dst1, '2026-10-25 00:30:00', '2026-10-25 02:30:00' ); // 02:30 CEST.
		$this->modified( $dst2, '2026-10-25 01:10:00', '2026-10-25 02:10:00' ); // 02:10 CET, later in GMT.
		$this->modified( $same1, '2026-10-26 08:00:00', '2026-10-26 09:00:00' );
		$this->modified( $same2, '2026-10-26 08:00:00', '2026-10-26 09:00:00' );
		$this->modified( $late, '2026-10-27 08:00:00', '2026-10-27 09:00:00' );

		$expected = array( $dst1, $dst2, $same1, $same2, $late );
		$this->assertSame( $expected, $this->ids( $this->get( 'mixed' ) ) );

		update_option( 'timezone_string', 'America/New_York' );
		$this->assertSame( $expected, $this->ids( $this->get( 'mixed' ) ) );
	}

	/**
	 * The order of the feeds does not change the order of other queries.
	 */
	public function test_the_order_does_not_leak_to_other_queries() {
		$this->feed( 'news' );
		$this->item();
		$this->get( 'news' );

		$query = new WP_Query( array( 'post_type' => 'post', 'orderby' => 'title' ) );

		$this->assertStringNotContainsString( 'post_modified_gmt', $query->request );
	}

	/**
	 * Five items modified one hour apart (09:00 to 13:00 GMT on 1 November 2026), published one day apart.
	 *
	 * @return list<int> IDs, oldest first.
	 */
	private function dated_items(): array {
		$this->feed( 'news' );
		$ids = array();
		foreach ( array( 9, 10, 11, 12, 13 ) as $i => $hour ) {
			$id = $this->item();
			$this->modified( $id, sprintf( '2026-11-01 %02d:00:00', $hour ), sprintf( '2026-11-01 %02d:00:00', $hour + 1 ) );
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => sprintf( '2026-10-%02d 08:00:00', 20 + $i ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			$ids[] = $id;
		}

		return $ids;
	}

	/**
	 * The interval of the modified date is half-open: `modified_after` included, `modified_before` excluded.
	 */
	public function test_modified_after_is_included_and_modified_before_is_excluded() {
		$ids = $this->dated_items();

		$this->assertSame( array_slice( $ids, 2 ), $this->ids( $this->get( 'news', array( 'modified_after' => '2026-11-01T11:00:00Z' ) ) ) );
		$this->assertSame( array_slice( $ids, 0, 2 ), $this->ids( $this->get( 'news', array( 'modified_before' => '2026-11-01T11:00:00Z' ) ) ) );

		$response = $this->get( 'news', array( 'modified_after' => '2026-11-01T10:00:00Z', 'modified_before' => '2026-11-01T12:00:00Z' ) );
		$this->assertSame( array( $ids[1], $ids[2] ), $this->ids( $response ) );
		$this->assertSame( 2, $response->get_data()['pagination']['total'] );
	}

	/**
	 * A time zone is converted to UTC, and fractions of a second are cut, never rounded up.
	 */
	public function test_dates_with_a_time_zone_and_fractions() {
		$ids = $this->dated_items();

		$this->assertSame( array_slice( $ids, 2 ), $this->ids( $this->get( 'news', array( 'modified_after' => '2026-11-01T12:00:00+01:00' ) ) ) );
		$this->assertSame( array_slice( $ids, 2 ), $this->ids( $this->get( 'news', array( 'modified_after' => '2026-11-01T11:00:00.999Z' ) ) ) );
	}

	/**
	 * `published_after` reads the date of publication in GMT, not the modified date.
	 */
	public function test_published_after_reads_the_publication_date() {
		$ids = $this->dated_items();

		$this->assertSame( array_slice( $ids, 3 ), $this->ids( $this->get( 'news', array( 'published_after' => '2026-10-23T08:00:00Z' ) ) ) );
	}

	/**
	 * The filters work with the pages: total and pages count the filtered items only.
	 */
	public function test_filters_and_pages() {
		$ids = $this->dated_items();

		$response = $this->get( 'news', array( 'modified_after' => '2026-11-01T10:00:00Z', 'per_page' => 2, 'page' => 2 ) );

		$this->assertSame( array( $ids[3], $ids[4] ), $this->ids( $response ) );
		$this->assertSame( array( 'page' => 2, 'per_page' => 2, 'total' => 4, 'total_pages' => 2 ), $response->get_data()['pagination'] );
	}

	/**
	 * A date without a time zone, or not a date, is refused with an example of the right form.
	 *
	 * @dataProvider invalid_date_provider
	 *
	 * @param string $param Filter.
	 * @param string $value Value.
	 */
	public function test_an_invalid_date_gives_400( string $param, string $value ) {
		$this->feed( 'news' );

		$response = $this->get( 'news', array( $param => $value ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( '2026-11-01T10:00:00Z', wp_json_encode( $response->get_data() ) );
	}

	/**
	 * Data for test_an_invalid_date_gives_400().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function invalid_date_provider(): array {
		return array(
			'no time zone'   => array( 'modified_after', '2026-11-01T10:00:00' ),
			'date only'      => array( 'modified_before', '2026-11-01' ),
			'impossible day' => array( 'published_after', '2026-02-30T10:00:00Z' ),
			'words'          => array( 'modified_after', 'yesterday' ),
			'remapped year'  => array( 'modified_after', '0050-01-01T00:00:00Z' ),
		);
	}

	/**
	 * An interval of the modified date that cannot contain anything is refused.
	 */
	public function test_an_empty_interval_gives_400() {
		$this->feed( 'news' );

		foreach ( array( '2026-11-01T10:00:00Z', '2026-11-02T10:00:00Z' ) as $after ) {
			$response = $this->get( 'news', array( 'modified_after' => $after, 'modified_before' => '2026-11-01T10:00:00Z' ) );
			$this->assertSame( 400, $response->get_status(), $after );
			$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		}

		// The same moment written with a time zone is the same moment.
		$response = $this->get( 'news', array( 'modified_after' => '2026-11-01T11:00:00+01:00', 'modified_before' => '2026-11-01T10:00:00Z' ) );
		$this->assertSame( 400, $response->get_status() );
	}

	/**
	 * A filter turned off in the settings is refused, never ignored; the other filters keep working.
	 */
	public function test_a_filter_turned_off_gives_400() {
		$ids = $this->dated_items();
		$this->settings( array( 'filter_modified_before' => false ) );

		$response = $this->get( 'news', array( 'modified_before' => '2026-11-01T11:00:00Z' ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'modified_before', wp_json_encode( $response->get_data() ) );

		$this->assertSame( array_slice( $ids, 2 ), $this->ids( $this->get( 'news', array( 'modified_after' => '2026-11-01T11:00:00Z' ) ) ) );
	}
}

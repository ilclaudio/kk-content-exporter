<?php
/**
 * Integration tests of the admin REST routes (catalog and preview).
 *
 * @package KKCE
 */

use KKCE\Feed_Post_Type;
use KKCE\Output\Item_Exporter;
use KKCE\Output\Mapping_Validator;
use KKCE\Rest\Admin_Routes;
use KKCE\Sources\Catalog;
use KKCE\Sources\Core_Provider;

/**
 * Only feed administrators reach the routes; the preview shows only published, public data of the chosen post types.
 */
class KKCE_Admin_Routes_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Editor (can edit posts, not feeds).
	 *
	 * @var int
	 */
	private static int $editor;

	/**
	 * Creates the users once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin  = $factory->user->create( array( 'role' => 'administrator' ) );
		self::$editor = $factory->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Fresh REST server with the routes of the plugin.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	/**
	 * Drops the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * GET request to the catalog.
	 *
	 * @param array<string, mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function catalog( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/' . Admin_Routes::NAMESPACE . '/catalog' );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * POST request to the preview.
	 *
	 * @param array<string, mixed> $params Body parameters.
	 * @return WP_REST_Response
	 */
	private function preview( array $params ): WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/' . Admin_Routes::NAMESPACE . '/preview' );
		$request->set_body_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * JSON text of a mapping with the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return string
	 */
	private function mapping( array $rows ): string {
		return (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) );
	}

	/**
	 * A mapping with the ID and the title of every item.
	 *
	 * @return string
	 */
	private function id_and_title(): string {
		return $this->mapping( array(
			array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ),
			array( 'key' => 'title', 'kind' => 'field', 'sources' => array( '*' => array( 'core.title' ) ) ),
		) );
	}

	/**
	 * The routes are registered in their own namespace and kept out of the REST index.
	 */
	public function test_routes_are_registered_outside_the_index() {
		$routes = rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/kkce-admin/v1/catalog', $routes );
		$this->assertArrayHasKey( '/kkce-admin/v1/preview', $routes );
		$this->assertSame( array( 'GET' => true ), $routes['/kkce-admin/v1/catalog'][0]['methods'] );
		$this->assertSame( array( 'POST' => true ), $routes['/kkce-admin/v1/preview'][0]['methods'] );
		$this->assertFalse( $routes['/kkce-admin/v1/catalog'][0]['show_in_index'] );
		$this->assertFalse( $routes['/kkce-admin/v1/preview'][0]['show_in_index'] );
	}

	/**
	 * Anonymous requests get 401, users who cannot edit feeds get 403.
	 */
	public function test_anonymous_gets_401_and_editor_gets_403() {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$params  = array( 'mapping' => $this->id_and_title(), 'post_types' => array( 'post' ), 'post_id' => $post_id );

		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->catalog( array( 'post_types' => array( 'post' ) ) )->get_status() );
		$this->assertSame( 401, $this->preview( $params )->get_status() );

		wp_set_current_user( self::$editor );
		$this->assertSame( 403, $this->catalog( array( 'post_types' => array( 'post' ) ) )->get_status() );
		$this->assertSame( 403, $this->preview( $params )->get_status() );
	}

	/**
	 * The permission follows the capability of the feed post type, not a fixed one.
	 */
	public function test_permission_follows_the_capability_of_the_feed_post_type() {
		wp_set_current_user( self::$editor );
		$grant = static function ( $allcaps ) {
			$allcaps['manage_options'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant );

		try {
			$this->assertSame( 200, $this->catalog( array( 'post_types' => array( 'post' ) ) )->get_status() );
		} finally {
			remove_filter( 'user_has_cap', $grant );
		}
	}

	/**
	 * The catalog is the one of the plugin for the selectable post types; the others are reported.
	 */
	public function test_catalog_matches_the_catalog_of_the_selectable_post_types() {
		wp_set_current_user( self::$admin );

		$response = $this->catalog( array( 'post_types' => array( 'post', 'page', 'attachment', 'nope', 'post' ) ) );
		$data     = $response->get_data();
		$expected = new Catalog( Core_Provider::sources(), array( 'post', 'page' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'post', 'page' ), $data['post_types'] );
		$this->assertSame( array( 'attachment', 'nope' ), $data['unavailable'] );
		$this->assertSame( array( 'post' => 'Posts', 'page' => 'Pages' ), $data['labels'] );
		$this->assertSame( array_map( static fn( $source ) => $source->id, $expected->sources() ), array_column( $data['sources'], 'id' ) );
		$this->assertSame( $expected->suggested_rows(), $data['suggested_rows'] );

		$by_id = array_column( $data['sources'], null, 'id' );
		$this->assertSame(
			array(
				'id'                 => 'core.content',
				'label'              => 'Content',
				'group'              => 'core',
				'post_type'          => '',
				'type'               => 'html',
				'options'            => array( 'shortcodes', 'raw' ),
				'needs_confirmation' => false,
				'suggested_key'      => 'content',
			),
			$by_id['core.content']
		);
		$this->assertTrue( $by_id['core.author.url']['needs_confirmation'] );
	}

	/**
	 * A feed with one post type gets no suggested row.
	 */
	public function test_catalog_of_one_post_type_suggests_no_row() {
		wp_set_current_user( self::$admin );

		$this->assertSame( array(), $this->catalog( array( 'post_types' => array( 'post' ) ) )->get_data()['suggested_rows'] );
	}

	/**
	 * Missing or malformed post types are refused with 400.
	 */
	public function test_catalog_refuses_invalid_post_types() {
		wp_set_current_user( self::$admin );

		$this->assertSame( 400, $this->catalog( array() )->get_status() );
		$this->assertSame( 400, $this->catalog( array( 'post_types' => array() ) )->get_status() );
		$this->assertSame( 400, $this->catalog( array( 'post_types' => array( 'Bad Type' ) ) )->get_status() );
		$this->assertSame( 400, $this->catalog( array( 'post_types' => array( str_repeat( 'a', 21 ) ) ) )->get_status() );
	}

	/**
	 * The preview of an item is the exported object of that item.
	 */
	public function test_preview_of_an_item_matches_the_exporter() {
		wp_set_current_user( self::$admin );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'Arts & <em>crafts</em>' ) );
		$mapping = $this->id_and_title();

		$response = $this->preview( array( 'mapping' => $mapping, 'post_types' => array( 'post' ), 'post_id' => $post_id ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( $post_id, $data['items'][0]['post']['id'] );
		$this->assertSame( 'post', $data['items'][0]['post']['type'] );
		$this->assertSame(
			Item_Exporter::export( get_post( $post_id ), (array) Mapping_Validator::parse( $mapping ) ),
			(array) $data['items'][0]['data']
		);
		$this->assertSame( array( 'id' => $post_id, 'title' => 'Arts & crafts' ), (array) $data['items'][0]['data'] );
		$this->assertSame( array(), $data['empty_types'] );
	}

	/**
	 * Without an item, the preview uses the latest published item of every post type, never a hidden one.
	 */
	public function test_preview_without_item_uses_the_latest_public_item_of_each_type() {
		wp_set_current_user( self::$admin );
		$old    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_date' => '2020-01-01 10:00:00' ) );
		$latest = self::factory()->post->create( array( 'post_status' => 'publish', 'post_date' => '2021-01-01 10:00:00' ) );
		self::factory()->post->create( array( 'post_status' => 'draft', 'post_date' => '2022-01-01 10:00:00' ) );
		self::factory()->post->create( array( 'post_status' => 'private', 'post_date' => '2022-01-01 10:00:00' ) );
		self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'secret', 'post_date' => '2022-01-01 10:00:00' ) );
		$this->set_modified( $old, '2020-01-01 10:00:00' );
		$this->set_modified( $latest, '2021-01-01 10:00:00' );

		$response = $this->preview( array( 'mapping' => $this->id_and_title(), 'post_types' => array( 'post', 'page' ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( $latest ), array_map( static fn( $item ) => $item['post']['id'], $data['items'] ) );
		$this->assertSame( array( 'page' ), $data['empty_types'] );
	}

	/**
	 * Sets the modified date of a post in the database (WordPress writes the current time on insert).
	 *
	 * @param int    $post_id Post.
	 * @param string $date    Date (local and GMT, same value).
	 * @return void
	 */
	private function set_modified( int $post_id, string $date ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => $date, 'post_modified_gmt' => $date ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
	}

	/**
	 * An item that is not published, has a password, is of another post type or does not exist gives 404.
	 */
	public function test_preview_of_a_hidden_or_foreign_item_gives_404() {
		wp_set_current_user( self::$admin );
		$hidden = array(
			self::factory()->post->create( array( 'post_status' => 'draft' ) ),
			self::factory()->post->create( array( 'post_status' => 'private' ) ),
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_password' => 'secret' ) ),
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => 'page' ) ),
			self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => Feed_Post_Type::POST_TYPE ) ),
			999999,
		);

		foreach ( $hidden as $post_id ) {
			$response = $this->preview( array( 'mapping' => $this->id_and_title(), 'post_types' => array( 'post' ), 'post_id' => $post_id ) );
			$this->assertSame( 404, $response->get_status(), 'Item ' . $post_id );
			$this->assertSame( 'kkce_item_not_found', $response->get_data()['code'] );
		}
	}

	/**
	 * Unreadable JSON and invalid mappings give 400 with every error.
	 */
	public function test_preview_refuses_an_invalid_mapping_with_every_error() {
		wp_set_current_user( self::$admin );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->preview( array( 'mapping' => '{not json', 'post_types' => array( 'post' ), 'post_id' => $post_id ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'kkce_invalid_mapping', $response->get_data()['code'] );
		$this->assertSame( array( array( 'row' => -1, 'field' => '', 'code' => 'json' ) ), $response->get_data()['data']['errors'] );

		$invalid  = $this->mapping( array(
			array( 'key' => 'a', 'kind' => 'template' ),
			array( 'key' => 'b', 'kind' => 'field', 'sources' => array( '*' => array( 'meta.user_pass' ) ) ),
		) );
		$response = $this->preview( array( 'mapping' => $invalid, 'post_types' => array( 'post' ), 'post_id' => $post_id ) );
		$errors   = $response->get_data()['data']['errors'];

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( Mapping_Validator::validate( json_decode( $invalid, true ) ), $errors );
		$this->assertContains( 'sensitive', array_column( $errors, 'code' ) );
		$this->assertContains( 0, array_column( $errors, 'row' ) );
		$this->assertContains( 1, array_column( $errors, 'row' ) );
	}

	/**
	 * Missing or malformed parameters of the preview give 400.
	 */
	public function test_preview_refuses_invalid_parameters() {
		wp_set_current_user( self::$admin );
		$mapping = $this->id_and_title();

		$this->assertSame( 400, $this->preview( array( 'post_types' => array( 'post' ) ) )->get_status() );
		$this->assertSame( 400, $this->preview( array( 'mapping' => $mapping ) )->get_status() );
		$this->assertSame( 400, $this->preview( array( 'mapping' => $mapping, 'post_types' => array( 'post' ), 'post_id' => 0 ) )->get_status() );
		$this->assertSame( 400, $this->preview( array( 'mapping' => $mapping, 'post_types' => array( 'post' ), 'post_id' => 'abc' ) )->get_status() );
	}

	/**
	 * A preview without a selectable post type is refused.
	 */
	public function test_preview_without_selectable_post_types_gives_400() {
		wp_set_current_user( self::$admin );

		$response = $this->preview( array( 'mapping' => $this->id_and_title(), 'post_types' => array( 'attachment', 'nope' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'kkce_no_post_types', $response->get_data()['code'] );
	}

	/**
	 * An empty mapping gives an empty JSON object for every item.
	 */
	public function test_preview_of_an_empty_mapping_is_an_empty_object() {
		wp_set_current_user( self::$admin );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$response = $this->preview( array( 'mapping' => $this->mapping( array() ), 'post_types' => array( 'post' ), 'post_id' => $post_id ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '{}', wp_json_encode( $response->get_data()['items'][0]['data'] ) );
	}

	/**
	 * The current user is the same after the preview: the item is computed as a visitor, then restored.
	 */
	public function test_preview_restores_the_current_user() {
		wp_set_current_user( self::$admin );
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->preview( array( 'mapping' => $this->id_and_title(), 'post_types' => array( 'post' ), 'post_id' => $post_id ) );

		$this->assertSame( self::$admin, get_current_user_id() );
	}
}

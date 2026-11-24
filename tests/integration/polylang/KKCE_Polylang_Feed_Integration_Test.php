<?php
/**
 * Integration tests of the endpoint of a feed on a site with Polylang.
 *
 * @package KKCE
 */

require_once __DIR__ . '/KKCE_Polylang_Test_Case.php';

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Settings;

/**
 * What a feed exports when the site has more languages (Polylang).
 */
class KKCE_Polylang_Feed_Integration_Test extends KKCE_Polylang_Test_Case {

	/**
	 * A post type that Polylang does not translate, registered by a test.
	 */
	private const PLAIN_TYPE = 'kkce_plain';

	/**
	 * Registers the meta again (the test case unregisters them), starts as a visitor with the default settings and a
	 * fresh REST server.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Feed_Meta::register();
		wp_set_current_user( 0 );
		delete_option( Settings::OPTION );
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
	 * A published, enabled, public feed with the ID and the title of the items.
	 *
	 * @param string   $slug       Slug.
	 * @param string[] $post_types Post types.
	 * @return int ID of the feed.
	 */
	private function feed( string $slug, array $post_types = array( 'post' ) ): int {
		$feed = self::factory()->post->create(
			array(
				'post_type'   => Feed_Post_Type::POST_TYPE,
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => 'Feed ' . $slug,
			)
		);
		foreach ( $post_types as $post_type ) {
			add_post_meta( $feed, Feed_Meta::META_POST_TYPE, $post_type );
		}
		update_post_meta( $feed, Feed_Meta::META_ACTIVE, '1' );
		update_post_meta( $feed, Feed_Meta::META_ACCESS, Feed_Meta::ACCESS_PUBLIC );
		$rows = array(
			array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ),
			array( 'key' => 'title', 'kind' => 'field', 'sources' => array( '*' => array( 'core.title' ) ) ),
		);
		Feed_Meta::save_mapping( $feed, (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) ) );

		return $feed;
	}

	/**
	 * Drops the post type of the test.
	 *
	 * @return void
	 */
	private function unregister_plain_type(): void {
		if ( post_type_exists( self::PLAIN_TYPE ) ) {
			unregister_post_type( self::PLAIN_TYPE );
		}
	}

	/**
	 * GET of a feed.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string, mixed> $params Query parameters.
	 * @return WP_REST_Response
	 */
	private function get( string $slug, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( 'GET', '/kkce/v1/' . $slug );
		$request->set_query_params( $params );

		return rest_do_request( $request );
	}

	/**
	 * IDs of the items of a GET of a feed, sorted.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string, mixed> $params Query parameters.
	 * @return list<int>
	 */
	private function ids( string $slug, array $params = array() ): array {
		$response = $this->get( $slug, $params );
		$this->assertSame( 200, $response->get_status() );

		$ids = array_map( static fn( $item ) => $item->id, $response->get_data()['items'] );
		sort( $ids );

		return $ids;
	}

	/**
	 * Without `lang` a feed exports every language (as the REST routes of WordPress do with Polylang); with `lang`
	 * only that language. Polylang itself adds no language condition to the queries of a REST request: the filter is
	 * the plugin's.
	 */
	public function test_lang_keeps_one_language_and_without_it_every_language_is_exported() {
		$italian = $this->translated_post( 'it', array( 'post_title' => 'Ciao' ) );
		$english = $this->translated_post( 'en', array( 'post_title' => 'Hello' ) );
		pll_save_post_translations( array( 'it' => $italian, 'en' => $english ) );
		$this->feed( 'news' );

		$this->assertSame( 'en', pll_get_post_language( $english ) );
		$this->assertEqualsCanonicalizing( array( 'it' => $italian, 'en' => $english ), pll_get_post_translations( $italian ) );
		$this->assertInstanceOf( PLL_REST_Request::class, PLL() );

		$this->assertSame( array( $italian, $english ), $this->ids( 'news' ) );
		$this->assertSame( array( $english ), $this->ids( 'news', array( 'lang' => 'en' ) ) );
		$this->assertSame( array( $italian ), $this->ids( 'news', array( 'lang' => 'it' ) ) );
	}

	/**
	 * With `lang`, the items of the post types that Polylang does not translate stay; the items of a translated post
	 * type without a language go, as they are in no language.
	 */
	public function test_lang_keeps_the_items_of_post_types_that_are_not_translated() {
		register_post_type( self::PLAIN_TYPE, array( 'public' => true ) );
		try {
			$english  = $this->translated_post( 'en' );
			$this->translated_post( 'it' );
			$untagged = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			$plain    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => self::PLAIN_TYPE ) );
			$this->feed( 'mixed', array( 'post', self::PLAIN_TYPE ) );

			$this->assertSame( array( $english, $plain ), $this->ids( 'mixed', array( 'lang' => 'en' ) ) );
			$this->assertContains( $untagged, $this->ids( 'mixed' ) );
		} finally {
			$this->unregister_plain_type();
		}
	}

	/**
	 * Totals and pages count the items of the language only, also for a page after the last one; `lang` combines with
	 * the date filters.
	 */
	public function test_lang_counts_only_its_items_and_combines_with_the_date_filters() {
		$old = $this->translated_post( 'en', array( 'post_date' => '2020-01-01 10:00:00', 'post_date_gmt' => '2020-01-01 10:00:00' ) );
		$new = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$new[] = $this->translated_post( 'en' );
			$this->translated_post( 'it' );
		}
		$this->feed( 'news' );

		$response   = $this->get( 'news', array( 'lang' => 'en', 'per_page' => 2 ) );
		$pagination = $response->get_data()['pagination'];
		$this->assertSame( 4, $pagination['total'] );
		$this->assertSame( 2, $pagination['total_pages'] );

		$after = $this->get( 'news', array( 'lang' => 'en', 'per_page' => 2, 'page' => 5 ) )->get_data();
		$this->assertSame( array(), $after['items'] );
		$this->assertSame( 4, $after['pagination']['total'] );

		$this->assertSame( $new, $this->ids( 'news', array( 'lang' => 'en', 'published_after' => '2021-01-01T00:00:00Z' ) ) );
		$this->assertNotContains( $old, $this->ids( 'news', array( 'lang' => 'en', 'published_after' => '2021-01-01T00:00:00Z' ) ) );
	}

	/**
	 * A language the site does not have gives 400, with the languages it has.
	 */
	public function test_an_unknown_language_gives_400() {
		$this->feed( 'news' );

		$response = $this->get( 'news', array( 'lang' => 'xx' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'rest_invalid_param', $response->as_error()->get_error_code() );
		$this->assertStringContainsString( 'it, en', (string) wp_json_encode( $response->get_data() ) );
	}
}

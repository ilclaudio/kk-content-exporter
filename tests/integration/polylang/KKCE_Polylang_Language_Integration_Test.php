<?php
/**
 * Integration tests of the language sources with Polylang.
 *
 * @package KKCE
 */

require_once __DIR__ . '/KKCE_Polylang_Test_Case.php';

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Rest\Admin_Routes;
use KKCE\Settings;
use KKCE\Sources\Catalog_Factory;

/**
 * With Polylang the catalog offers the language of an item in three formats and its translation group; the feed
 * exports them, null for an item without a language, and they cost no query per item.
 */
class KKCE_Polylang_Language_Integration_Test extends KKCE_Polylang_Test_Case {

	/**
	 * A post type that Polylang does not translate, registered by a test.
	 */
	private const PLAIN_TYPE = 'kkce_plain';

	/**
	 * Language sources, in the order of the catalog.
	 */
	private const SOURCES = array( 'language.site', 'language.code', 'language.locale', 'language.w3c', 'language.translation_group' );

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
	 * Drops the REST server and the post type of the test.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		if ( post_type_exists( self::PLAIN_TYPE ) ) {
			unregister_post_type( self::PLAIN_TYPE );
		}
		parent::tear_down();
	}

	/**
	 * A published, enabled, public feed with the ID and every language source of the items.
	 *
	 * @param string   $slug       Slug.
	 * @param string[] $post_types Post types.
	 * @param int      $per_page   Items per page.
	 * @return void
	 */
	private function feed( string $slug, array $post_types = array( 'post' ), int $per_page = 50 ): void {
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
		update_post_meta( $feed, Feed_Meta::META_PER_PAGE, (string) $per_page );
		$rows = array( array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ) );
		foreach ( self::SOURCES as $source ) {
			$rows[] = array( 'key' => substr( $source, strlen( 'language.' ) ), 'kind' => 'field', 'sources' => array( '*' => array( $source ) ) );
		}
		Feed_Meta::save_mapping( $feed, (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) ) );
	}

	/**
	 * Items of a GET of a feed, by ID.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string, mixed> $params Query parameters.
	 * @return array<int, array<string, mixed>>
	 */
	private function items( string $slug, array $params = array() ): array {
		$request = new WP_REST_Request( 'GET', '/kkce/v1/' . $slug );
		$request->set_query_params( $params );
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status() );

		$items = array();
		foreach ( $response->get_data()['items'] as $item ) {
			$items[ $item->id ] = (array) $item;
		}

		return $items;
	}

	/**
	 * The catalog offers every language source when a post type of the feed is translated, only the site language
	 * when none is.
	 */
	public function test_the_catalog_offers_the_item_language_for_translated_post_types() {
		register_post_type( self::PLAIN_TYPE, array( 'public' => true ) );
		$this->assertFalse( pll_is_translated_post_type( self::PLAIN_TYPE ) );

		$ids = static fn( array $post_types ): array => array_values(
			array_map(
				static fn( $source ) => $source->id,
				array_filter( Catalog_Factory::for_post_types( $post_types )->sources(), static fn( $source ) => 'language' === $source->group() )
			)
		);

		$this->assertSame( self::SOURCES, $ids( array( 'post' ) ) );
		$this->assertSame( self::SOURCES, $ids( array( self::PLAIN_TYPE, 'post' ) ) );
		$this->assertSame( array( 'language.site' ), $ids( array( self::PLAIN_TYPE ) ) );

		// The internal taxonomies of Polylang are never offered as taxonomy sources: the language has its own.
		$taxonomies = array_filter( Catalog_Factory::for_post_types( array( 'post' ) )->sources(), static fn( $source ) => 'taxonomy' === $source->group() );
		$this->assertSame( array(), preg_grep( '/language|translations/', array_map( static fn( $source ) => $source->id, $taxonomies ) ) );
		$this->assertNotSame( array(), $taxonomies );
	}

	/**
	 * The catalog of the mapping editor proposes the rows `lang` and `translation_group` for a new mapping when a post
	 * type of the feed is translated, none when it is not.
	 */
	public function test_the_editor_catalog_proposes_the_language_rows() {
		register_post_type( self::PLAIN_TYPE, array( 'public' => true ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$proposed = static function ( array $post_types ): array {
			$request = new WP_REST_Request( 'GET', '/' . Admin_Routes::NAMESPACE . '/catalog' );
			$request->set_query_params( array( 'post_types' => $post_types ) );

			return array_column( rest_do_request( $request )->get_data()['suggested_rows'], 'sources', 'key' );
		};

		$this->assertSame(
			array(
				'lang'              => array( '*' => array( 'language.code' ) ),
				'translation_group' => array( '*' => array( 'language.translation_group' ) ),
			),
			$proposed( array( 'post' ) )
		);
		$this->assertSame( array(), $proposed( array( self::PLAIN_TYPE ) ) );
	}

	/**
	 * The feed exports the language of each item in the three formats and the same translation group for the
	 * translations of one content, a different one for another content.
	 */
	public function test_the_feed_exports_the_language_and_the_translation_group() {
		// WordPress saves only installed languages, and the test environment has none.
		add_filter( 'pre_option_WPLANG', static fn() => 'it_IT' );
		$italian = $this->translated_post( 'it' );
		$english = $this->translated_post( 'en' );
		pll_save_post_translations( array( 'it' => $italian, 'en' => $english ) );
		$other = $this->translated_post( 'en' );
		pll_save_post_translations( array( 'en' => $other, 'it' => $this->translated_post( 'it', array( 'post_status' => 'draft' ) ) ) );
		$this->feed( 'news' );

		$items = $this->items( 'news' );

		$this->assertCount( 3, $items );
		$this->assertSame( array( 'it', 'it_IT', 'it-IT', 'it-IT' ), array( $items[ $italian ]['code'], $items[ $italian ]['locale'], $items[ $italian ]['w3c'], $items[ $italian ]['site'] ) );
		$this->assertSame( array( 'en', 'en_US', 'en-US', 'it-IT' ), array( $items[ $english ]['code'], $items[ $english ]['locale'], $items[ $english ]['w3c'], $items[ $english ]['site'] ) );

		$group = $items[ $italian ]['translation_group'];
		$this->assertIsString( $group );
		$this->assertNotSame( '', $group );
		$this->assertSame( $group, $items[ $english ]['translation_group'] );
		$this->assertIsString( $items[ $other ]['translation_group'] );
		$this->assertNotSame( $group, $items[ $other ]['translation_group'] );
	}

	/**
	 * An item without a language, without translations or of a post type that Polylang does not translate gives null
	 * for the item sources; the site language is still there.
	 */
	public function test_items_without_a_language_give_null() {
		register_post_type( self::PLAIN_TYPE, array( 'public' => true ) );
		$lonely   = $this->translated_post( 'it' );
		$untagged = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$plain    = self::factory()->post->create( array( 'post_status' => 'publish', 'post_type' => self::PLAIN_TYPE ) );
		$this->feed( 'mixed', array( 'post', self::PLAIN_TYPE ) );

		$items = $this->items( 'mixed' );

		$this->assertSame( 'it', $items[ $lonely ]['code'] );
		$this->assertNull( $items[ $lonely ]['translation_group'] );
		foreach ( array( $untagged, $plain ) as $id ) {
			foreach ( array( 'code', 'locale', 'w3c', 'translation_group' ) as $key ) {
				$this->assertNull( $items[ $id ][ $key ], $id . ' ' . $key );
			}
			$this->assertSame( 'en-US', $items[ $id ]['site'] );
		}
	}

	/**
	 * A page of 5 items costs the same queries as a page of 1: languages and groups come from the term cache, which
	 * the query of the page fills.
	 */
	public function test_language_sources_cost_no_query_per_item() {
		global $wpdb;
		for ( $i = 0; $i < 5; $i++ ) {
			$italian = $this->translated_post( 'it' );
			pll_save_post_translations( array( 'it' => $italian, 'en' => $this->translated_post( 'en' ) ) );
		}
		$this->feed( 'cost' );

		$queries = function ( int $per_page ) use ( $wpdb ): int {
			wp_cache_flush();
			$before = $wpdb->num_queries;
			$this->assertCount( $per_page, $this->items( 'cost', array( 'per_page' => $per_page ) ) );

			return $wpdb->num_queries - $before;
		};

		// The first request also loads what WordPress and Polylang keep in memory outside the object cache.
		$queries( 1 );
		$one  = $queries( 1 );
		$many = $queries( 5 );

		$this->assertSame( $one, $many, sprintf( '1 item: %d queries, 5 items: %d queries.', $one, $many ) );
	}
}

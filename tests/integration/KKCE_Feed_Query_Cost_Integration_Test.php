<?php
/**
 * Integration tests of the cost of a page of a feed.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Settings;

/**
 * A page of a feed costs the same number of database queries with 1 item and with 50: meta, terms and authors
 * are loaded for the whole page, never item by item.
 */
class KKCE_Feed_Query_Cost_Integration_Test extends WP_UnitTestCase {

	/**
	 * Items of the large page.
	 */
	private const ITEMS = 50;

	/**
	 * Registers the meta again (the test case unregisters them), and starts a REST server with the routes.
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
	 * A feed that maps every core source, the author archive URL confirmed, and a post meta.
	 *
	 * @return void
	 */
	private function feed(): void {
		$sources = array(
			'core.id', 'core.slug', 'core.title', 'core.content', 'core.excerpt', 'core.permalink', 'core.shortlink',
			'core.date', 'core.modified', 'core.author.id', 'core.author.name', 'core.author.url', 'core.type', 'core.type_label',
			'meta.kkce_price',
		);
		$rows    = array();
		foreach ( $sources as $i => $source ) {
			$row = array(
				'key'     => 'k' . $i,
				'kind'    => 'field',
				'sources' => array( '*' => array( $source ) ),
			);
			if ( 'core.author.url' === $source ) {
				$row['confirmed'] = array( $source );
			}
			$rows[] = $row;
		}

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
		update_post_meta( $feed, Feed_Meta::META_PER_PAGE, '50' );
		Feed_Meta::save_mapping( $feed, (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) ) );
	}

	/**
	 * Items with their own author, category and meta, so that nothing is shared between them.
	 *
	 * @return void
	 */
	private function items(): void {
		for ( $i = 0; $i < self::ITEMS; $i++ ) {
			$author   = self::factory()->user->create( array( 'role' => 'author', 'display_name' => 'Author ' . $i ) );
			$category = self::factory()->category->create( array( 'name' => 'Category ' . $i ) );
			$post     = self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_author'   => $author,
					'post_category' => array( $category ),
					'post_content'  => '<!-- wp:paragraph --><p>Item ' . $i . '</p><!-- /wp:paragraph -->',
				)
			);
			add_post_meta( $post, 'kkce_price', (string) $i );
		}
	}

	/**
	 * Number of database queries of one request of the feed, with empty caches.
	 *
	 * @param int $per_page Items of the page.
	 * @return int
	 */
	private function queries( int $per_page ): int {
		global $wpdb;

		wp_cache_flush();
		$before   = $wpdb->num_queries;
		$request  = new WP_REST_Request( 'GET', '/kkce/v1/news' );
		$request->set_query_params( array( 'per_page' => $per_page ) );
		$response = rest_do_request( $request );
		$count    = $wpdb->num_queries - $before;

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( $per_page, $response->get_data()['items'] );

		return $count;
	}

	/**
	 * A page of 50 items costs the same queries as a page of 1, with a permalink structure that reads the category.
	 */
	public function test_a_page_costs_the_same_queries_with_1_and_50_items() {
		$this->set_permalink_structure( '/%category%/%postname%/' );
		$this->feed();
		$this->items();

		// The first request also loads what WordPress keeps in memory outside the object cache: not measured.
		$this->queries( 1 );

		$one   = $this->queries( 1 );
		$fifty = $this->queries( self::ITEMS );

		$this->assertSame( $one, $fifty, sprintf( '1 item: %d queries, %d items: %d queries.', $one, self::ITEMS, $fifty ) );
	}
}

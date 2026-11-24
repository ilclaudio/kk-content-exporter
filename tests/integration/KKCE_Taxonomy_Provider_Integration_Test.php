<?php
/**
 * Integration tests of the taxonomy sources.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Settings;
use KKCE\Sources\Catalog_Factory;
use KKCE\Sources\Taxonomy_Provider;

/**
 * Every public taxonomy of a post type gives the list of the names of the terms of an item and its first term; private
 * taxonomies and those of other post types give nothing.
 */
class KKCE_Taxonomy_Provider_Integration_Test extends WP_UnitTestCase {

	/**
	 * A taxonomy that is not public, registered by the tests.
	 */
	private const PRIVATE_TAXONOMY = 'kkce_internal';

	/**
	 * Registers the meta again (the test case unregisters them) and a private taxonomy of posts.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		Feed_Meta::register();
		register_taxonomy( self::PRIVATE_TAXONOMY, 'post', array( 'public' => false ) );
	}

	/**
	 * Drops the private taxonomy and the REST server.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		unregister_taxonomy( self::PRIVATE_TAXONOMY );
		parent::tear_down();
	}

	/**
	 * Identifiers of the taxonomy sources of the catalog of a feed.
	 *
	 * @param string[] $post_types Post types.
	 * @return list<string>
	 */
	private function taxonomy_sources( array $post_types ): array {
		$ids = array();
		foreach ( Catalog_Factory::for_post_types( $post_types )->sources() as $source ) {
			if ( 'taxonomy' === $source->group() ) {
				$ids[] = $source->post_type . ':' . $source->id . ':' . $source->type;
			}
		}

		return $ids;
	}

	/**
	 * Every public taxonomy of the post type gives its two sources, for that post type only; a private taxonomy none, a
	 * post type without taxonomies none.
	 */
	public function test_the_catalog_offers_the_public_taxonomies_of_each_post_type() {
		$sources = $this->taxonomy_sources( array( 'post', 'page' ) );

		foreach ( array( 'category', 'post_tag' ) as $taxonomy ) {
			$this->assertContains( 'post:taxonomy.' . $taxonomy . ':list', $sources );
			$this->assertContains( 'post:taxonomy.' . $taxonomy . '.first:string', $sources );
		}
		$this->assertSame( array(), preg_grep( '/' . self::PRIVATE_TAXONOMY . '/', $sources ) );
		$this->assertSame( array(), preg_grep( '/^page:/', $sources ) );

		$labels = array_column( array_map( static fn( $source ) => (array) $source, Taxonomy_Provider::sources( 'post' ) ), 'label', 'id' );
		$this->assertSame( 'Categories (all terms)', $labels['taxonomy.category'] );
		$this->assertSame( 'Category (first term, alphabetical)', $labels['taxonomy.category.first'] );
	}

	/**
	 * The terms are the plain names of the assigned terms in alphabetical order, commas and entities included, a child
	 * without its parent; the first term is the first of them. Without terms: `[]` and null.
	 */
	public function test_values_are_the_names_of_the_assigned_terms() {
		$music = self::factory()->category->create( array( 'name' => 'Music' ) );
		$jazz  = self::factory()->category->create( array( 'name' => 'Jazz', 'parent' => $music ) );
		$rock  = self::factory()->category->create( array( 'name' => 'Rock, Pop' ) );
		$arts  = self::factory()->category->create( array( 'name' => 'Arts & crafts' ) );
		$post  = get_post( self::factory()->post->create( array( 'post_category' => array( $rock, $jazz, $arts ) ) ) );

		$this->assertSame( array( 'Arts & crafts', 'Jazz', 'Rock, Pop' ), Taxonomy_Provider::value( $post, 'taxonomy.category' ) );
		$this->assertSame( 'Arts & crafts', Taxonomy_Provider::value( $post, 'taxonomy.category.first' ) );
		$this->assertSame( array(), Taxonomy_Provider::value( $post, 'taxonomy.post_tag' ) );
		$this->assertNull( Taxonomy_Provider::value( $post, 'taxonomy.post_tag.first' ) );
	}

	/**
	 * A private taxonomy, a taxonomy of another post type, one that does not exist and identifiers of another shape give
	 * null.
	 */
	public function test_other_taxonomies_and_identifiers_give_null() {
		$post = get_post( self::factory()->post->create() );
		$page = get_post( self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		wp_set_object_terms( $post->ID, array( 'secret' ), self::PRIVATE_TAXONOMY );

		$this->assertNull( Taxonomy_Provider::value( $post, 'taxonomy.' . self::PRIVATE_TAXONOMY ) );
		$this->assertNull( Taxonomy_Provider::value( $page, 'taxonomy.category' ) );
		foreach ( array( 'taxonomy.nope', 'taxonomy.', 'taxonomy..first', 'taxonomy.category.first.first', 'meta.category' ) as $id ) {
			$this->assertNull( Taxonomy_Provider::value( $post, $id ), $id );
		}
		$this->assertSame( 'list', Taxonomy_Provider::declared_type( 'taxonomy.category' ) );
		$this->assertSame( 'string', Taxonomy_Provider::declared_type( 'taxonomy.category.first' ) );
		$this->assertNull( Taxonomy_Provider::declared_type( 'core.title' ) );
	}

	/**
	 * The feed exports the terms as a JSON list with the type `auto`, and the first term can be the input of a lookup
	 * table, which a list cannot.
	 */
	public function test_the_feed_exports_the_terms_and_a_table_reads_the_first_term() {
		wp_set_current_user( 0 );
		delete_option( Settings::OPTION );
		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );

		$events = self::factory()->category->create( array( 'name' => 'Events' ) );
		$post   = self::factory()->post->create( array( 'post_status' => 'publish', 'post_category' => array( $events ), 'tags_input' => array( 'jazz', 'live' ) ) );
		$feed   = self::factory()->post->create( array( 'post_type' => Feed_Post_Type::POST_TYPE, 'post_status' => 'publish', 'post_name' => 'news' ) );
		add_post_meta( $feed, Feed_Meta::META_POST_TYPE, 'post' );
		update_post_meta( $feed, Feed_Meta::META_ACTIVE, '1' );
		update_post_meta( $feed, Feed_Meta::META_ACCESS, Feed_Meta::ACCESS_PUBLIC );
		$rows = array(
			array( 'key' => 'id', 'kind' => 'field', 'sources' => array( '*' => array( 'core.id' ) ) ),
			array( 'key' => 'tags', 'kind' => 'field', 'sources' => array( '*' => array( 'taxonomy.post_tag' ) ) ),
			array(
				'key'     => 'section',
				'kind'    => 'lookup',
				'inputs'  => array( '*' => 'taxonomy.category.first' ),
				'cases'   => array( array( 'match' => 'Events', 'output' => array( 'fixed' => 'events' ) ) ),
				'default' => array( 'fixed' => 'other' ),
			),
		);
		$mapping = (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) );
		$this->assertSame( array(), KKCE\Output\Catalog_Validator::validate_text( $mapping, Catalog_Factory::for_post_types( array( 'post' ) ), 'wp_json_encode' )['errors'] );
		Feed_Meta::save_mapping( $feed, $mapping );

		$response = rest_do_request( new WP_REST_Request( 'GET', '/kkce/v1/news' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			array( 'id' => $post, 'tags' => array( 'jazz', 'live' ), 'section' => 'events' ),
			(array) $response->get_data()['items'][0]
		);
	}
}

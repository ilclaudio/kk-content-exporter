<?php
/**
 * Integration tests of the post meta sources: discovery, hidden keys, values and export.
 *
 * @package KKCE
 */

use KKCE\Output\Item_Exporter;
use KKCE\Rest\Admin_Routes;
use KKCE\Sources\Catalog_Factory;
use KKCE\Sources\Meta_Provider;

/**
 * Registered keys, keys found in the database, the keys that are left out, and the values read from items.
 */
class KKCE_Meta_Provider_Integration_Test extends WP_UnitTestCase {

	/**
	 * Registers the post type of the tests.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		register_post_type( 'kkce_event', array( 'public' => true ) );
	}

	/**
	 * Removes what the tests registered.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		unregister_post_type( 'kkce_event' );
		unregister_meta_key( 'post', 'kkce_seats', 'kkce_event' );
		unregister_meta_key( 'post', 'kkce_open', 'kkce_event' );
		unregister_meta_key( 'post', 'kkce_tags', 'kkce_event' );
		parent::tear_down();
	}

	/**
	 * A published event with meta.
	 *
	 * @param array<string, mixed> $meta   Meta, key to value.
	 * @param string               $status Post status.
	 * @return WP_Post
	 */
	private function event( array $meta, string $status = 'publish' ): WP_Post {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kkce_event',
				'post_status' => $status,
			)
		);
		foreach ( $meta as $key => $value ) {
			add_post_meta( $id, $key, $value );
		}

		return get_post( $id );
	}

	/**
	 * Identifiers of the sources of a post type.
	 *
	 * @param bool $discover Whether the keys in use are read from the database.
	 * @return list<string>
	 */
	private function ids( bool $discover ): array {
		return array_map(
			static fn( $source ) => $source->id,
			Meta_Provider::sources( 'kkce_event', $discover )
		);
	}

	/**
	 * Registered keys are listed without the database, with the declared type of their registration; a structure
	 * is left out.
	 */
	public function test_registered_keys_are_listed_with_their_type() {
		register_post_meta( 'kkce_event', 'kkce_seats', array( 'type' => 'integer', 'single' => true ) );
		register_post_meta( 'kkce_event', 'kkce_open', array( 'type' => 'boolean', 'single' => true ) );
		register_post_meta( 'kkce_event', 'kkce_tags', array( 'type' => 'array', 'single' => true, 'show_in_rest' => array( 'schema' => array( 'items' => array( 'type' => 'string' ) ) ) ) );

		$types = array();
		foreach ( Meta_Provider::sources( 'kkce_event', false ) as $source ) {
			$types[ $source->id ] = $source->type;
		}

		$this->assertSame( 'number', $types['meta.kkce_seats'] );
		$this->assertSame( 'boolean', $types['meta.kkce_open'] );
		$this->assertArrayNotHasKey( 'meta.kkce_tags', $types );
		$this->assertSame( 'number', Meta_Provider::declared_type( 'kkce_event', 'meta.kkce_seats' ) );
		$this->assertNull( Meta_Provider::declared_type( 'kkce_event', 'meta.kkce_tags' ) );
		$this->assertSame( 'string', Meta_Provider::declared_type( 'kkce_event', 'meta.unregistered' ) );
		$this->assertNull( Meta_Provider::declared_type( 'kkce_event', 'core.title' ) );
	}

	/**
	 * The keys of published items of the post type are found, hidden ones too; nothing is read without `discover`.
	 */
	public function test_keys_in_use_are_discovered_only_on_request() {
		$this->event( array( 'place' => 'Pisa', '_price' => '10' ) );

		$this->assertSame( array(), $this->ids( false ) );
		$this->assertSame( array( 'meta._price', 'meta.place' ), $this->ids( true ) );
	}

	/**
	 * Keys of drafts, of other post types and of items in the trash are not listed: only what can be exported.
	 */
	public function test_only_published_items_of_the_post_type_count() {
		$this->event( array( 'in_draft' => 'x' ), 'draft' );
		$this->event( array( 'in_trash' => 'x' ), 'trash' );
		add_post_meta( self::factory()->post->create(), 'on_a_post', 'x' );
		$this->event( array( 'visible' => 'x' ) );

		$this->assertSame( array( 'meta.visible' ), $this->ids( true ) );
	}

	/**
	 * Internal keys of WordPress and of this plugin, the oEmbed cache and the references of ACF are not listed,
	 * and do not use the places of the sample.
	 */
	public function test_internal_keys_and_acf_references_are_left_out() {
		$meta = array(
			'_edit_lock'        => '1:1',
			'_edit_last'        => '1',
			'_wp_page_template' => 'default',
			'_thumbnail_id'     => '5',
			'_oembed_abc'       => '<iframe>',
			'_kkce_mapping'     => '{}',
			'_venue'            => 'field_5f1a2b3c',
			'venue'             => 'Teatro',
			'_note'             => 'a hidden note',
			'with space'        => 'x',
		);
		$this->event( $meta );

		$this->assertSame( array( 'meta._note', 'meta.venue' ), $this->ids( true ) );
	}

	/**
	 * The sample has a limit, and the keys left out do not use it.
	 */
	public function test_the_sample_is_limited() {
		$meta = array();
		for ( $i = 0; $i < Meta_Provider::SAMPLE_LIMIT + 20; $i++ ) {
			$meta[ sprintf( 'field_%03d', $i ) ]      = 'x';
			$meta[ sprintf( '_oembed_%03d', $i ) ] = 'x';
		}
		$this->event( $meta );

		$ids = $this->ids( true );

		$this->assertCount( Meta_Provider::SAMPLE_LIMIT, $ids );
		$this->assertSame( 'meta.field_000', $ids[0] );
		$this->assertSame( array(), array_filter( $ids, static fn( $id ) => str_starts_with( $id, 'meta._oembed_' ) ) );
	}

	/**
	 * A registered key and a found one are listed once, with the registered type; the sensitive names are
	 * listed too, flagged as needing a confirmation, even when they are in use.
	 */
	public function test_registered_and_found_keys_are_merged_and_sensitive_ones_flagged() {
		register_post_meta( 'kkce_event', 'kkce_seats', array( 'type' => 'integer', 'single' => true ) );
		$this->event( array( 'kkce_seats' => '120', 'api_key' => 'abc', '_price' => '10' ) );

		$sources = array();
		foreach ( Meta_Provider::sources( 'kkce_event', true ) as $source ) {
			$sources[ $source->id ] = $source;
		}

		$this->assertSame( array( 'meta.kkce_seats', 'meta._price', 'meta.api_key' ), array_keys( $sources ) );
		$this->assertSame( 'number', $sources['meta.kkce_seats']->type );
		$this->assertTrue( $sources['meta.api_key']->needs_confirmation() );
		$this->assertFalse( $sources['meta._price']->needs_confirmation() );
		$this->assertTrue( $sources['meta._price']->is_hidden() );
		$this->assertFalse( $sources['meta.api_key']->is_hidden() );
	}

	/**
	 * Values: as stored, an empty text stays a value, the first value of a repeated key, and no value for a
	 * missing key, a structure or an internal key.
	 */
	public function test_values() {
		$event = $this->event(
			array(
				'place'      => 'Pisa',
				'empty'      => '',
				'zero'       => '0',
				'structure'  => array( 'a', 'b' ),
				'_edit_lock' => '1:1',
			)
		);
		add_post_meta( $event->ID, 'twice', 'first' );
		add_post_meta( $event->ID, 'twice', 'second' );

		$this->assertSame( 'Pisa', Meta_Provider::value( $event, 'meta.place' ) );
		$this->assertSame( '', Meta_Provider::value( $event, 'meta.empty' ) );
		$this->assertSame( '0', Meta_Provider::value( $event, 'meta.zero' ) );
		$this->assertSame( 'first', Meta_Provider::value( $event, 'meta.twice' ) );
		$this->assertNull( Meta_Provider::value( $event, 'meta.missing' ) );
		$this->assertNull( Meta_Provider::value( $event, 'meta.structure' ) );
		$this->assertNull( Meta_Provider::value( $event, 'meta._edit_lock' ), 'An internal key is never read.' );
		$this->assertNull( Meta_Provider::value( $event, 'core.title' ) );
	}

	/**
	 * The export reads meta in the mapping: text, the registered type for `auto`, `null` for a missing key, and a
	 * key that was typed by hand and is not in the catalog.
	 */
	public function test_the_export_reads_meta() {
		register_post_meta( 'kkce_event', 'kkce_seats', array( 'type' => 'integer', 'single' => true ) );
		$event   = $this->event( array( 'kkce_seats' => '120', 'place' => 'Pisa', '_price' => '10' ) );
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				array( 'key' => 'seats', 'kind' => 'field', 'type' => 'auto', 'sources' => array( '*' => array( 'meta.kkce_seats' ) ) ),
				array( 'key' => 'place', 'kind' => 'field', 'type' => 'string', 'sources' => array( '*' => array( 'meta.place' ) ) ),
				array( 'key' => 'price', 'kind' => 'field', 'type' => 'auto', 'sources' => array( '*' => array( 'meta._price' ) ) ),
				array( 'key' => 'missing', 'kind' => 'field', 'type' => 'string', 'sources' => array( '*' => array( 'meta.nowhere' ) ) ),
				array( 'key' => 'label', 'kind' => 'template', 'type' => 'string', 'templates' => array( '*' => '{meta.place} ({meta._price})' ) ),
			),
		);

		$item = Item_Exporter::export( $event, $mapping );

		$this->assertSame( 120, $item['seats'], 'A registered integer is a number for `auto`.' );
		$this->assertSame( 'Pisa', $item['place'] );
		$this->assertSame( '10', $item['price'], 'An unregistered key is text for `auto`.' );
		$this->assertNull( $item['missing'] );
		$this->assertSame( 'Pisa (10)', $item['label'] );
	}

	/**
	 * Keys typed by the administrator are listed with the others for every post type of the feed; the keys that
	 * cannot be a source are given back as refused and are not listed.
	 */
	public function test_the_catalog_lists_typed_keys_and_refuses_the_others() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$request = new WP_REST_Request( 'GET', '/' . Admin_Routes::NAMESPACE . '/catalog' );
		$request->set_query_params(
			array(
				'post_types' => array( 'kkce_event' ),
				'meta_keys'  => array( 'typed_by_hand', '_hidden_by_hand', '_edit_lock', 'two words', 'typed_by_hand' ),
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		$ids      = array_column( $data['sources'], 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( 'meta.typed_by_hand', $ids );
		$this->assertContains( 'meta._hidden_by_hand', $ids );
		$this->assertNotContains( 'meta._edit_lock', $ids );
		$this->assertSame( array( '_edit_lock', 'two words' ), $data['refused_meta_keys'] );
		$this->assertSame( array( 'typed_by_hand', '_hidden_by_hand' ), array_values( array_filter( array( 'typed_by_hand', '_hidden_by_hand' ), static fn( $key ) => in_array( 'meta.' . $key, $ids, true ) ) ) );
	}

	/**
	 * The catalog route lists the keys in use with the `hidden` flag; the check of a mapping does not read the
	 * database for the list, but accepts a key typed by hand.
	 */
	public function test_the_catalog_route_and_the_preview() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$event = $this->event( array( 'place' => 'Pisa', '_price' => '10' ) );

		$description = Admin_Routes::describe_catalog( array( 'kkce_event' ) );
		$by_id       = array();
		foreach ( $description['sources'] as $source ) {
			$by_id[ $source['id'] ] = $source;
		}

		$this->assertArrayHasKey( 'meta.place', $by_id );
		$this->assertFalse( $by_id['meta.place']['hidden'] );
		$this->assertTrue( $by_id['meta._price']['hidden'] );
		$this->assertFalse( $by_id['core.title']['hidden'] );
		$this->assertSame( 'kkce_event', $by_id['meta.place']['post_type'] );

		$plain = array_map( static fn( $source ) => $source->id, Catalog_Factory::for_post_types( array( 'kkce_event' ) )->sources() );
		$this->assertNotContains( 'meta.place', $plain, 'The check of a mapping does not read the keys in use.' );

		$request = new WP_REST_Request( 'POST', '/' . Admin_Routes::NAMESPACE . '/preview' );
		$request->set_body_params(
			array(
				'mapping'    => wp_json_encode(
					array(
						'version' => 1,
						'rows'    => array(
							array( 'key' => 'typed', 'kind' => 'field', 'sources' => array( '*' => array( 'meta.typed_by_hand' ) ) ),
							array( 'key' => 'place', 'kind' => 'field', 'sources' => array( '*' => array( 'meta.place' ) ) ),
						),
					)
				),
				'post_types' => array( 'kkce_event' ),
				'post_id'    => $event->ID,
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data()['items'][0]['data'];
		$this->assertNull( $data['typed'] );
		$this->assertSame( 'Pisa', $data['place'] );

		$request->set_body_params(
			array(
				'mapping'    => wp_json_encode( array( 'version' => 1, 'rows' => array( array( 'key' => 'lock', 'kind' => 'field', 'sources' => array( '*' => array( 'meta._edit_lock' ) ) ) ) ) ),
				'post_types' => array( 'kkce_event' ),
			)
		);
		$this->assertSame( 400, rest_do_request( $request )->get_status(), 'An internal key is refused.' );
	}
}

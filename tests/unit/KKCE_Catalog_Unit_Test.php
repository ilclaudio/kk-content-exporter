<?php
/**
 * Unit tests of the sources and of the catalog of a feed (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Sources\Catalog;
use KKCE\Sources\Source;

/**
 * Which sources a feed can use, for its post types.
 */
class KKCE_Catalog_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * A source common to every post type.
	 *
	 * @param string $id   Identifier.
	 * @param string $type Declared type.
	 * @return Source
	 */
	private function common( string $id, string $type = 'string' ): Source {
		return new Source( $id, 'Label of ' . $id, '', $type );
	}

	/**
	 * A source of one post type.
	 *
	 * @param string $id        Identifier.
	 * @param string $post_type Post type.
	 * @return Source
	 */
	private function of_type( string $id, string $post_type ): Source {
		return new Source( $id, 'Label of ' . $id, $post_type, 'string' );
	}

	/**
	 * A source knows its group, whether it is common and whether it needs a confirmation.
	 */
	public function test_a_source_describes_itself() {
		$title  = new Source( 'core.title', 'Title', '', 'string' );
		$price  = new Source( 'meta._price', 'Price', 'product', 'number', array( 'date_format' ) );
		$author = new Source( 'core.author.url', 'Author archive URL', '', 'url' );
		$secret = new Source( 'meta.api_key', 'API key', 'product', 'string' );

		$this->assertSame( 'core', $title->group() );
		$this->assertTrue( $title->is_common() );
		$this->assertFalse( $price->is_common() );
		$this->assertSame( 'product', $price->post_type );
		$this->assertSame( 'number', $price->type );
		$this->assertSame( array( 'date_format' ), $price->options );
		$this->assertFalse( $title->needs_confirmation() );
		$this->assertTrue( $author->needs_confirmation(), 'The author archive URL contains the user nicename.' );
		$this->assertTrue( $secret->needs_confirmation(), 'A sensitive name needs a confirmation.' );
	}

	/**
	 * A source with a malformed identifier, an unknown group or type, or an `auto` type is a programming
	 * error.
	 *
	 * @dataProvider invalid_source_provider
	 *
	 * @param string $id   Identifier.
	 * @param string $type Declared type.
	 */
	public function test_an_invalid_source_is_refused( string $id, string $type ) {
		$this->expectException( InvalidArgumentException::class );

		new Source( $id, 'Label', '', $type );
	}

	/**
	 * Data for test_an_invalid_source_is_refused().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function invalid_source_provider(): array {
		return array(
			'no group'      => array( 'title', 'string' ),
			'unknown group' => array( 'user.email', 'string' ),
			'auto type'     => array( 'core.title', 'auto' ),
			'unknown type'  => array( 'core.title', 'date' ),
		);
	}

	/**
	 * Common sources appear once, then the sources of each post type of the feed, in the order of the
	 * feed; sources of other post types are left out.
	 */
	public function test_the_catalog_keeps_the_sources_of_the_feed() {
		$catalog = new Catalog(
			array(
				$this->of_type( 'meta.place', 'event' ),
				$this->common( 'core.id', 'number' ),
				$this->of_type( 'meta._price', 'product' ),
				$this->common( 'core.title' ),
				$this->common( 'core.id', 'number' ),
				$this->of_type( 'meta._price', 'event' ),
				$this->of_type( 'meta.place', 'event' ),
			),
			array( 'event', 'post' )
		);

		$this->assertSame(
			array( '*:core.id', '*:core.title', 'event:meta.place', 'event:meta._price' ),
			array_map(
				static fn( Source $source ): string => ( $source->is_common() ? '*' : $source->post_type ) . ':' . $source->id,
				$catalog->sources()
			)
		);
	}

	/**
	 * A source is found for a post type of the feed when it is common or belongs to that post type; for
	 * `*` (every post type) only common sources count.
	 */
	public function test_find() {
		$catalog = new Catalog(
			array(
				$this->common( 'core.title' ),
				$this->of_type( 'meta.place', 'event' ),
				$this->of_type( 'meta._price', 'product' ),
			),
			array( 'event', 'post' )
		);

		$this->assertSame( 'core.title', $catalog->find( 'core.title', 'event' )?->id );
		$this->assertSame( 'core.title', $catalog->find( 'core.title', '*' )?->id );
		$this->assertSame( 'event', $catalog->find( 'meta.place', 'event' )?->post_type );
		$this->assertNull( $catalog->find( 'meta.place', 'post' ), 'Another post type of the feed.' );
		$this->assertNull( $catalog->find( 'meta.place', '*' ), 'Not common.' );
		$this->assertNull( $catalog->find( 'meta._price', 'product' ), 'Not a post type of the feed.' );
		$this->assertNull( $catalog->find( 'core.nothing', 'event' ) );
	}

	/**
	 * With two or more post types the catalog proposes the `type` row (slug of the post type); with
	 * one it proposes nothing, although the source exists.
	 */
	public function test_the_type_row_is_proposed_only_with_several_post_types() {
		$sources = array( $this->common( 'core.type' ), $this->common( 'core.title' ) );

		$this->assertSame(
			array(
				array(
					'key'     => 'type',
					'kind'    => 'field',
					'type'    => 'string',
					'sources' => array( '*' => array( 'core.type' ) ),
				),
			),
			( new Catalog( $sources, array( 'post', 'event' ) ) )->suggested_rows()
		);
		$this->assertSame( array(), ( new Catalog( $sources, array( 'post' ) ) )->suggested_rows() );
		$this->assertSame( array(), ( new Catalog( array( $this->common( 'core.title' ) ), array( 'post', 'event' ) ) )->suggested_rows(), 'No row without the source.' );
	}
}

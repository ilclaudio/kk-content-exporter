<?php
/**
 * Unit tests of the response of a feed (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Feed_Response;

/**
 * The container of the response is fixed: items, pagination and meta.
 */
class KKCE_Feed_Response_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Generation time used by the tests: 2026-11-06T12:00:00Z.
	 */
	private const GENERATED_AT = 1793966400;

	/**
	 * The response has the three blocks, in this order, with the items, the pagination and the meta.
	 */
	public function test_build_gives_the_fixed_container() {
		$response = Feed_Response::build(
			array( array( 'title' => 'Example', 'id' => 7 ) ),
			1,
			50,
			1,
			'news',
			self::GENERATED_AT
		);

		$this->assertSame( array( 'items', 'pagination', 'meta' ), array_keys( $response ) );
		$this->assertEquals( array( (object) array( 'title' => 'Example', 'id' => 7 ) ), $response['items'] );
		$this->assertSame(
			array( 'page' => 1, 'per_page' => 50, 'total' => 1, 'total_pages' => 1 ),
			$response['pagination']
		);
		$this->assertSame( array( 'feed' => 'news', 'generated_at' => '2026-11-06T12:00:00Z' ), $response['meta'] );
	}

	/**
	 * Every item is a JSON object, also without values; no items give an empty list.
	 */
	public function test_items_are_objects_and_no_items_is_an_empty_list() {
		$response = Feed_Response::build( array( array() ), 1, 50, 1, 'news', self::GENERATED_AT );
		$this->assertSame( '{}', json_encode( $response['items'][0] ) );

		$empty = Feed_Response::build( array(), 1, 50, 0, 'news', self::GENERATED_AT );
		$this->assertSame( array(), $empty['items'] );
		$this->assertSame( '[]', json_encode( $empty['items'] ) );
	}

	/**
	 * Total pages: none without items, then one more every `per_page` items.
	 *
	 * @dataProvider total_pages_provider
	 *
	 * @param int $total    Items of the feed.
	 * @param int $per_page Items per page.
	 * @param int $expected Total pages.
	 */
	public function test_total_pages( int $total, int $per_page, int $expected ) {
		$this->assertSame( $expected, Feed_Response::build( array(), 1, $per_page, $total, 'news', self::GENERATED_AT )['pagination']['total_pages'] );
	}

	/**
	 * Data for test_total_pages().
	 *
	 * @return array<string, array{0: int, 1: int, 2: int}>
	 */
	public function total_pages_provider(): array {
		return array(
			'no items'         => array( 0, 50, 0 ),
			'one item'         => array( 1, 50, 1 ),
			'one full page'    => array( 50, 50, 1 ),
			'one more'         => array( 51, 50, 2 ),
			'one per page'     => array( 3, 1, 3 ),
		);
	}

	/**
	 * Items per page: the value of the feed, never above the global maximum.
	 *
	 * @dataProvider per_page_provider
	 *
	 * @param int $feed     Items per page of the feed.
	 * @param int $max      Global maximum.
	 * @param int $expected Items per page used.
	 */
	public function test_per_page_is_the_value_of_the_feed_within_the_maximum( int $feed, int $max, int $expected ) {
		$this->assertSame( $expected, Feed_Response::per_page( $feed, $max ) );
	}

	/**
	 * Data for test_per_page_is_the_value_of_the_feed_within_the_maximum().
	 *
	 * @return array<string, array{0: int, 1: int, 2: int}>
	 */
	public function per_page_provider(): array {
		return array(
			'below the maximum'     => array( 50, 100, 50 ),
			'equal to the maximum'  => array( 100, 100, 100 ),
			'above a lowered max'   => array( 50, 20, 20 ),
		);
	}

	/**
	 * A requested value replaces the one of the feed, above or below it (the route refuses one above the maximum).
	 */
	public function test_a_requested_per_page_replaces_the_one_of_the_feed() {
		$this->assertSame( 10, Feed_Response::per_page( 50, 100, 10 ) );
		$this->assertSame( 80, Feed_Response::per_page( 50, 100, 80 ) );
	}

	/**
	 * Regression: the number of pages never overflows, also with the largest page size (it threw a TypeError).
	 */
	public function test_total_pages_does_not_overflow() {
		$this->assertSame( 1, Feed_Response::build( array(), 1, PHP_INT_MAX, 5, 'news', self::GENERATED_AT )['pagination']['total_pages'] );
		$this->assertSame( 1, Feed_Response::build( array(), 1, PHP_INT_MAX, PHP_INT_MAX, 'news', self::GENERATED_AT )['pagination']['total_pages'] );
		$this->assertSame( 2, Feed_Response::build( array(), 1, PHP_INT_MAX - 1, PHP_INT_MAX, 'news', self::GENERATED_AT )['pagination']['total_pages'] );
	}

	/**
	 * A page can be read only when its offset, (page - 1) x per page, is a whole number of PHP.
	 */
	public function test_offset_fits() {
		$this->assertTrue( Feed_Response::offset_fits( 1, PHP_INT_MAX ) );
		$this->assertTrue( Feed_Response::offset_fits( 2147483647, 100 ) );
		$this->assertTrue( Feed_Response::offset_fits( 2, PHP_INT_MAX ) );
		$this->assertFalse( Feed_Response::offset_fits( 3, PHP_INT_MAX ) );
		$this->assertFalse( Feed_Response::offset_fits( 1000000, 10000000000000 ) );
	}
}

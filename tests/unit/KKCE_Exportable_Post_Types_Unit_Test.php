<?php
/**
 * Unit tests of the split of requested post types (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Sources\Exportable_Post_Types;

/**
 * Requested post types are split into the exportable ones and the others.
 */
class KKCE_Exportable_Post_Types_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Exportable post types, as `Exportable_Post_Types::selectable()` gives them.
	 *
	 * @var array<string, string>
	 */
	private const SELECTABLE = array(
		'page'  => 'Pages',
		'post'  => 'Posts',
		'event' => 'Events',
	);

	/**
	 * Both lists keep the order of the request.
	 */
	public function test_split_keeps_the_order_of_the_request() {
		$this->assertSame(
			array( array( 'post', 'event', 'page' ), array( 'gone', 'attachment' ) ),
			Exportable_Post_Types::split( array( 'post', 'gone', 'event', 'attachment', 'page' ), self::SELECTABLE )
		);
	}

	/**
	 * A post type requested twice appears once.
	 */
	public function test_split_removes_duplicates() {
		$this->assertSame(
			array( array( 'post' ), array( 'gone' ) ),
			Exportable_Post_Types::split( array( 'post', 'gone', 'post', 'gone' ), self::SELECTABLE )
		);
	}

	/**
	 * A single value counts as a list of one, and values are compared as text.
	 */
	public function test_split_reads_a_single_value_and_values_as_text() {
		$this->assertSame( array( array( 'post' ), array() ), Exportable_Post_Types::split( 'post', self::SELECTABLE ) );
		$this->assertSame( array( array(), array( '12' ) ), Exportable_Post_Types::split( array( 12 ), self::SELECTABLE ) );
	}

	/**
	 * Nothing requested, or nothing exportable, gives empty lists.
	 */
	public function test_split_of_nothing() {
		$this->assertSame( array( array(), array() ), Exportable_Post_Types::split( array(), self::SELECTABLE ) );
		$this->assertSame( array( array(), array() ), Exportable_Post_Types::split( null, self::SELECTABLE ) );
		$this->assertSame( array( array(), array( 'post' ) ), Exportable_Post_Types::split( array( 'post' ), array() ) );
	}
}

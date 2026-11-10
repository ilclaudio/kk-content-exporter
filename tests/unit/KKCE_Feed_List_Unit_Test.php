<?php
/**
 * Unit tests of the columns of the feed list, with WordPress mocked by Brain Monkey.
 *
 * @package KKCE
 */

use KKCE\Admin\Feed_List;

/**
 * The list of the feeds shows the name and four configuration columns.
 */
class KKCE_Feed_List_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The columns keep the checkbox and the title and add the four feed columns, in order.
	 */
	public function test_columns_keep_checkbox_and_title_and_add_the_feed_columns() {
		$columns = Feed_List::columns(
			array(
				'cb'    => '<input type="checkbox" />',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', 'kkce_endpoint', 'kkce_source', 'kkce_access', 'kkce_status' ),
			array_keys( $columns )
		);
		$this->assertSame( '<input type="checkbox" />', $columns['cb'] );
		$this->assertSame( 'Title', $columns['title'] );
	}

	/**
	 * A value that is not an array is returned unchanged.
	 */
	public function test_columns_with_unexpected_type_are_returned_unchanged() {
		$this->assertSame( 'not-an-array', Feed_List::columns( 'not-an-array' ) );
	}

	/**
	 * An unknown column prints nothing and reads nothing.
	 */
	public function test_render_column_ignores_other_columns() {
		\Brain\Monkey\Functions\expect( 'get_post_meta' )->never();
		\Brain\Monkey\Functions\expect( 'get_post_field' )->never();

		ob_start();
		Feed_List::render_column( 'comments', 5 );

		$this->assertSame( '', ob_get_clean() );
	}
}

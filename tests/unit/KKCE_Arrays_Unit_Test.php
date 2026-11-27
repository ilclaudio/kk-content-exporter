<?php
/**
 * Unit tests of the helpers on arrays (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Arrays;

/**
 * is_list() answers as the native array_is_list() of PHP.
 */
class KKCE_Arrays_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Arrays whose keys are 0, 1, 2… in order, and the empty array, are lists.
	 */
	public function test_lists() {
		foreach ( array( array(), array( 'a' ), array( 'a', 'b', null ), array( 0 => 'a', 1 => 'b' ), array( array( 'x' => 1 ) ) ) as $value ) {
			$this->assertTrue( Arrays::is_list( $value ), var_export( $value, true ) );
		}
	}

	/**
	 * Keys with gaps, out of order, not starting at 0 or not integers make an array that is not a list.
	 */
	public function test_arrays_that_are_not_lists() {
		$values = array(
			array( 1 => 'a' ),
			array( 0 => 'a', 2 => 'b' ),
			array( 1 => 'b', 0 => 'a' ),
			array( 'a' => 1 ),
			array( 0 => 'a', 'b' => 2 ),
			array( '1' => 'a' ),
		);
		foreach ( $values as $value ) {
			$this->assertFalse( Arrays::is_list( $value ), var_export( $value, true ) );
		}
	}

	/**
	 * The answer is the one of the native function on every case above.
	 */
	public function test_same_answer_as_the_native_function() {
		$values = array( array(), array( 'a' ), array( 1 => 'a' ), array( 1 => 'b', 0 => 'a' ), array( 'a' => 1 ), array( 0 => 'a', 'b' => 2 ) );
		foreach ( $values as $value ) {
			$this->assertSame( array_is_list( $value ), Arrays::is_list( $value ), var_export( $value, true ) );
		}
	}
}

<?php
/**
 * Unit tests of the date filters of a feed (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Rest\Date_Filters;

/**
 * Dates in the form of the dates of the feed (ISO 8601 with a time zone), a half-open interval, and the switches
 * of the settings.
 */
class KKCE_Date_Filters_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Accepted dates, in UTC as stored by WordPress.
	 *
	 * @dataProvider valid_date_provider
	 *
	 * @param string $value    Parameter.
	 * @param string $expected Date in GMT.
	 */
	public function test_parse_accepts_the_dates_of_the_feed( string $value, string $expected ) {
		$this->assertSame( $expected, Date_Filters::parse( $value ) );
	}

	/**
	 * Data for test_parse_accepts_the_dates_of_the_feed().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function valid_date_provider(): array {
		return array(
			'UTC'                       => array( '2026-11-01T10:00:00Z', '2026-11-01 10:00:00' ),
			'fraction cut, not rounded' => array( '2026-11-01T10:00:00.999Z', '2026-11-01 10:00:00' ),
			'positive offset'           => array( '2026-11-01T11:30:00+01:00', '2026-11-01 10:30:00' ),
			'negative offset'           => array( '2026-10-31T22:00:00-05:00', '2026-11-01 03:00:00' ),
			'offset across the year'    => array( '2027-01-01T00:30:00+01:00', '2026-12-31 23:30:00' ),
			'zero offset'               => array( '2026-11-01T10:00:00+00:00', '2026-11-01 10:00:00' ),
			'leap day'                  => array( '2028-02-29T00:00:00Z', '2028-02-29 00:00:00' ),
			'first supported date'      => array( '1000-01-01T00:00:00Z', '1000-01-01 00:00:00' ),
			'last supported date'       => array( '9999-12-31T23:59:59Z', '9999-12-31 23:59:59' ),
		);
	}

	/**
	 * Dates without a time zone, incomplete or impossible, and other values, are refused.
	 *
	 * @dataProvider invalid_date_provider
	 *
	 * @param mixed $value Parameter.
	 */
	public function test_parse_refuses_other_values( $value ) {
		$this->assertNull( Date_Filters::parse( $value ) );
	}

	/**
	 * Data for test_parse_refuses_other_values().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_date_provider(): array {
		return array(
			'no time zone'      => array( '2026-11-01T10:00:00' ),
			'date only'         => array( '2026-11-01' ),
			'space separator'   => array( '2026-11-01 10:00:00Z' ),
			'no seconds'        => array( '2026-11-01T10:00Z' ),
			'compact offset'    => array( '2026-11-01T10:00:00+0100' ),
			'impossible day'    => array( '2026-02-30T10:00:00Z' ),
			'impossible hour'   => array( '2026-11-01T24:00:00Z' ),
			'impossible minute' => array( '2026-11-01T10:60:00Z' ),
			'impossible offset' => array( '2026-11-01T10:00:00+15:00' ),
			'year zero'         => array( '0000-01-01T00:00:00Z' ),
			'remapped year'     => array( '0050-01-01T00:00:00Z' ),
			'year below range'  => array( '0999-12-31T23:59:59Z' ),
			'offset underflow'  => array( '1000-01-01T00:00:00+01:00' ),
			'Unix timestamp'    => array( '1793966400' ),
			'words'             => array( 'yesterday' ),
			'empty'             => array( '' ),
			'null byte'         => array( "2026-11-01T10:00:00Z\0" ),
			'not a string'      => array( array( '2026-11-01T10:00:00Z' ) ),
		);
	}

	/**
	 * A value is checked against its switch first: a filter turned off is refused whatever its value.
	 */
	public function test_check_value() {
		$this->assertSame( Date_Filters::VALID, Date_Filters::check_value( '2026-11-01T10:00:00Z', true ) );
		$this->assertSame( Date_Filters::ERROR_FORMAT, Date_Filters::check_value( '2026-11-01', true ) );
		$this->assertSame( Date_Filters::ERROR_DISABLED, Date_Filters::check_value( '2026-11-01T10:00:00Z', false ) );
		$this->assertSame( Date_Filters::ERROR_DISABLED, Date_Filters::check_value( 'yesterday', false ) );
	}

	/**
	 * The interval of the modified date is empty when `after` is not before `before`.
	 */
	public function test_an_empty_interval_is_an_error() {
		$this->assertFalse( Date_Filters::empty_interval( '2026-11-01 10:00:00', '2026-11-01 10:00:01' ) );
		$this->assertTrue( Date_Filters::empty_interval( '2026-11-01 10:00:00', '2026-11-01 10:00:00' ) );
		$this->assertTrue( Date_Filters::empty_interval( '2026-11-02 00:00:00', '2026-11-01 00:00:00' ) );
		$this->assertFalse( Date_Filters::empty_interval( null, '2026-11-01 00:00:00' ) );
		$this->assertFalse( Date_Filters::empty_interval( '2026-11-01 00:00:00', null ) );
	}

	/**
	 * The clauses of the query: `after` included, `before` excluded, each on its own column.
	 */
	public function test_date_query() {
		$this->assertSame( array(), Date_Filters::date_query( array() ) );
		$this->assertSame(
			array(
				'relation' => 'AND',
				array(
					'column'    => 'post_modified_gmt',
					'after'     => '2026-11-01 10:00:00',
					'inclusive' => true,
				),
				array(
					'column'    => 'post_modified_gmt',
					'before'    => '2026-11-02 10:00:00',
					'inclusive' => false,
				),
				array(
					'column'    => 'post_date_gmt',
					'after'     => '2026-01-01 00:00:00',
					'inclusive' => true,
				),
			),
			Date_Filters::date_query(
				array(
					'modified_after'  => '2026-11-01 10:00:00',
					'modified_before' => '2026-11-02 10:00:00',
					'published_after' => '2026-01-01 00:00:00',
				)
			)
		);
	}

	/**
	 * Every filter has its switch in the settings.
	 */
	public function test_every_filter_has_its_setting() {
		$this->assertSame(
			array(
				'modified_after'  => 'filter_modified_after',
				'modified_before' => 'filter_modified_before',
				'published_after' => 'filter_published_after',
			),
			array_map( static fn( array $filter ): string => $filter['setting'], Date_Filters::FILTERS )
		);
	}
}

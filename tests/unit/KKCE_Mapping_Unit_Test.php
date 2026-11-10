<?php
/**
 * Unit tests of the format of the mapping (pure logic, no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Mapping;

/**
 * Reading of the mapping JSON and the key proposed for a source.
 */
class KKCE_Mapping_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * The format has a version and limits.
	 */
	public function test_format_constants() {
		$this->assertSame( 1, Mapping::VERSION );
		$this->assertSame( 100, Mapping::MAX_ROWS );
		$this->assertSame( 64, Mapping::KEY_MAX_LENGTH );
	}

	/**
	 * A blank mapping has the current version and no rows.
	 */
	public function test_blank_mapping() {
		$this->assertSame(
			array(
				'version' => 1,
				'rows'    => array(),
			),
			Mapping::blank()
		);
	}

	/**
	 * A JSON object is read as an array, without losing anything.
	 */
	public function test_decode_reads_a_json_object() {
		$json = '{"version":1,"rows":[{"key":"title","kind":"field","sources":{"*":["core.title"]},"note":"caffè \"x\" \\\\ /"}]}';

		$decoded = Mapping::decode( $json );

		$this->assertIsArray( $decoded );
		$this->assertSame( 1, $decoded['version'] );
		$this->assertSame( array( 'core.title' ), $decoded['rows'][0]['sources']['*'] );
		$this->assertSame( 'caffè "x" \\ /', $decoded['rows'][0]['note'] );
	}

	/**
	 * Anything that is not a JSON object (or a list) gives null: damaged text, scalars, empty values,
	 * values that are not strings, texts above the size limit and nesting that is too deep.
	 *
	 * @dataProvider unreadable_provider
	 *
	 * @param mixed $value Raw value.
	 */
	public function test_decode_gives_null_when_the_value_cannot_be_read( $value ) {
		$this->assertNull( Mapping::decode( $value ) );
	}

	/**
	 * Data for test_decode_gives_null_when_the_value_cannot_be_read().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function unreadable_provider(): array {
		return array(
			'empty string' => array( '' ),
			'damaged'      => array( '{"version":1,' ),
			'null text'    => array( 'null' ),
			'number'       => array( '5' ),
			'string'       => array( '"text"' ),
			'true'         => array( 'true' ),
			'null'         => array( null ),
			'array'        => array( array( 'version' => 1 ) ),
			'integer'      => array( 1 ),
			'too large'    => array( '{"version":1,"rows":[],"x":"' . str_repeat( 'a', Mapping::MAX_BYTES ) . '"}' ),
			'too deep'     => array( str_repeat( '[', 20 ) . str_repeat( ']', 20 ) ),
		);
	}

	/**
	 * The key proposed for a source is its name, made valid.
	 *
	 * @dataProvider suggested_key_provider
	 *
	 * @param string $source   Source identifier.
	 * @param string $expected Proposed key, empty when none can be proposed.
	 */
	public function test_suggest_key( string $source, string $expected ) {
		$this->assertSame( $expected, Mapping::suggest_key( $source ) );
	}

	/**
	 * Data for test_suggest_key().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function suggested_key_provider(): array {
		return array(
			'core field'            => array( 'core.title', 'title' ),
			'core with underscores' => array( 'core.post_date_gmt', 'post_date_gmt' ),
			'meta'                  => array( 'meta.event_date', 'event_date' ),
			'meta with underscore'  => array( 'meta._price', 'price' ),
			'taxonomy'              => array( 'taxonomy.category', 'category' ),
			'dash kept'             => array( 'acf.field-name', 'field-name' ),
			'case kept'             => array( 'meta.eventDate', 'eventDate' ),
			'colon replaced'        => array( 'cmb2.group:field', 'group_field' ),
			'dot inside the name'   => array( 'meta.a.b', 'a.b' ),
			'only digits'           => array( 'meta.123', '' ),
			'only underscores'      => array( 'meta.___', '' ),
			'no group'              => array( 'title', '' ),
			'empty name'            => array( 'meta.', '' ),
			'empty'                 => array( '', '' ),
			'cut to the limit'      => array( 'meta.' . str_repeat( 'a', 80 ), str_repeat( 'a', 64 ) ),
		);
	}
}

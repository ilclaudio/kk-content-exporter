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
	 * The draft of a refused mapping is the text decoded and encoded again: unfinished rows stay editable
	 * (an empty object stays `{}`), values are kept, and whitespace or duplicate keys of the submitted text are not.
	 */
	public function test_draft_encodes_again_a_mapping_with_unfinished_rows() {
		$json = '{ "version": 1, "rows": [ {}, { "key": "t", "kind": "template", "template": "<b>{core.title}</b>", "key": "x" } ] }';

		$this->assertSame(
			'{"version":1,"rows":[{},{"key":"x","kind":"template","template":"<b>{core.title}</b>"}]}',
			Mapping::draft( $json, static fn( $value ) => json_encode( $value, JSON_UNESCAPED_SLASHES ) )
		);
	}

	/**
	 * A text that does not have the shape of a mapping, or that is too large, gives no draft.
	 *
	 * @dataProvider no_draft_provider
	 *
	 * @param mixed $value Submitted value.
	 */
	public function test_draft_is_empty_without_the_shape_of_a_mapping( $value ) {
		$this->assertSame( '', Mapping::draft( $value, 'json_encode' ) );
	}

	/**
	 * Data for test_draft_is_empty_without_the_shape_of_a_mapping().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function no_draft_provider(): array {
		return array(
			'not a string'      => array( array( 'rows' => array() ) ),
			'empty'             => array( '' ),
			'damaged'           => array( '{"version":1,"rows":[' ),
			'invalid UTF-8'     => array( "{\"rows\":[{\"key\":\"\xC3\x28\"}]}" ),
			'a list'            => array( '[{"key":"t"}]' ),
			'without rows'      => array( '{"version":1}' ),
			'rows not a list'   => array( '{"rows":{"0":{"key":"t"}}}' ),
			'rows as an object' => array( '{"rows":{"a":{}}}' ),
			'a row not object'  => array( '{"rows":[{"key":"t"},"<script>"]}' ),
			'too deep'          => array( '{"rows":[{"a":{"b":{"c":{"d":{"e":{"f":{"g":1}}}}}}}]}' ),
			'too large'         => array( '{"rows":[],"x":"' . str_repeat( 'a', Mapping::MAX_BYTES ) . '"}' ),
		);
	}

	/**
	 * A draft whose encoded form is above the limit, or that cannot be encoded, is not kept.
	 */
	public function test_draft_is_empty_when_the_encoded_form_is_not_usable() {
		$this->assertSame( '', Mapping::draft( '{"rows":[]}', static fn(): bool => false ) );
		$this->assertSame( '', Mapping::draft( '{"rows":[]}', static fn(): string => str_repeat( 'a', Mapping::MAX_BYTES + 1 ) ) );
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

	/**
	 * The sources of a row, with the field that holds each one and its post type (`*` for the outputs), in the
	 * order of the row: every place of every chain, every placeholder of every template, inputs and outputs.
	 *
	 * @dataProvider row_sources_provider
	 *
	 * @param array<string, mixed>                          $row      A well-formed row.
	 * @param list<array{0: string, 1: string, 2: string}> $expected Field, post type and source.
	 */
	public function test_row_sources( array $row, array $expected ) {
		$this->assertSame( $expected, Mapping::row_sources( $row ) );
	}

	/**
	 * Data for test_row_sources().
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: list<array{0: string, 1: string, 2: string}>}>
	 */
	public function row_sources_provider(): array {
		return array(
			'field: chains of every post type'         => array(
				array(
					'kind'    => 'field',
					'sources' => array(
						'*'     => array( 'core.excerpt', 'core.title' ),
						'event' => array( 'meta.summary' ),
					),
				),
				array(
					array( 'sources', '*', 'core.excerpt' ),
					array( 'sources', '*', 'core.title' ),
					array( 'sources', 'event', 'meta.summary' ),
				),
			),
			'template: placeholders, alternatives too' => array(
				array(
					'kind'      => 'template',
					'templates' => array(
						'*'    => '{core.title} – {meta.place|"online"}',
						'page' => '{core.title}',
					),
				),
				array(
					array( 'templates', '*', 'core.title' ),
					array( 'templates', '*', 'meta.place' ),
					array( 'templates', 'page', 'core.title' ),
				),
			),
			'lookup: inputs, then sources of the outputs' => array(
				array(
					'kind'    => 'lookup',
					'inputs'  => array( '*' => 'core.type' ),
					'cases'   => array(
						array( 'match' => 'post', 'output' => array( 'fixed' => 'News' ) ),
						array( 'match' => 'event', 'output' => array( 'source' => 'meta.label' ) ),
					),
					'default' => array( 'source' => 'core.type_label' ),
				),
				array(
					array( 'inputs', '*', 'core.type' ),
					array( 'cases', '*', 'meta.label' ),
					array( 'default', '*', 'core.type_label' ),
				),
			),
			'lookup: default that writes the input'    => array(
				array(
					'kind'    => 'lookup',
					'inputs'  => array( 'post' => 'meta.code' ),
					'cases'   => array( array( 'match' => 'a', 'output' => array( 'fixed' => 'A' ) ) ),
					'default' => array( 'input' => true ),
				),
				array( array( 'inputs', 'post', 'meta.code' ) ),
			),
			'condition: input, then and else'         => array(
				array(
					'kind'     => 'condition',
					'inputs'   => array( '*' => 'core.excerpt' ),
					'operator' => 'empty',
					'then'     => array( 'source' => 'core.title' ),
					'else'     => array( 'source' => 'core.excerpt' ),
				),
				array(
					array( 'inputs', '*', 'core.excerpt' ),
					array( 'then', '*', 'core.title' ),
					array( 'else', '*', 'core.excerpt' ),
				),
			),
			'condition: fixed outputs'                 => array(
				array(
					'kind'     => 'condition',
					'inputs'   => array( '*' => 'meta.free' ),
					'operator' => 'equals',
					'matches'  => '1',
					'then'     => array( 'fixed' => 'Free' ),
				),
				array( array( 'inputs', '*', 'meta.free' ) ),
			),
			'fixed: no source'                         => array(
				array(
					'kind'   => 'fixed',
					'values' => array( '*' => 'Site' ),
				),
				array(),
			),
		);
	}
}

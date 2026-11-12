<?php
/**
 * Unit tests of the validation and normalization of the mapping (pure logic, no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Mapping;
use KKCE\Output\Mapping_Validator;

/**
 * Every rule of the mapping format, one error at a time.
 */
class KKCE_Mapping_Validator_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * A valid `field` row.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function field_row( array $override = array() ): array {
		return array_merge(
			array(
				'key'     => 'title',
				'kind'    => 'field',
				'type'    => 'string',
				'sources' => array( '*' => array( 'core.title' ) ),
			),
			$override
		);
	}

	/**
	 * A valid `fixed` row.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function fixed_row( array $override = array() ): array {
		return array_merge(
			array(
				'key'    => 'lang',
				'kind'   => 'fixed',
				'type'   => 'string',
				'values' => array( '*' => 'it' ),
			),
			$override
		);
	}

	/**
	 * A mapping with the given rows.
	 *
	 * @param array<int, mixed> $rows Rows.
	 * @return array<string, mixed>
	 */
	private function mapping( array $rows ): array {
		return array(
			'version' => 1,
			'rows'    => $rows,
		);
	}

	/**
	 * The errors as `row:field:code` strings, to compare them at a glance.
	 *
	 * @param mixed $mapping Mapping to validate.
	 * @return list<string>
	 */
	private function errors( $mapping ): array {
		return array_map(
			static fn( array $error ): string => $error['row'] . ':' . $error['field'] . ':' . $error['code'],
			Mapping_Validator::validate( $mapping )
		);
	}

	/**
	 * Valid mappings give no error: empty, one row of each kind, several rows.
	 */
	public function test_valid_mappings() {
		$this->assertSame( array(), $this->errors( $this->mapping( array() ) ), 'An empty mapping is valid for the format.' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row() ) ) ) );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row() ) ) ) );
		$this->assertSame(
			array(),
			$this->errors(
				$this->mapping(
					array(
						$this->field_row(),
						$this->field_row(
							array(
								'key'     => 'price',
								'type'    => 'number',
								'sources' => array(
									'product' => array( 'meta._price', 'acf.price' ),
									'event'   => array( 'meta.cost' ),
								),
								'options' => array( 'shortcodes' => false ),
							)
						),
						$this->fixed_row( array( 'values' => array( 'post' => 'a', 'event' => 3, 'page' => 1.5, 'x-y_1' => true ) ) ),
					)
				)
			)
		);
	}

	/**
	 * Something that is not a mapping object is refused.
	 *
	 * @dataProvider not_an_object_provider
	 *
	 * @param mixed $mapping Value to validate.
	 */
	public function test_a_value_that_is_not_an_object_is_refused( $mapping ) {
		$this->assertSame( array( '-1::not_object' ), $this->errors( $mapping ) );
	}

	/**
	 * Data for test_a_value_that_is_not_an_object_is_refused().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function not_an_object_provider(): array {
		return array(
			'null'   => array( null ),
			'string' => array( 'text' ),
			'number' => array( 3 ),
			'list'   => array( array( 'a', 'b' ) ),
		);
	}

	/**
	 * A missing or unknown version is refused, and rows are not read.
	 *
	 * @dataProvider unknown_version_provider
	 *
	 * @param array<string, mixed> $mapping Mapping.
	 */
	public function test_an_unknown_version_is_refused( array $mapping ) {
		$this->assertSame( array( '-1:version:unknown' ), $this->errors( $mapping ) );
	}

	/**
	 * Data for test_an_unknown_version_is_refused().
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function unknown_version_provider(): array {
		return array(
			'missing'       => array( array( 'rows' => array() ) ),
			'future'        => array( array( 'version' => 2, 'rows' => array( array( 'x' => 1 ) ) ) ),
			'zero'          => array( array( 'version' => 0, 'rows' => array() ) ),
			'text'          => array( array( 'version' => '1', 'rows' => array() ) ),
			'float'         => array( array( 'version' => 1.0, 'rows' => array() ) ),
			'empty object'  => array( array() ),
		);
	}

	/**
	 * The rows must be a list; unknown fields of the mapping are refused.
	 */
	public function test_rows_must_be_a_list_and_the_mapping_has_no_other_fields() {
		$this->assertSame( array( '-1:rows:format' ), $this->errors( array( 'version' => 1 ) ) );
		$this->assertSame( array( '-1:rows:format' ), $this->errors( array( 'version' => 1, 'rows' => 'x' ) ) );
		$this->assertSame( array( '-1:rows:format' ), $this->errors( array( 'version' => 1, 'rows' => array( 'a' => $this->field_row() ) ) ) );
		$this->assertSame( array( '-1:extra:unknown_field' ), $this->errors( array( 'version' => 1, 'rows' => array(), 'extra' => 1 ) ) );
	}

	/**
	 * Up to the maximum number of rows is accepted; more is refused without reading the rows.
	 */
	public function test_the_number_of_rows_is_limited() {
		$rows = array();
		for ( $i = 1; $i <= Mapping::MAX_ROWS; $i++ ) {
			$rows[] = $this->field_row( array( 'key' => 'k' . $i ) );
		}
		$this->assertSame( array(), $this->errors( $this->mapping( $rows ) ) );

		$rows[] = $this->field_row( array( 'key' => 'one_more' ) );
		$this->assertSame( array( '-1:rows:too_many' ), $this->errors( $this->mapping( $rows ) ) );
	}

	/**
	 * A row that is not an object is refused.
	 */
	public function test_a_row_must_be_an_object() {
		$this->assertSame( array( '0::format' ), $this->errors( $this->mapping( array( 'text' ) ) ) );
		$this->assertSame( array( '1::format' ), $this->errors( $this->mapping( array( $this->field_row(), array( 'a', 'b' ) ) ) ) );
	}

	/**
	 * The key of a row: 1 to 64 characters (letters, digits, `_`, `-`, `.`), not only digits.
	 *
	 * @dataProvider key_provider
	 *
	 * @param mixed  $key      Key.
	 * @param string $expected Expected error, empty for none.
	 */
	public function test_the_key_of_a_row( $key, string $expected ) {
		$this->assertSame( '' === $expected ? array() : array( '0:key:' . $expected ), $this->errors( $this->mapping( array( $this->field_row( array( 'key' => $key ) ) ) ) ) );
	}

	/**
	 * Data for test_the_key_of_a_row().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function key_provider(): array {
		return array(
			'letters'        => array( 'title', '' ),
			'with symbols'   => array( 'Event_date-2.start', '' ),
			'longest'        => array( str_repeat( 'a', 64 ), '' ),
			'digits and text' => array( '2024a', '' ),
			'empty'          => array( '', 'empty' ),
			'too long'       => array( str_repeat( 'a', 65 ), 'too_long' ),
			'space'          => array( 'my key', 'format' ),
			'accent'         => array( 'città', 'format' ),
			'slash'          => array( 'a/b', 'format' ),
			'markup'         => array( '<b>', 'format' ),
			'only digits'    => array( '2024', 'format' ),
			'integer'        => array( 5, 'format' ),
			'null'           => array( null, 'format' ),
			'list'           => array( array( 'a' ), 'format' ),
		);
	}

	/**
	 * A row without key is refused as required.
	 */
	public function test_a_row_without_key_is_refused() {
		$row = $this->field_row();
		unset( $row['key'] );

		$this->assertSame( array( '0:key:required' ), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The same key twice is refused on the second row; the check is exact.
	 */
	public function test_duplicate_keys_are_refused() {
		$mapping = $this->mapping(
			array(
				$this->field_row( array( 'key' => 'a' ) ),
				$this->fixed_row( array( 'key' => 'b' ) ),
				$this->field_row( array( 'key' => 'a' ) ),
				$this->field_row( array( 'key' => 'A' ) ),
			)
		);

		$this->assertSame( array( '2:key:duplicate' ), $this->errors( $mapping ) );
	}

	/**
	 * A kind that is not known is refused, the kinds of later phases included, and a missing one is required.
	 *
	 * @dataProvider unknown_kind_provider
	 *
	 * @param mixed $kind Kind.
	 */
	public function test_an_unknown_kind_is_refused( $kind ) {
		$this->assertSame( array( '0:kind:unknown' ), $this->errors( $this->mapping( array( $this->field_row( array( 'kind' => $kind ) ) ) ) ) );
	}

	/**
	 * Data for test_an_unknown_kind_is_refused().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function unknown_kind_provider(): array {
		return array(
			'expression' => array( 'expression' ),
			'wrong case' => array( 'Field' ),
			'empty'      => array( '' ),
			'null'       => array( null ),
			'number'     => array( 1 ),
		);
	}

	/**
	 * A row without kind is refused as required.
	 */
	public function test_a_row_without_kind_is_refused() {
		$row = $this->field_row();
		unset( $row['kind'] );

		$this->assertSame( array( '0:kind:required' ), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The output type is one of the eight known types.
	 */
	public function test_output_type() {
		foreach ( array( 'auto', 'string', 'html', 'number', 'boolean', 'datetime', 'url', 'list' ) as $type ) {
			$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row( array( 'type' => $type ) ) ) ) ), $type );
		}

		foreach ( array( 'date', 'String', '', null, 3 ) as $bad ) {
			$this->assertSame( array( '0:type:unknown' ), $this->errors( $this->mapping( array( $this->field_row( array( 'type' => $bad ) ) ) ) ), 'type ' . var_export( $bad, true ) );
		}
	}

	/**
	 * The type is optional: `auto` applies.
	 */
	public function test_type_is_optional() {
		$row = $this->field_row();
		unset( $row['type'] );

		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * A row has no missing-value policy: every key is always written (the mapping is a contract), so the old
	 * field `missing` is an unknown field, whatever its value.
	 */
	public function test_the_missing_value_policy_no_longer_exists() {
		foreach ( array( 'omit', 'null', '' ) as $policy ) {
			$this->assertSame( array( '0:missing:unknown_field' ), $this->errors( $this->mapping( array( $this->field_row( array( 'missing' => $policy ) ) ) ) ), $policy );
			$this->assertSame( array( '0:missing:unknown_field' ), $this->errors( $this->mapping( array( $this->fixed_row( array( 'missing' => $policy ) ) ) ) ), $policy );
		}
		$this->assertNull( Mapping_Validator::parse( '{"version":1,"rows":[{"key":"lang","kind":"fixed","missing":"omit","values":{"*":"it"}}]}' ) );
	}

	/**
	 * A row has only the fields of its kind.
	 */
	public function test_unknown_fields_are_refused() {
		$this->assertSame( array( '0:template:unknown_field' ), $this->errors( $this->mapping( array( $this->field_row( array( 'template' => '{title}' ) ) ) ) ) );
		$this->assertSame( array( '0:values:unknown_field' ), $this->errors( $this->mapping( array( $this->field_row( array( 'values' => array( '*' => 'x' ) ) ) ) ) ) );
		$this->assertSame( array( '0:sources:unknown_field' ), $this->errors( $this->mapping( array( $this->fixed_row( array( 'sources' => array( '*' => array( 'core.title' ) ) ) ) ) ) ) );
	}

	/**
	 * A `field` row needs sources: a map of post types (or `*`) to a chain of source identifiers.
	 */
	public function test_field_sources_are_required_and_well_formed() {
		$row = $this->field_row();
		unset( $row['sources'] );
		$this->assertSame( array( '0:sources:required' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$cases = array(
			'not an array'      => array( 'core.title', 'format' ),
			'no post types'     => array( array(), 'format' ),
			'a plain list'      => array( array( 'core.title' ), 'format' ),
			'bad post type'     => array( array( 'Post Type' => array( 'core.title' ) ), 'format' ),
			'post type too long' => array( array( str_repeat( 'a', 21 ) => array( 'core.title' ) ), 'format' ),
			'chain not a list'  => array( array( '*' => 'core.title' ), 'format' ),
			'chain keyed'       => array( array( '*' => array( 'a' => 'core.title' ) ), 'format' ),
			'empty chain'       => array( array( '*' => array() ), 'empty' ),
			'chain too long'    => array( array( '*' => array_fill( 0, 11, 'core.title' ) ), 'too_many' ),
			'not a string'      => array( array( '*' => array( 5 ) ), 'format' ),
			'no group'          => array( array( '*' => array( 'title' ) ), 'format' ),
			'empty name'        => array( array( '*' => array( 'core.' ) ), 'format' ),
			'space in name'     => array( array( '*' => array( 'meta.my key' ) ), 'format' ),
			'name too long'     => array( array( '*' => array( 'meta.' . str_repeat( 'a', 192 ) ) ), 'format' ),
			'unknown group'     => array( array( '*' => array( 'user.email' ) ), 'unknown' ),
			'upper case group'  => array( array( '*' => array( 'Core.title' ) ), 'format' ),
		);
		foreach ( $cases as $label => $case ) {
			$this->assertSame(
				array( '0:sources:' . $case[1] ),
				$this->errors( $this->mapping( array( $this->field_row( array( 'sources' => $case[0] ) ) ) ) ),
				$label
			);
		}
	}

	/**
	 * Every group of sources is accepted; the chain can have ten sources.
	 */
	public function test_every_source_group_is_accepted() {
		$chain = array( 'core.title', 'meta._x', 'taxonomy.category', 'image.featured', 'language.site', 'acf.f', 'cmb2.g:h', 'meta.a.b-c' );

		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row( array( 'sources' => array( '*' => $chain ) ) ) ) ) ) );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row( array( 'sources' => array( '*' => array_fill( 0, 10, 'core.title' ) ) ) ) ) ) ) );
	}

	/**
	 * A `fixed` row needs values: a map of post types (or `*`) to a text, a number or a boolean.
	 */
	public function test_fixed_values_are_required_and_well_formed() {
		$row = $this->fixed_row();
		unset( $row['values'] );
		$this->assertSame( array( '0:values:required' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$cases = array(
			'not an array'  => array( 'it', 'format' ),
			'no post types' => array( array(), 'format' ),
			'plain list'    => array( array( 'it' ), 'format' ),
			'bad post type' => array( array( 'Post Type' => 'it' ), 'format' ),
			'null value'    => array( array( '*' => null ), 'format' ),
			'list value'    => array( array( '*' => array( 'it' ) ), 'format' ),
			'text too long' => array( array( '*' => str_repeat( 'a', 1001 ) ), 'too_long' ),
		);
		foreach ( $cases as $label => $case ) {
			$this->assertSame(
				array( '0:values:' . $case[1] ),
				$this->errors( $this->mapping( array( $this->fixed_row( array( 'values' => $case[0] ) ) ) ) ),
				$label
			);
		}

		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row( array( 'values' => array( '*' => str_repeat( 'a', 1000 ) ) ) ) ) ) ) );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row( array( 'values' => array( '*' => '' ) ) ) ) ) ), 'An empty text is a value.' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row( array( 'values' => array( '*' => 0 ) ) ) ) ) ), 'Zero is a value.' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row( array( 'values' => array( '*' => false ) ) ) ) ) ), 'False is a value.' );
	}

	/**
	 * The options of a `field` row: a small map of plain flags and settings; a `fixed` row has none.
	 */
	public function test_options() {
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row( array( 'options' => array() ) ) ) ) ), 'An empty object is fine.' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->field_row( array( 'options' => array( 'shortcodes' => true, 'date_format' => 'Y-m-d', 'limit' => 3 ) ) ) ) ) ) );

		$cases = array(
			'not an array'   => array( 'x', 'format' ),
			'bad name'       => array( array( 'Bad Name' => true ), 'format' ),
			'list'           => array( array( true ), 'format' ),
			'nested'         => array( array( 'a' => array( 1 ) ), 'format' ),
			'null value'     => array( array( 'a' => null ), 'format' ),
			'text too long'  => array( array( 'a' => str_repeat( 'x', 101 ) ), 'too_long' ),
			'too many'       => array( array_combine( array_map( static fn( $i ) => 'o' . $i, range( 1, 11 ) ), array_fill( 0, 11, true ) ), 'too_many' ),
		);
		foreach ( $cases as $label => $case ) {
			$this->assertSame(
				array( '0:options:' . $case[1] ),
				$this->errors( $this->mapping( array( $this->field_row( array( 'options' => $case[0] ) ) ) ) ),
				$label
			);
		}

		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->fixed_row( array( 'options' => array() ) ) ) ) ), 'An empty object is fine on a fixed row too.' );
		$this->assertSame( array( '0:options:not_allowed' ), $this->errors( $this->mapping( array( $this->fixed_row( array( 'options' => array( 'a' => true ) ) ) ) ) ) );
	}

	/**
	 * Every problem of every row is reported, with the number of its row.
	 */
	public function test_all_the_errors_are_reported() {
		$mapping = $this->mapping(
			array(
				$this->field_row( array( 'key' => '', 'kind' => 'expression' ) ),
				$this->field_row( array( 'sources' => array( '*' => array( 'user.email', 'x' ) ), 'type' => 'date' ) ),
				$this->fixed_row(),
			)
		);

		$this->assertSame(
			array( '0:key:empty', '0:kind:unknown', '1:type:unknown', '1:sources:unknown', '1:sources:format' ),
			$this->errors( $mapping )
		);
	}

	/**
	 * Normalization fills the defaults, keeps a fixed order and drops empty options.
	 */
	public function test_normalize_gives_the_canonical_form() {
		$mapping = $this->mapping(
			array(
				array(
					'sources' => array( 'event' => array( 'meta.when' ), '*' => array( 'core.title' ) ),
					'kind'    => 'field',
					'key'     => 'when',
					'options' => array(),
				),
				array(
					'values' => array( '*' => 'it' ),
					'key'    => 'lang',
					'kind'   => 'fixed',
				),
			)
		);

		$this->assertSame(
			array(
				'version' => 1,
				'rows'    => array(
					array(
						'key'     => 'when',
						'kind'    => 'field',
						'type'    => 'auto',
						'sources' => array( 'event' => array( 'meta.when' ), '*' => array( 'core.title' ) ),
					),
					array(
						'key'     => 'lang',
						'kind'    => 'fixed',
						'type'    => 'auto',
						'values'  => array( '*' => 'it' ),
					),
				),
			),
			Mapping_Validator::normalize( $mapping )
		);
	}

	/**
	 * Normalizing a normalized mapping changes nothing, and options are kept in order.
	 */
	public function test_normalize_is_idempotent() {
		$mapping = $this->mapping(
			array(
				$this->field_row( array( 'options' => array( 'shortcodes' => true, 'raw' => false ) ) ),
				$this->fixed_row( array( 'values' => array( 'a' => 1, 'b' => 2.5, 'c' => true, 'd' => 'x' ) ) ),
			)
		);

		$once = Mapping_Validator::normalize( $mapping );

		$this->assertSame( $once, Mapping_Validator::normalize( $once ) );
		$this->assertSame( array( 'shortcodes' => true, 'raw' => false ), $once['rows'][0]['options'] );
	}

	/**
	 * Integer-looking post type keys of a decoded JSON object become integers in PHP: they are read as text.
	 */
	public function test_numeric_post_type_keys_are_read_as_text() {
		$decoded = Mapping::decode( '{"version":1,"rows":[{"key":"k","kind":"fixed","values":{"123":"x"}}]}' );

		$this->assertIsArray( $decoded );
		$this->assertSame( array(), $this->errors( $decoded ) );
		$this->assertSame( array( '123' => 'x' ), Mapping_Validator::normalize( $decoded )['rows'][0]['values'] );
	}

	/**
	 * parse() reads, checks and normalizes a JSON text in one call; anything not valid gives null.
	 */
	public function test_parse() {
		$this->assertSame(
			array(
				'version' => 1,
				'rows'    => array(
					array(
						'key'     => 'lang',
						'kind'    => 'fixed',
						'type'    => 'auto',
						'values'  => array( '*' => 'it' ),
					),
				),
			),
			Mapping_Validator::parse( '{"version":1,"rows":[{"kind":"fixed","key":"lang","values":{"*":"it"}}]}' )
		);
		$this->assertSame( Mapping::blank(), Mapping_Validator::parse( '{"version":1,"rows":[]}' ) );

		foreach ( array( '', '{bad', '{"version":2,"rows":[]}', '{"version":1,"rows":[{"key":"a","kind":"expression"}]}', null, 5 ) as $invalid ) {
			$this->assertNull( Mapping_Validator::parse( $invalid ), var_export( $invalid, true ) );
		}
	}

	/**
	 * A sensitive source needs an explicit confirmation in the same row: without it the mapping is
	 * refused, with it the row is valid.
	 */
	public function test_a_sensitive_source_needs_a_confirmation() {
		$row = $this->field_row( array( 'sources' => array( 'event' => array( 'meta.organizer_email', 'core.title' ) ) ) );
		$this->assertSame( array( '0:sources:sensitive' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$row['confirmed'] = array( 'meta.organizer_email' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * Every sensitive source needs its own confirmation: one confirmed source does not open the others.
	 */
	public function test_every_sensitive_source_needs_its_own_confirmation() {
		$row = $this->field_row(
			array(
				'sources'   => array(
					'*'     => array( 'meta.organizer_email' ),
					'event' => array( 'meta._ticket_api_key' ),
				),
				'confirmed' => array( 'meta.organizer_email' ),
			)
		);

		$this->assertSame( array( '0:sources:sensitive' ), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The confirmations are a list of sources of the same row.
	 */
	public function test_confirmations_must_name_sources_of_the_row() {
		$cases = array(
			'not a list'        => 'meta.organizer_email',
			'keyed'             => array( 'a' => 'meta.organizer_email' ),
			'not in the row'    => array( 'meta.other_email' ),
			'not a string'      => array( 5 ),
			'twice'             => array( 'meta.organizer_email', 'meta.organizer_email' ),
		);
		foreach ( $cases as $label => $confirmed ) {
			$row = $this->field_row(
				array(
					'sources'   => array( '*' => array( 'meta.organizer_email' ) ),
					'confirmed' => $confirmed,
				)
			);
			$this->assertContains( '0:confirmed:format', $this->errors( $this->mapping( array( $row ) ) ), $label );
		}

		$this->assertSame( array( '0:confirmed:unknown_field' ), $this->errors( $this->mapping( array( $this->fixed_row( array( 'confirmed' => array() ) ) ) ) ), 'A fixed row has no sources to confirm.' );
	}

	/**
	 * Confirming a source that is not sensitive is accepted and kept: it costs nothing.
	 */
	public function test_confirming_a_plain_source_is_accepted() {
		$row = $this->field_row( array( 'confirmed' => array( 'core.title' ) ) );

		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The confirmations are kept in the canonical form, after the sources; an empty list is left out.
	 */
	public function test_normalize_keeps_the_confirmations() {
		$row = $this->field_row(
			array(
				'options'   => array( 'raw' => true ),
				'confirmed' => array( 'meta.organizer_email' ),
				'sources'   => array( '*' => array( 'meta.organizer_email' ) ),
			)
		);

		$normalized = Mapping_Validator::normalize( $this->mapping( array( $row ) ) )['rows'][0];

		$this->assertSame( array( 'key', 'kind', 'type', 'sources', 'confirmed', 'options' ), array_keys( $normalized ) );
		$this->assertSame( array( 'meta.organizer_email' ), $normalized['confirmed'] );

		$row['confirmed'] = array();
		$this->assertArrayNotHasKey( 'confirmed', Mapping_Validator::normalize( $this->mapping( array( $row ) ) )['rows'][0] );
	}

	/**
	 * The author archive URL needs a confirmation like a sensitive source.
	 */
	public function test_the_author_url_needs_a_confirmation() {
		$row = $this->field_row( array( 'sources' => array( '*' => array( 'core.author.url' ) ) ) );
		$this->assertSame( array( '0:sources:sensitive' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$row['confirmed'] = array( 'core.author.url' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * A valid `template` row.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function template_row( array $override = array() ): array {
		return array_merge(
			array(
				'key'       => 'subtitle',
				'kind'      => 'template',
				'type'      => 'string',
				'templates' => array(
					'*'     => '{core.title}',
					'event' => '{core.title} – {meta.place|"n.d."}',
				),
			),
			$override
		);
	}

	/**
	 * A template row is valid with text or HTML output, or `auto`; other types are not allowed.
	 */
	public function test_a_template_row_and_its_output_types() {
		foreach ( array( 'string', 'html', 'auto' ) as $type ) {
			$this->assertSame( array(), $this->errors( $this->mapping( array( $this->template_row( array( 'type' => $type ) ) ) ) ), $type );
		}
		foreach ( array( 'number', 'boolean', 'datetime', 'url', 'list' ) as $type ) {
			$this->assertSame( array( '0:type:not_allowed' ), $this->errors( $this->mapping( array( $this->template_row( array( 'type' => $type ) ) ) ) ), $type );
		}
	}

	/**
	 * The templates are required, a non-empty map of post types to valid texts, and their sources have known groups.
	 */
	public function test_templates_are_required_and_well_formed() {
		$row = $this->template_row();
		unset( $row['templates'] );
		$this->assertSame( array( '0:templates:required' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$cases = array(
			'empty map'        => array( array(), 'empty' ),
			'list'             => array( array( '{core.title}' ), 'format' ),
			'not a text'       => array( array( '*' => 5 ), 'format' ),
			'empty text'       => array( array( '*' => '' ), 'empty' ),
			'syntax'           => array( array( '*' => '{core.title' ), 'format' ),
			'too long'         => array( array( '*' => str_repeat( 'a', 1001 ) ), 'too_long' ),
			'too many'         => array( array( '*' => str_repeat( '{core.id}', 21 ) ), 'too_many' ),
			'unknown group'    => array( array( '*' => '{other.title}' ), 'unknown' ),
			'bad post type'    => array( array( 'Post' => '{core.title}' ), 'format' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( array( '0:templates:' . $case[1] ), $this->errors( $this->mapping( array( $this->template_row( array( 'templates' => $case[0] ) ) ) ) ), $name );
		}
	}

	/**
	 * A template row has no options: it is an unknown field.
	 */
	public function test_a_template_row_has_no_options() {
		$this->assertSame( array( '0:options:unknown_field' ), $this->errors( $this->mapping( array( $this->template_row( array( 'options' => array( 'raw' => true ) ) ) ) ) ) );
	}

	/**
	 * A sensitive source in a placeholder needs its confirmation in the row, like in a field row.
	 */
	public function test_a_sensitive_source_in_a_template_needs_a_confirmation() {
		$row = $this->template_row( array( 'templates' => array( '*' => 'Contact: {meta.organizer_email|"none"}' ) ) );
		$this->assertSame( array( '0:templates:sensitive' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$row['confirmed'] = array( 'meta.organizer_email' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );

		$row['confirmed'] = array( 'meta.other' );
		$this->assertContains( '0:confirmed:format', $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The canonical form of a template row: common fields, templates as texts, confirmations when present.
	 */
	public function test_normalize_a_template_row() {
		$row = $this->template_row( array( 'confirmed' => array( 'core.author.url' ), 'templates' => array( '*' => '{core.author.url}' ) ) );
		unset( $row['type'] );

		$this->assertSame(
			array(
				'key'       => 'subtitle',
				'kind'      => 'template',
				'type'      => 'auto',
				'templates' => array( '*' => '{core.author.url}' ),
				'confirmed' => array( 'core.author.url' ),
			),
			Mapping_Validator::normalize( $this->mapping( array( $row ) ) )['rows'][0]
		);
	}

	/**
	 * A valid `lookup` row.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function lookup_row( array $override = array() ): array {
		return array_merge(
			array(
				'key'    => 'label',
				'kind'   => 'lookup',
				'type'   => 'string',
				'inputs' => array(
					'*'     => 'core.type',
					'event' => 'meta.status',
				),
				'cases'  => array(
					array( 'match' => 'post', 'output' => array( 'fixed' => 'News' ) ),
					array( 'match' => 'event', 'output' => array( 'source' => 'meta.category' ) ),
				),
			),
			$override
		);
	}

	/**
	 * A lookup row is valid with any output type and with every kind of default.
	 */
	public function test_a_lookup_row_and_its_defaults() {
		foreach ( Mapping::TYPES as $type ) {
			$this->assertSame( array(), $this->errors( $this->mapping( array( $this->lookup_row( array( 'type' => $type ) ) ) ) ), $type );
		}

		$defaults = array(
			array( 'fixed' => 'Other' ),
			array( 'fixed' => 0 ),
			array( 'fixed' => false ),
			array( 'source' => 'core.title' ),
			array( 'input' => true ),
		);
		foreach ( $defaults as $default ) {
			$this->assertSame( array(), $this->errors( $this->mapping( array( $this->lookup_row( array( 'default' => $default ) ) ) ) ), (string) json_encode( $default ) );
		}
	}

	/**
	 * The inputs are required, a non-empty map of post types to one source identifier each.
	 */
	public function test_lookup_inputs_are_required_and_well_formed() {
		$row = $this->lookup_row();
		unset( $row['inputs'] );
		$this->assertSame( array( '0:inputs:required' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$cases = array(
			'empty map'     => array( array(), 'empty' ),
			'list'          => array( array( 'core.type' ), 'format' ),
			'chain'         => array( array( '*' => array( 'core.type' ) ), 'format' ),
			'bad source'    => array( array( '*' => 'type' ), 'format' ),
			'unknown group' => array( array( '*' => 'other.type' ), 'unknown' ),
			'bad post type' => array( array( 'Post' => 'core.type' ), 'format' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( array( '0:inputs:' . $case[1] ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'inputs' => $case[0] ) ) ) ) ), $name );
		}
	}

	/**
	 * The cases are required: a non-empty list of `match` + `output`, with distinct texts to match and a fixed
	 * text or a source as output.
	 */
	public function test_lookup_cases_are_required_and_well_formed() {
		$row = $this->lookup_row();
		unset( $row['cases'] );
		$this->assertSame( array( '0:cases:required' ), $this->errors( $this->mapping( array( $row ) ) ) );

		$fixed = array( 'fixed' => 'x' );
		$cases = array(
			'empty list'         => array( array(), 'empty' ),
			'map'                => array( array( 'a' => array( 'match' => 'a', 'output' => $fixed ) ), 'format' ),
			'not an object'      => array( array( 'a' ), 'format' ),
			'missing output'     => array( array( array( 'match' => 'a' ) ), 'format' ),
			'other field'        => array( array( array( 'match' => 'a', 'output' => $fixed, 'note' => 'n' ) ), 'format' ),
			'match not a text'   => array( array( array( 'match' => 1, 'output' => $fixed ) ), 'format' ),
			'empty match'        => array( array( array( 'match' => '', 'output' => $fixed ) ), 'empty' ),
			'spaces at the ends' => array( array( array( 'match' => ' a', 'output' => $fixed ) ), 'format' ),
			'match too long'     => array( array( array( 'match' => str_repeat( 'a', 1001 ), 'output' => $fixed ) ), 'too_long' ),
			'duplicate match'    => array( array( array( 'match' => 'a', 'output' => $fixed ), array( 'match' => 'a', 'output' => $fixed ) ), 'duplicate' ),
			'empty output'       => array( array( array( 'match' => 'a', 'output' => array() ) ), 'format' ),
			'two outputs'        => array( array( array( 'match' => 'a', 'output' => array( 'fixed' => 'x', 'source' => 'core.title' ) ) ), 'format' ),
			'input as output'    => array( array( array( 'match' => 'a', 'output' => array( 'input' => true ) ) ), 'format' ),
			'fixed not scalar'   => array( array( array( 'match' => 'a', 'output' => array( 'fixed' => array( 'x' ) ) ) ), 'format' ),
			'fixed too long'     => array( array( array( 'match' => 'a', 'output' => array( 'fixed' => str_repeat( 'a', 1001 ) ) ) ), 'too_long' ),
			'bad source'         => array( array( array( 'match' => 'a', 'output' => array( 'source' => 'title' ) ) ), 'format' ),
			'unknown group'      => array( array( array( 'match' => 'a', 'output' => array( 'source' => 'other.title' ) ) ), 'unknown' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( array( '0:cases:' . $case[1] ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'cases' => $case[0] ) ) ) ) ), $name );
		}

		$many = array();
		for ( $i = 0; $i <= Mapping_Validator::MAX_CASES; $i++ ) {
			$many[] = array( 'match' => 'v' . $i, 'output' => $fixed );
		}
		$this->assertSame( array( '0:cases:too_many' ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'cases' => $many ) ) ) ) ) );
		array_pop( $many );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $this->lookup_row( array( 'cases' => $many ) ) ) ) ) );
	}

	/**
	 * The default is optional (no default gives null); when present it is a fixed text, a source or the input.
	 */
	public function test_lookup_default_is_well_formed() {
		$cases = array(
			'not an object' => array( 'x', 'format' ),
			'empty'         => array( array(), 'format' ),
			'input false'   => array( array( 'input' => false ), 'format' ),
			'null'          => array( array( 'null' => true ), 'format' ),
			'bad source'    => array( array( 'source' => 'title' ), 'format' ),
		);
		foreach ( $cases as $name => $case ) {
			$this->assertSame( array( '0:default:' . $case[1] ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'default' => $case[0] ) ) ) ) ), $name );
		}
	}

	/**
	 * A lookup row has no options and no field of the other rules.
	 */
	public function test_a_lookup_row_has_no_options() {
		$this->assertSame( array( '0:options:unknown_field' ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'options' => array( 'raw' => true ) ) ) ) ) ) );
		$this->assertSame( array( '0:sources:unknown_field' ), $this->errors( $this->mapping( array( $this->lookup_row( array( 'sources' => array( '*' => array( 'core.title' ) ) ) ) ) ) ) );
	}

	/**
	 * A sensitive source needs its confirmation wherever it is used: input, output of a case, default.
	 */
	public function test_a_sensitive_source_in_a_lookup_needs_a_confirmation() {
		$input = $this->lookup_row( array( 'inputs' => array( '*' => 'meta.user_email' ) ) );
		$this->assertSame( array( '0:inputs:sensitive' ), $this->errors( $this->mapping( array( $input ) ) ) );
		$input['confirmed'] = array( 'meta.user_email' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $input ) ) ) );

		$output = $this->lookup_row( array( 'cases' => array( array( 'match' => 'a', 'output' => array( 'source' => 'meta.api_key' ) ) ) ) );
		$this->assertSame( array( '0:cases:sensitive' ), $this->errors( $this->mapping( array( $output ) ) ) );

		$default = $this->lookup_row( array( 'default' => array( 'source' => 'core.author.url' ) ) );
		$this->assertSame( array( '0:default:sensitive' ), $this->errors( $this->mapping( array( $default ) ) ) );
		$default['confirmed'] = array( 'core.author.url' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $default ) ) ) );

		$default['confirmed'] = array( 'meta.other' );
		$this->assertContains( '0:confirmed:format', $this->errors( $this->mapping( array( $default ) ) ) );
	}

	/**
	 * The canonical form of a lookup row: common fields, inputs, cases, then default and confirmations when present.
	 */
	public function test_normalize_a_lookup_row() {
		$row = $this->lookup_row(
			array(
				'confirmed' => array( 'core.author.url' ),
				'default'   => array( 'source' => 'core.author.url' ),
				'inputs'    => array( '*' => 'core.type' ),
				'cases'     => array( array( 'output' => array( 'fixed' => 'News' ), 'match' => 'post' ) ),
			)
		);
		unset( $row['type'] );

		$this->assertSame(
			array(
				'key'       => 'label',
				'kind'      => 'lookup',
				'type'      => 'auto',
				'inputs'    => array( '*' => 'core.type' ),
				'cases'     => array( array( 'match' => 'post', 'output' => array( 'fixed' => 'News' ) ) ),
				'default'   => array( 'source' => 'core.author.url' ),
				'confirmed' => array( 'core.author.url' ),
			),
			Mapping_Validator::normalize( $this->mapping( array( $row ) ) )['rows'][0]
		);

		$plain = $this->lookup_row();
		$this->assertArrayNotHasKey( 'default', Mapping_Validator::normalize( $this->mapping( array( $plain ) ) )['rows'][0], 'No default: null, nothing written.' );
	}

	/**
	 * A valid `condition` row.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function condition_row( array $override = array() ): array {
		return array_merge(
			array(
				'key'      => 'cancelled',
				'kind'     => 'condition',
				'type'     => 'boolean',
				'inputs'   => array(
					'*'     => 'meta.status',
					'event' => 'meta.event_status',
				),
				'operator' => 'equals',
				'matches'  => array( 'cancelled' ),
				'then'     => array( 'fixed' => true ),
				'else'     => array( 'fixed' => false ),
			),
			$override
		);
	}

	/**
	 * A condition row is valid with every operator and its texts, and with every kind of output, or none.
	 */
	public function test_a_condition_row_and_its_operators() {
		$valid = array(
			'empty'      => null,
			'not_empty'  => null,
			'equals'     => array( 'a' ),
			'not_equals' => array( 'a' ),
			'contains'   => array( 'a' ),
			'one_of'     => array( 'a', 'b, c', 'd' ),
		);
		foreach ( $valid as $operator => $matches ) {
			$row = $this->condition_row( array( 'operator' => $operator, 'matches' => $matches ) );
			if ( null === $matches ) {
				unset( $row['matches'] );
			}
			$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ), $operator );
		}

		foreach ( array( array( 'fixed' => 'x' ), array( 'source' => 'core.title' ), array( 'input' => true ) ) as $output ) {
			$this->assertSame( array(), $this->errors( $this->mapping( array( $this->condition_row( array( 'then' => $output, 'else' => $output ) ) ) ) ), (string) json_encode( $output ) );
		}

		$row = $this->condition_row();
		unset( $row['then'], $row['else'] );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ), 'No output: null.' );
	}

	/**
	 * The inputs follow the same rules as the inputs of a lookup row.
	 */
	public function test_condition_inputs_are_required_and_well_formed() {
		$row = $this->condition_row();
		unset( $row['inputs'] );
		$this->assertSame( array( '0:inputs:required' ), $this->errors( $this->mapping( array( $row ) ) ) );
		$this->assertSame( array( '0:inputs:empty' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'inputs' => array() ) ) ) ) ) );
		$this->assertSame( array( '0:inputs:unknown' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'inputs' => array( '*' => 'other.x' ) ) ) ) ) ) );
	}

	/**
	 * The operator is required and known; the texts to compare fit the operator.
	 */
	public function test_condition_operator_and_matches() {
		$row = $this->condition_row();
		unset( $row['operator'] );
		$this->assertSame( array( '0:operator:required' ), $this->errors( $this->mapping( array( $row ) ) ) );
		$this->assertSame( array( '0:operator:unknown' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'operator' => 'starts_with' ) ) ) ) ) );

		$cases = array(
			'texts for empty'         => array( 'empty', array( 'a' ), 'not_allowed' ),
			'no text for equals'      => array( 'equals', null, 'required' ),
			'two texts for equals'    => array( 'equals', array( 'a', 'b' ), 'too_many' ),
			'two texts for contains'  => array( 'contains', array( 'a', 'b' ), 'too_many' ),
			'no texts for one of'     => array( 'one_of', array(), 'empty' ),
			'not a list'              => array( 'one_of', 'a', 'format' ),
			'map'                     => array( 'one_of', array( 'x' => 'a' ), 'format' ),
			'not a text'              => array( 'equals', array( 1 ), 'format' ),
			'empty text'              => array( 'equals', array( '' ), 'empty' ),
			'spaces at the ends'      => array( 'equals', array( 'a ' ), 'format' ),
			'too long'                => array( 'equals', array( str_repeat( 'a', 1001 ) ), 'too_long' ),
			'duplicate'               => array( 'one_of', array( 'a', 'a' ), 'duplicate' ),
		);
		foreach ( $cases as $name => $case ) {
			$row = $this->condition_row( array( 'operator' => $case[0], 'matches' => $case[1] ) );
			if ( null === $case[1] ) {
				unset( $row['matches'] );
			}
			$this->assertSame( array( '0:matches:' . $case[2] ), $this->errors( $this->mapping( array( $row ) ) ), $name );
		}

		$many = array();
		for ( $i = 0; $i <= Mapping_Validator::MAX_CASES; $i++ ) {
			$many[] = 'v' . $i;
		}
		$this->assertSame( array( '0:matches:too_many' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'operator' => 'one_of', 'matches' => $many ) ) ) ) ) );
	}

	/**
	 * The outputs are a fixed value, a source or the input.
	 */
	public function test_condition_outputs_are_well_formed() {
		foreach ( array( 'then', 'else' ) as $field ) {
			foreach ( array( 'x', array(), array( 'input' => false ), array( 'source' => 'title' ), array( 'fixed' => 'a', 'input' => true ) ) as $output ) {
				$this->assertSame( array( '0:' . $field . ':format' ), $this->errors( $this->mapping( array( $this->condition_row( array( $field => $output ) ) ) ) ), $field . ' ' . json_encode( $output ) );
			}
		}
		$this->assertSame( array( '0:options:unknown_field' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'options' => array( 'raw' => true ) ) ) ) ) ) );
	}

	/**
	 * A sensitive source needs its confirmation: as input, or as output.
	 */
	public function test_a_sensitive_source_in_a_condition_needs_a_confirmation() {
		$this->assertSame( array( '0:inputs:sensitive' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'inputs' => array( '*' => 'meta.user_email' ) ) ) ) ) ) );
		$this->assertSame( array( '0:then:sensitive' ), $this->errors( $this->mapping( array( $this->condition_row( array( 'then' => array( 'source' => 'meta.api_key' ) ) ) ) ) ) );

		$row = $this->condition_row( array( 'else' => array( 'source' => 'core.author.url' ) ) );
		$this->assertSame( array( '0:else:sensitive' ), $this->errors( $this->mapping( array( $row ) ) ) );
		$row['confirmed'] = array( 'core.author.url' );
		$this->assertSame( array(), $this->errors( $this->mapping( array( $row ) ) ) );
	}

	/**
	 * The canonical form of a condition row: common fields, inputs, operator, then texts, outputs and
	 * confirmations when present.
	 */
	public function test_normalize_a_condition_row() {
		$row = $this->condition_row(
			array(
				'else'      => array( 'source' => 'core.author.url' ),
				'confirmed' => array( 'core.author.url' ),
			)
		);
		unset( $row['type'], $row['then'] );

		$this->assertSame(
			array(
				'key'       => 'cancelled',
				'kind'      => 'condition',
				'type'      => 'auto',
				'inputs'    => array( '*' => 'meta.status', 'event' => 'meta.event_status' ),
				'operator'  => 'equals',
				'matches'   => array( 'cancelled' ),
				'else'      => array( 'source' => 'core.author.url' ),
				'confirmed' => array( 'core.author.url' ),
			),
			Mapping_Validator::normalize( $this->mapping( array( array_reverse( $row, true ) ) ) )['rows'][0]
		);
	}
}

<?php
/**
 * Unit tests of the evaluation of the mapping rules (pure logic, no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Rule_Evaluator;

/**
 * The value of every row, from the sources read through a function given by the caller.
 */
class KKCE_Rule_Evaluator_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * A reading function over fixed values: a source missing from the list does not exist (null).
	 *
	 * @param array<string, mixed> $values Source => value.
	 * @param list<string>         $asked  Sources read, in order, filled by the function.
	 * @return callable(string): mixed
	 */
	private function reader( array $values, array &$asked = array() ): callable {
		return static function ( string $source ) use ( $values, &$asked ) {
			$asked[] = $source;
			return $values[ $source ] ?? null;
		};
	}

	/**
	 * A `field` row with one chain for every post type.
	 *
	 * @param list<string>         $chain    Sources.
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function field( array $chain, array $override = array() ): array {
		return array_merge(
			array(
				'key'     => 'k',
				'kind'    => 'field',
				'type'    => 'auto',
				'sources' => array( '*' => $chain ),
			),
			$override
		);
	}

	/**
	 * The first source with a value wins.
	 */
	public function test_the_first_present_value_wins() {
		$asked  = array();
		$result = Rule_Evaluator::evaluate(
			$this->field( array( 'meta.a', 'meta.b', 'meta.c' ) ),
			'post',
			$this->reader( array( 'meta.b' => 'B', 'meta.c' => 'C' ), $asked )
		);

		$this->assertSame( array( 'present' => true, 'value' => 'B' ), $result );
		$this->assertSame( array( 'meta.a', 'meta.b' ), $asked, 'The sources after the winner are not read.' );
	}

	/**
	 * `0`, `"0"` and `false` are values: they win and stop the chain.
	 *
	 * @dataProvider falsy_value_provider
	 *
	 * @param mixed $value Value of the first source.
	 */
	public function test_zero_and_false_are_values( $value ) {
		$result = Rule_Evaluator::evaluate( $this->field( array( 'meta.a', 'meta.b' ) ), 'post', $this->reader( array( 'meta.a' => $value, 'meta.b' => 'B' ) ) );

		$this->assertSame( array( 'present' => true, 'value' => $value ), $result );
	}

	/**
	 * Data for test_zero_and_false_are_values().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function falsy_value_provider(): array {
		return array(
			'zero'        => array( 0 ),
			'zero string' => array( '0' ),
			'false'       => array( false ),
			'zero float'  => array( 0.0 ),
		);
	}

	/**
	 * An empty value (empty string, only spaces, empty list) passes to the next source.
	 *
	 * @dataProvider empty_value_provider
	 *
	 * @param mixed $value Value of the first source.
	 */
	public function test_empty_values_pass_to_the_next_source( $value ) {
		$result = Rule_Evaluator::evaluate( $this->field( array( 'meta.a', 'meta.b' ) ), 'post', $this->reader( array( 'meta.a' => $value, 'meta.b' => 'B' ) ) );

		$this->assertSame( array( 'present' => true, 'value' => 'B' ), $result );
	}

	/**
	 * Data for test_empty_values_pass_to_the_next_source().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function empty_value_provider(): array {
		return array(
			'empty string' => array( '' ),
			'spaces'       => array( "  \t\n " ),
			'empty list'   => array( array() ),
		);
	}

	/**
	 * When every source exists but is empty, the first empty value comes out (spaces become an empty string).
	 */
	public function test_all_empty_sources_give_the_empty_value() {
		$reader = $this->reader( array( 'meta.a' => '   ', 'meta.b' => array() ) );
		$this->assertSame( array( 'present' => true, 'value' => '' ), Rule_Evaluator::evaluate( $this->field( array( 'meta.a', 'meta.b' ) ), 'post', $reader ) );

		$reader = $this->reader( array( 'meta.b' => array() ) );
		$this->assertSame( array( 'present' => true, 'value' => array() ), Rule_Evaluator::evaluate( $this->field( array( 'meta.a', 'meta.b' ) ), 'post', $reader ) );
	}

	/**
	 * When no source exists, the row has no value.
	 */
	public function test_no_existing_source_gives_no_value() {
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->field( array( 'meta.a', 'meta.b' ) ), 'post', $this->reader( array() ) ) );
	}

	/**
	 * The chain of the post type of the item replaces the general one; the general one serves the other
	 * post types; without either the row has no value.
	 */
	public function test_the_chain_of_the_post_type_replaces_the_general_one() {
		$row    = $this->field(
			array(),
			array(
				'sources' => array(
					'*'     => array( 'core.title' ),
					'event' => array( 'meta.name' ),
				),
			)
		);
		$reader = $this->reader( array( 'core.title' => 'Title', 'meta.name' => '' ) );

		$this->assertSame( array( 'present' => true, 'value' => '' ), Rule_Evaluator::evaluate( $row, 'event', $reader ), 'No fallback to the general chain.' );
		$this->assertSame( array( 'present' => true, 'value' => 'Title' ), Rule_Evaluator::evaluate( $row, 'post', $reader ) );

		$only_event = $this->field( array(), array( 'sources' => array( 'event' => array( 'meta.name' ) ) ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $only_event, 'post', $reader ) );
	}

	/**
	 * A fixed value, also per post type; the value of the post type replaces the general one.
	 */
	public function test_fixed_values() {
		$row = array(
			'key'     => 'lang',
			'kind'    => 'fixed',
			'type'    => 'auto',
			'values'  => array(
				'*'     => 'it',
				'event' => 'en',
			),
		);
		$never = static function () {
			throw new LogicException( 'A fixed value reads no source.' );
		};

		$this->assertSame( array( 'present' => true, 'value' => 'it' ), Rule_Evaluator::evaluate( $row, 'post', $never ) );
		$this->assertSame( array( 'present' => true, 'value' => 'en' ), Rule_Evaluator::evaluate( $row, 'event', $never ) );

		$row['values'] = array( 'event' => 0 );
		$this->assertSame( array( 'present' => true, 'value' => 0 ), Rule_Evaluator::evaluate( $row, 'event', $never ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $row, 'post', $never ) );

		$row['values'] = array( '*' => '' );
		$this->assertSame( array( 'present' => true, 'value' => '' ), Rule_Evaluator::evaluate( $row, 'post', $never ), 'An empty fixed text is a value.' );
	}

	/**
	 * A sensitive source that the row does not confirm is never read, even if the mapping was not
	 * validated (an imported or damaged one); a confirmed one is read.
	 */
	public function test_unconfirmed_sensitive_sources_are_never_read() {
		$asked  = array();
		$reader = $this->reader( array( 'meta.api_key' => 'SECRET', 'core.author.url' => 'https://x/author/jdoe/', 'core.title' => 'T' ), $asked );

		$result = Rule_Evaluator::evaluate( $this->field( array( 'meta.api_key', 'core.author.url', 'core.title' ) ), 'post', $reader );
		$this->assertSame( array( 'present' => true, 'value' => 'T' ), $result );
		$this->assertSame( array( 'core.title' ), $asked );

		$confirmed = $this->field( array( 'meta.api_key', 'core.title' ), array( 'confirmed' => array( 'meta.api_key' ) ) );
		$this->assertSame( array( 'present' => true, 'value' => 'SECRET' ), Rule_Evaluator::evaluate( $confirmed, 'post', $reader ) );
	}

	/**
	 * A row of an unknown kind, or with a damaged shape, has no value and reads nothing.
	 */
	public function test_unknown_or_damaged_rows_have_no_value() {
		$asked  = array();
		$reader = $this->reader( array( 'core.title' => 'T' ), $asked );

		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( array( 'kind' => 'expression', 'sources' => array( '*' => array( 'core.title' ) ) ), 'post', $reader ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( array( 'kind' => 'field', 'sources' => 'core.title' ), 'post', $reader ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( array( 'kind' => 'field', 'sources' => array( '*' => array( 5 ) ) ), 'post', $reader ) );
		$this->assertSame( array(), $asked );
	}

	/**
	 * A whole mapping gives an object with every key of the mapping, in the order of the rows: the mapping is
	 * a contract, so a row without a value writes `null` instead of disappearing.
	 */
	public function test_evaluate_mapping() {
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				$this->field( array( 'core.title' ), array( 'key' => 'title' ) ),
				$this->field( array( 'meta.none' ), array( 'key' => 'nothing' ) ),
				array( 'key' => 'lang', 'kind' => 'fixed', 'type' => 'auto', 'values' => array( '*' => 'it' ) ),
				$this->field( array( 'meta.zero' ), array( 'key' => 'zero' ) ),
				array( 'key' => 'only_events', 'kind' => 'fixed', 'type' => 'auto', 'values' => array( 'event' => 'x' ) ),
			),
		);

		$this->assertSame(
			array(
				'title'       => 'T',
				'nothing'     => null,
				'lang'        => 'it',
				'zero'        => 0,
				'only_events' => null,
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $this->reader( array( 'core.title' => 'T', 'meta.zero' => 0 ) ), $this->declared( array( 'core.title' => 'string', 'meta.zero' => 'number' ) ) )
		);
	}

	/**
	 * Rows that cannot give a value still write their key with `null`: an unknown kind, a damaged shape, an
	 * unconfirmed sensitive source.
	 */
	public function test_rows_without_a_value_write_null() {
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				array( 'key' => 'future', 'kind' => 'expression', 'sources' => array( '*' => array( 'core.title' ) ) ),
				array( 'key' => 'damaged', 'kind' => 'field', 'sources' => 'core.title' ),
				$this->field( array( 'meta.api_key' ), array( 'key' => 'secret' ) ),
			),
		);

		$this->assertSame(
			array(
				'future'  => null,
				'damaged' => null,
				'secret'  => null,
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $this->reader( array( 'core.title' => 'T', 'meta.api_key' => 'SECRET' ) ) )
		);
	}

	/**
	 * A mapping without rows, or a damaged one, gives an empty object.
	 */
	public function test_evaluate_mapping_without_rows() {
		$reader = $this->reader( array() );

		$this->assertSame( array(), Rule_Evaluator::evaluate_mapping( array( 'version' => 1, 'rows' => array() ), 'post', $reader ) );
		$this->assertSame( array(), Rule_Evaluator::evaluate_mapping( array( 'version' => 1 ), 'post', $reader ) );
		$this->assertSame( array(), Rule_Evaluator::evaluate_mapping( array( 'version' => 1, 'rows' => array( 'x', array( 'kind' => 'fixed' ) ) ), 'post', $reader ) );
	}

	/**
	 * A function that gives the declared type of a source.
	 *
	 * @param array<string, string> $types Source => declared type.
	 * @return callable(string): ?string
	 */
	private function declared( array $types ): callable {
		return static fn( string $source ): ?string => $types[ $source ] ?? null;
	}

	/**
	 * Values are normalized to the type of the row: `auto` takes the common declared type of every source
	 * of the row (all the chains), otherwise text; an explicit type wins.
	 */
	public function test_values_are_normalized_to_the_type_of_the_row() {
		$reader   = $this->reader( array( 'core.date' => '2026-07-01 08:30:00', 'core.title' => 'A &amp; B', 'core.id' => 7 ) );
		$declared = $this->declared( array( 'core.date' => 'datetime', 'core.title' => 'string', 'core.id' => 'number' ) );
		$mapping  = array(
			'version' => 1,
			'rows'    => array(
				$this->field( array( 'core.date' ), array( 'key' => 'date' ) ),
				$this->field( array( 'core.title' ), array( 'key' => 'title' ) ),
				$this->field( array(), array( 'key' => 'mixed', 'sources' => array( '*' => array( 'core.id' ), 'event' => array( 'core.date' ) ) ) ),
				$this->field( array( 'core.id' ), array( 'key' => 'id_text', 'type' => 'string' ) ),
				$this->field( array( 'meta.unknown', 'core.id' ), array( 'key' => 'unknown_source' ) ),
			),
		);

		$this->assertSame(
			array(
				'date'           => '2026-07-01T08:30:00Z',
				'title'          => 'A & B',
				'mixed'          => '7',
				'id_text'        => '7',
				'unknown_source' => '7',
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $reader, $declared )
		);
	}

	/**
	 * A value that cannot be converted, and an empty value of a type without an empty form, give `null`; an
	 * empty value keeps its empty form for text, HTML and lists ("there, but empty").
	 */
	public function test_a_value_that_cannot_be_converted_gives_null() {
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				$this->field( array( 'meta.price' ), array( 'key' => 'price', 'type' => 'number' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_number', 'type' => 'number' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_date', 'type' => 'datetime' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_url', 'type' => 'url' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_boolean', 'type' => 'boolean' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_text', 'type' => 'string' ) ),
				$this->field( array( 'meta.empty' ), array( 'key' => 'empty_html', 'type' => 'html' ) ),
				$this->field( array( 'meta.empty_list' ), array( 'key' => 'empty_list', 'type' => 'list' ) ),
			),
		);

		$this->assertSame(
			array(
				'price'         => null,
				'empty_number'  => null,
				'empty_date'    => null,
				'empty_url'     => null,
				'empty_boolean' => null,
				'empty_text'    => '',
				'empty_html'    => '',
				'empty_list'    => array(),
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $this->reader( array( 'meta.price' => '15,50', 'meta.empty' => '', 'meta.empty_list' => array() ) ) )
		);
	}

	/**
	 * A fixed value with `auto` keeps its JSON type; with an explicit type it is converted.
	 */
	public function test_fixed_values_are_normalized() {
		$row = static fn( string $key, $value, string $type ): array => array(
			'key'     => $key,
			'kind'    => 'fixed',
			'type'    => $type,
			'values'  => array( '*' => $value ),
		);
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				$row( 'a', 0, 'auto' ),
				$row( 'b', false, 'auto' ),
				$row( 'c', 'it', 'auto' ),
				$row( 'd', '1', 'boolean' ),
				$row( 'e', 'x', 'number' ),
			),
		);

		$this->assertSame(
			array(
				'a' => 0,
				'b' => false,
				'c' => 'it',
				'd' => true,
				'e' => null,
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $this->reader( array() ) )
		);
	}

	/**
	 * Root-relative URLs are made absolute with the origin given by the caller.
	 */
	public function test_urls_use_the_origin_of_the_site() {
		$mapping = array(
			'version' => 1,
			'rows'    => array( $this->field( array( 'meta.image' ), array( 'key' => 'image', 'type' => 'url' ) ) ),
		);

		$this->assertSame(
			array( 'image' => 'https://example.org/a.jpg' ),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $this->reader( array( 'meta.image' => '/a.jpg' ) ), null, 'https://example.org' )
		);
	}

	/**
	 * A `template` row with one template for every post type.
	 *
	 * @param string               $template Template.
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function template( string $template, array $override = array() ): array {
		return array_merge(
			array(
				'key'       => 'k',
				'kind'      => 'template',
				'type'      => 'string',
				'templates' => array( '*' => $template ),
			),
			$override
		);
	}

	/**
	 * A template joins text and the values of its placeholders; doubled braces are literal braces.
	 */
	public function test_a_template_joins_text_and_values() {
		$reader = $this->reader( array( 'core.title' => 'Concert', 'meta.place' => 'Pisa' ) );

		$this->assertSame(
			array( 'present' => true, 'value' => 'Concert – Pisa {live}' ),
			Rule_Evaluator::evaluate( $this->template( '{core.title} – {meta.place} {{live}}' ), 'post', $reader )
		);
	}

	/**
	 * A placeholder uses its first alternative with a value: empty values pass on, a literal always has one.
	 */
	public function test_placeholder_fallbacks() {
		$reader = $this->reader( array( 'meta.place' => '  ', 'meta.city' => '', 'meta.zero' => 0 ) );

		$this->assertSame( 'at n.d.', Rule_Evaluator::evaluate( $this->template( 'at {meta.place|meta.city|meta.none|"n.d."}' ), 'post', $reader )['value'] );
		$this->assertSame( 'count 0', Rule_Evaluator::evaluate( $this->template( 'count {meta.zero|"none"}' ), 'post', $reader )['value'] );
	}

	/**
	 * A placeholder without a value is empty; when no placeholder has a value the row has none.
	 */
	public function test_a_template_without_any_value_has_no_value() {
		$reader = $this->reader( array( 'core.title' => 'T' ) );

		$this->assertSame( array( 'present' => true, 'value' => 'T, ' ), Rule_Evaluator::evaluate( $this->template( '{core.title}, {meta.none}' ), 'post', $reader ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->template( 'Place: {meta.none}' ), 'post', $reader ) );
		$this->assertSame( array( 'present' => true, 'value' => 'Site A' ), Rule_Evaluator::evaluate( $this->template( 'Site A' ), 'post', $reader ), 'Without placeholders it is a fixed text.' );
	}

	/**
	 * An optional section is written only when every placeholder in it has a value; a literal fallback always
	 * has one. The row has no value only when no placeholder at all has one.
	 */
	public function test_optional_sections_of_a_template() {
		$both    = $this->reader( array( 'meta.city' => 'Roma', 'meta.address' => 'Via X' ) );
		$city    = $this->reader( array( 'meta.city' => 'Roma', 'meta.address' => '  ' ) );
		$address = $this->reader( array( 'meta.address' => 'Via X' ) );
		$place   = $this->template( '{meta.city}[, {meta.address}]' );

		$this->assertSame( 'Roma, Via X', Rule_Evaluator::evaluate( $place, 'post', $both )['value'] );
		$this->assertSame( 'Roma', Rule_Evaluator::evaluate( $place, 'post', $city )['value'] );
		$this->assertSame( ', Via X', Rule_Evaluator::evaluate( $place, 'post', $address )['value'] );
		$this->assertSame( 'Roma', Rule_Evaluator::evaluate( $this->template( '{meta.city}[ – {meta.city} {meta.address}]' ), 'post', $city )['value'], 'Every placeholder of the section needs a value.' );
		$this->assertSame( 'Roma (n.d.)', Rule_Evaluator::evaluate( $this->template( '{meta.city}[ ({meta.zip|"n.d."})]' ), 'post', $city )['value'] );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->template( 'Place[: {meta.none}]' ), 'post', $city ) );
	}

	/**
	 * The template of the post type of the item replaces the general one.
	 */
	public function test_the_template_of_the_post_type_wins() {
		$row    = $this->template( '{core.title}', array( 'templates' => array( '*' => '{core.title}', 'event' => '{core.title} @ {meta.place}' ) ) );
		$reader = $this->reader( array( 'core.title' => 'T', 'meta.place' => 'Pisa' ) );

		$this->assertSame( 'T', Rule_Evaluator::evaluate( $row, 'post', $reader )['value'] );
		$this->assertSame( 'T @ Pisa', Rule_Evaluator::evaluate( $row, 'event', $reader )['value'] );
	}

	/**
	 * An unconfirmed sensitive source is never read in a placeholder; a confirmed one is.
	 */
	public function test_unconfirmed_sensitive_placeholders_are_never_read() {
		$asked  = array();
		$reader = $this->reader( array( 'meta.api_key' => 'SECRET' ), $asked );

		$this->assertSame( 'key: none', Rule_Evaluator::evaluate( $this->template( 'key: {meta.api_key|"none"}' ), 'post', $reader )['value'] );
		$this->assertSame( array(), $asked );

		$confirmed = $this->template( 'key: {meta.api_key}', array( 'confirmed' => array( 'meta.api_key' ) ) );
		$this->assertSame( 'key: SECRET', Rule_Evaluator::evaluate( $confirmed, 'post', $reader )['value'] );
	}

	/**
	 * Values that are not text get their standard text form, by the declared type of their source.
	 */
	public function test_placeholder_values_in_standard_form() {
		$reader   = $this->reader( array( 'core.date' => '2026-07-01 08:30:00', 'meta.tags' => array( 'a', 'b', 3 ), 'meta.flag' => true, 'core.id' => 7, 'meta.price' => '15,50' ) );
		$declared = $this->declared( array( 'core.date' => 'datetime', 'meta.tags' => 'list', 'meta.flag' => 'boolean', 'core.id' => 'number', 'meta.price' => 'number' ) );

		$this->assertSame(
			array( 'k' => '2026-07-01T08:30:00Z | a, b, 3 | true | 7 | ' ),
			Rule_Evaluator::evaluate_mapping(
				array( 'version' => 1, 'rows' => array( $this->template( '{core.date} | {meta.tags} | {meta.flag} | {core.id} | {meta.price}' ) ) ),
				'post',
				$reader,
				$declared
			)
		);
	}

	/**
	 * `max_length` cuts a plain text output after the normalization, at a whole word with `…`; other types and
	 * texts within the limit are not changed.
	 */
	public function test_max_length_cuts_plain_texts() {
		$reader  = $this->reader( array( 'core.title' => 'Concerto di <b>musica</b> classica al teatro', 'core.id' => 1234567, 'core.content' => '<p>Long text here</p>' ) );
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				$this->field( array( 'core.title' ), array( 'key' => 'field', 'type' => 'string', 'max_length' => 20 ) ),
				$this->template( 'Event: {core.title}', array( 'key' => 'template', 'type' => 'auto', 'max_length' => 12 ) ),
				$this->field( array( 'core.title' ), array( 'key' => 'short', 'type' => 'string', 'max_length' => 100 ) ),
				$this->field( array( 'core.id' ), array( 'key' => 'number', 'type' => 'number', 'max_length' => 3 ) ),
				$this->field( array( 'core.content' ), array( 'key' => 'html', 'type' => 'html', 'max_length' => 5 ) ),
			),
		);

		$this->assertSame(
			array(
				'field'    => 'Concerto di musica…',
				'template' => 'Event…',
				'short'    => 'Concerto di musica classica al teatro',
				'number'   => 1234567,
				'html'     => '<p>Long text here</p>',
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $reader )
		);
	}

	/**
	 * Text output removes the tags of the whole result; HTML output keeps them; `auto` is text.
	 */
	public function test_template_output_types() {
		$reader   = $this->reader( array( 'core.content' => '<p>Hello &amp; welcome</p>' ) );
		$declared = $this->declared( array( 'core.content' => 'html' ) );
		$mapping  = array(
			'version' => 1,
			'rows'    => array(
				$this->template( '<b>{core.content}</b>', array( 'key' => 'text' ) ),
				$this->template( '<b>{core.content}</b>', array( 'key' => 'html', 'type' => 'html' ) ),
				$this->template( '<b>{core.content}</b>', array( 'key' => 'auto', 'type' => 'auto' ) ),
			),
		);

		$this->assertSame(
			array(
				'text' => 'Hello & welcome',
				'html' => '<b><p>Hello &amp; welcome</p></b>',
				'auto' => 'Hello & welcome',
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $reader, $declared )
		);
	}

	/**
	 * A damaged template has no value and reads nothing.
	 */
	public function test_a_damaged_template_has_no_value() {
		$asked  = array();
		$reader = $this->reader( array( 'core.title' => 'T' ), $asked );

		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->template( '{core.title' ), 'post', $reader ) );
		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->template( '', array( 'templates' => 'x' ) ), 'post', $reader ) );
		$this->assertSame( array(), $asked );
	}

	/**
	 * A `lookup` row with one input for every post type.
	 *
	 * @param string               $input    Source of the input.
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function lookup( string $input, array $override = array() ): array {
		return array_merge(
			array(
				'key'    => 'k',
				'kind'   => 'lookup',
				'type'   => 'auto',
				'inputs' => array( '*' => $input ),
				'cases'  => array(
					array( 'match' => 'post', 'output' => array( 'fixed' => 'News' ) ),
					array( 'match' => 'event', 'output' => array( 'source' => 'meta.category' ) ),
				),
			),
			$override
		);
	}

	/**
	 * The output of the case whose text matches the input: a fixed text or the value of a source.
	 */
	public function test_a_lookup_gives_the_output_of_the_matching_case() {
		$reader = $this->reader( array( 'core.type' => 'post', 'meta.category' => 'Concerts' ) );
		$this->assertSame( array( 'present' => true, 'value' => 'News' ), Rule_Evaluator::evaluate( $this->lookup( 'core.type' ), 'post', $reader ) );

		$reader = $this->reader( array( 'core.type' => 'event', 'meta.category' => 'Concerts' ) );
		$this->assertSame( array( 'present' => true, 'value' => 'Concerts' ), Rule_Evaluator::evaluate( $this->lookup( 'core.type' ), 'event', $reader ) );
	}

	/**
	 * The comparison is exact (case included) on the text of the input without spaces at the ends; `0` and
	 * `false` are compared in their standard text form.
	 */
	public function test_a_lookup_compares_the_exact_text() {
		$row = $this->lookup(
			'meta.status',
			array(
				'cases' => array(
					array( 'match' => 'Open', 'output' => array( 'fixed' => 'open' ) ),
					array( 'match' => '0', 'output' => array( 'fixed' => 'zero' ) ),
					array( 'match' => 'false', 'output' => array( 'fixed' => 'no' ) ),
				),
			)
		);
		$declared = $this->declared( array( 'meta.flag' => 'boolean' ) );

		$this->assertSame( 'open', Rule_Evaluator::evaluate( $row, 'post', $this->reader( array( 'meta.status' => '  Open ' ) ) )['value'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $row, 'post', $this->reader( array( 'meta.status' => 'open' ) ) )['present'], 'Case matters.' );
		$this->assertSame( 'zero', Rule_Evaluator::evaluate( $row, 'post', $this->reader( array( 'meta.status' => 0 ) ) )['value'] );
		$this->assertSame( 'no', Rule_Evaluator::evaluate( array_merge( $row, array( 'inputs' => array( '*' => 'meta.flag' ) ) ), 'post', $this->reader( array( 'meta.flag' => false ) ), $declared )['value'] );
	}

	/**
	 * Without a matching case: the default (none gives no value; a fixed text, a source, or the input as it is).
	 */
	public function test_a_lookup_without_a_matching_case_gives_the_default() {
		$reader = $this->reader( array( 'core.type' => 'page', 'core.title' => 'About' ) );

		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->lookup( 'core.type' ), 'page', $reader ) );
		$this->assertSame( 'Other', Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'default' => array( 'fixed' => 'Other' ) ) ), 'page', $reader )['value'] );
		$this->assertSame( 'About', Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'default' => array( 'source' => 'core.title' ) ) ), 'page', $reader )['value'] );
		$this->assertSame( 'page', Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'default' => array( 'input' => true ) ) ), 'page', $reader )['value'] );
	}

	/**
	 * An input that does not exist or is empty matches no case: the default is used.
	 */
	public function test_a_lookup_with_a_missing_or_empty_input_gives_the_default() {
		$row = $this->lookup( 'meta.status', array( 'default' => array( 'fixed' => 'unknown' ) ) );

		$this->assertSame( 'unknown', Rule_Evaluator::evaluate( $row, 'post', $this->reader( array() ) )['value'] );
		$this->assertSame( 'unknown', Rule_Evaluator::evaluate( $row, 'post', $this->reader( array( 'meta.status' => '  ' ) ) )['value'] );

		$input = $this->lookup( 'meta.status', array( 'default' => array( 'input' => true ) ) );
		$this->assertFalse( Rule_Evaluator::evaluate( $input, 'post', $this->reader( array() ) )['present'], 'No input: no value.' );
		$this->assertSame( '', Rule_Evaluator::evaluate( $input, 'post', $this->reader( array( 'meta.status' => '' ) ) )['value'], 'An empty input stays empty.' );
		$this->assertSame( '', Rule_Evaluator::evaluate( $input, 'post', $this->reader( array( 'meta.status' => '   ' ) ) )['value'], 'A text of spaces is written empty.' );
	}

	/**
	 * A list is not a valid input: the row has no value and no output is read.
	 */
	public function test_a_lookup_with_a_list_input_has_no_value() {
		$asked  = array();
		$reader = $this->reader( array( 'meta.tags' => array( 'post' ), 'meta.category' => 'C' ), $asked );
		$row    = $this->lookup( 'meta.tags', array( 'default' => array( 'input' => true ) ) );

		$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $row, 'post', $reader ) );
		$this->assertSame( array( 'meta.tags' ), $asked );
	}

	/**
	 * The input of the post type of the item replaces the general one.
	 */
	public function test_the_input_of_the_post_type_wins() {
		$row    = $this->lookup( 'core.type', array( 'inputs' => array( '*' => 'core.type', 'event' => 'meta.kind' ) ) );
		$reader = $this->reader( array( 'core.type' => 'event', 'meta.kind' => 'post' ) );

		$this->assertSame( 'News', Rule_Evaluator::evaluate( $row, 'event', $reader )['value'] );
		$this->assertSame( 'Concerts', Rule_Evaluator::evaluate( $row, 'post', $this->reader( array( 'core.type' => 'event', 'meta.category' => 'Concerts' ) ) )['value'] );
	}

	/**
	 * An output source without a value gives no value; an empty one keeps its empty form.
	 */
	public function test_a_lookup_output_source_without_a_value() {
		$row = $this->lookup( 'core.type' );

		$this->assertFalse( Rule_Evaluator::evaluate( $row, 'event', $this->reader( array( 'core.type' => 'event' ) ) )['present'] );
		$this->assertSame( array( 'present' => true, 'value' => '' ), Rule_Evaluator::evaluate( $row, 'event', $this->reader( array( 'core.type' => 'event', 'meta.category' => '' ) ) ) );
	}

	/**
	 * Unconfirmed sensitive sources are never read: as input (the default is used), as output or as default.
	 */
	public function test_unconfirmed_sensitive_sources_of_a_lookup_are_never_read() {
		$asked  = array();
		$reader = $this->reader( array( 'meta.user_email' => 'post', 'meta.api_key' => 'secret' ), $asked );

		$input = $this->lookup( 'meta.user_email', array( 'default' => array( 'fixed' => 'd' ) ) );
		$this->assertSame( 'd', Rule_Evaluator::evaluate( $input, 'post', $reader )['value'] );

		$output = $this->lookup( 'meta.user_email', array( 'confirmed' => array( 'meta.user_email' ), 'cases' => array( array( 'match' => 'post', 'output' => array( 'source' => 'meta.api_key' ) ) ) ) );
		$this->assertFalse( Rule_Evaluator::evaluate( $output, 'post', $reader )['present'] );

		$default = $this->lookup( 'core.type', array( 'default' => array( 'source' => 'meta.api_key' ) ) );
		$this->assertFalse( Rule_Evaluator::evaluate( $default, 'page', $reader )['present'] );

		$this->assertSame( array( 'meta.user_email', 'core.type' ), $asked );
	}

	/**
	 * With `auto` the row takes the common type of its outputs (fixed values, sources, the input when the
	 * default is the input), otherwise text; an explicit type wins.
	 */
	public function test_lookup_output_types() {
		$reader   = $this->reader( array( 'core.type' => 'post', 'core.id' => 7, 'meta.count' => '12' ) );
		$declared = $this->declared( array( 'core.id' => 'number', 'meta.count' => 'number', 'core.type' => 'string' ) );
		$numbers  = array(
			array( 'match' => 'post', 'output' => array( 'source' => 'core.id' ) ),
			array( 'match' => 'event', 'output' => array( 'fixed' => 3 ) ),
		);
		$mapping  = array(
			'version' => 1,
			'rows'    => array(
				$this->lookup( 'core.type', array( 'key' => 'number', 'cases' => $numbers, 'default' => array( 'source' => 'meta.count' ) ) ),
				$this->lookup( 'core.type', array( 'key' => 'mixed', 'cases' => $numbers, 'default' => array( 'input' => true ) ) ),
				$this->lookup( 'core.type', array( 'key' => 'forced', 'type' => 'string', 'cases' => $numbers ) ),
				$this->lookup( 'core.type', array( 'key' => 'none' ) ),
			),
		);

		$this->assertSame(
			array(
				'number' => 7,
				'mixed'  => '7',
				'forced' => '7',
				'none'   => 'News',
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $reader, $declared )
		);
	}

	/**
	 * A damaged lookup row has no value.
	 */
	public function test_a_damaged_lookup_has_no_value() {
		$reader = $this->reader( array( 'core.type' => 'post' ) );

		$this->assertFalse( Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'cases' => 'x' ) ), 'post', $reader )['present'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'inputs' => 'x' ) ), 'post', $reader )['present'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->lookup( 'core.type', array( 'cases' => array( 'x', array( 'match' => 'post' ) ) ) ), 'post', $reader )['present'] );
	}

	/**
	 * A `condition` row with one input for every post type, "yes" when true and "no" otherwise.
	 *
	 * @param string               $operator Operator.
	 * @param list<string>|null    $matches  Texts to compare, null for none.
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	private function condition( string $operator, ?array $matches, array $override = array() ): array {
		$row = array_merge(
			array(
				'key'      => 'k',
				'kind'     => 'condition',
				'type'     => 'auto',
				'inputs'   => array( '*' => 'meta.x' ),
				'operator' => $operator,
				'matches'  => $matches,
				'then'     => array( 'fixed' => 'yes' ),
				'else'     => array( 'fixed' => 'no' ),
			),
			$override
		);
		if ( null === $matches ) {
			unset( $row['matches'] );
		}

		return $row;
	}

	/**
	 * The answer of every operator for a value: the "then" output when true, the "else" output otherwise.
	 *
	 * @dataProvider condition_provider
	 *
	 * @param string            $operator Operator.
	 * @param list<string>|null $matches  Texts to compare.
	 * @param mixed             $value    Value of the input (null: it does not exist).
	 * @param string            $expected `yes` or `no`.
	 */
	public function test_condition_operators( string $operator, ?array $matches, $value, string $expected ) {
		$reader = $this->reader( null === $value ? array() : array( 'meta.x' => $value ) );

		$this->assertSame( array( 'present' => true, 'value' => $expected ), Rule_Evaluator::evaluate( $this->condition( $operator, $matches ), 'post', $reader ) );
	}

	/**
	 * Data for test_condition_operators().
	 *
	 * @return array<string, array{0: string, 1: list<string>|null, 2: mixed, 3: string}>
	 */
	public function condition_provider(): array {
		return array(
			'empty: missing'              => array( 'empty', null, null, 'yes' ),
			'empty: empty text'           => array( 'empty', null, '  ', 'yes' ),
			'empty: empty list'           => array( 'empty', null, array(), 'yes' ),
			'empty: zero is a value'      => array( 'empty', null, 0, 'no' ),
			'not empty: false is a value' => array( 'not_empty', null, false, 'yes' ),
			'not empty: list'             => array( 'not_empty', null, array( 'a' ), 'yes' ),
			'not empty: missing'          => array( 'not_empty', null, null, 'no' ),
			'equals: same text'           => array( 'equals', array( 'Open' ), ' Open ', 'yes' ),
			'equals: case matters'        => array( 'equals', array( 'Open' ), 'open', 'no' ),
			'equals: number as text'      => array( 'equals', array( '0' ), 0, 'yes' ),
			'equals: missing'             => array( 'equals', array( 'Open' ), null, 'no' ),
			'not equals: other text'      => array( 'not_equals', array( 'Open' ), 'Closed', 'yes' ),
			'not equals: same text'       => array( 'not_equals', array( 'Open' ), 'Open', 'no' ),
			'not equals: missing'         => array( 'not_equals', array( 'Open' ), null, 'yes' ),
			'one of: listed'              => array( 'one_of', array( 'Theatre', 'Music' ), 'Music', 'yes' ),
			'one of: not listed'          => array( 'one_of', array( 'Theatre', 'Music' ), 'Dance', 'no' ),
			'contains: element of a list' => array( 'contains', array( 'Events' ), array( 'News', 'Events' ), 'yes' ),
			'contains: not in the list'   => array( 'contains', array( 'Events' ), array( 'News' ), 'no' ),
			'contains: single value'      => array( 'contains', array( 'Events' ), 'Events', 'yes' ),
			'contains: part of a text'    => array( 'contains', array( 'Breaking' ), 'Breaking: storm hits city', 'yes' ),
			'contains: text not found'    => array( 'contains', array( 'storm' ), 'Sunny day', 'no' ),
			'contains: case matters'      => array( 'contains', array( 'breaking' ), 'Breaking news', 'no' ),
			'contains: part of a number'  => array( 'contains', array( '20' ), 2026, 'yes' ),
			'contains: part of an element' => array( 'contains', array( 'Event' ), array( 'Events' ), 'no' ),
			'contains: missing'           => array( 'contains', array( 'Events' ), null, 'no' ),
		);
	}

	/**
	 * Equality operators do not compare a list: the row has no value.
	 */
	public function test_a_condition_does_not_compare_a_list_for_equality() {
		$reader = $this->reader( array( 'meta.x' => array( 'Open' ) ) );

		foreach ( array( 'equals', 'not_equals', 'one_of' ) as $operator ) {
			$this->assertSame( array( 'present' => false, 'value' => null ), Rule_Evaluator::evaluate( $this->condition( $operator, array( 'Open' ) ), 'post', $reader ), $operator );
		}
	}

	/**
	 * Every output: nothing (no value), a fixed value, a source, the input as it is.
	 */
	public function test_condition_outputs() {
		$reader = $this->reader( array( 'meta.x' => 'hidden', 'core.title' => 'T' ) );
		$row    = $this->condition( 'equals', array( 'hidden' ) );

		$this->assertFalse( Rule_Evaluator::evaluate( array_diff_key( $row, array( 'then' => true ) ), 'post', $reader )['present'], 'No "then": no value.' );
		$this->assertSame( 'T', Rule_Evaluator::evaluate( array_merge( $row, array( 'then' => array( 'source' => 'core.title' ) ) ), 'post', $reader )['value'] );
		$this->assertSame( 'hidden', Rule_Evaluator::evaluate( array_merge( $row, array( 'then' => array( 'input' => true ) ) ), 'post', $reader )['value'] );

		$other = $this->reader( array( 'meta.x' => 'shown' ) );
		$this->assertSame( 'shown', Rule_Evaluator::evaluate( array_merge( $row, array( 'else' => array( 'input' => true ) ) ), 'post', $other )['value'] );
		$this->assertFalse( Rule_Evaluator::evaluate( array_diff_key( $row, array( 'else' => true ) ), 'post', $other )['present'], 'No "else": no value.' );
		$this->assertFalse( Rule_Evaluator::evaluate( array_merge( $row, array( 'else' => array( 'input' => true ) ) ), 'post', $this->reader( array() ) )['present'], 'A missing input as output: no value.' );

		$empty = $this->condition( 'empty', null, array( 'then' => array( 'input' => true ) ) );
		$this->assertSame( '', Rule_Evaluator::evaluate( $empty, 'post', $this->reader( array( 'meta.x' => '  ' ) ) )['value'], 'A text of spaces is written empty.' );
		$this->assertSame( array(), Rule_Evaluator::evaluate( $empty, 'post', $this->reader( array( 'meta.x' => array() ) ) )['value'], 'An empty list stays a list.' );
	}

	/**
	 * The input of the post type of the item replaces the general one.
	 */
	public function test_the_condition_input_of_the_post_type_wins() {
		$row    = $this->condition( 'not_empty', null, array( 'inputs' => array( '*' => 'meta.x', 'event' => 'meta.y' ) ) );
		$reader = $this->reader( array( 'meta.x' => 'a' ) );

		$this->assertSame( 'yes', Rule_Evaluator::evaluate( $row, 'post', $reader )['value'] );
		$this->assertSame( 'no', Rule_Evaluator::evaluate( $row, 'event', $reader )['value'] );
	}

	/**
	 * Unconfirmed sensitive sources are never read: an input counts as missing, an output gives no value.
	 */
	public function test_unconfirmed_sensitive_sources_of_a_condition_are_never_read() {
		$asked  = array();
		$reader = $this->reader( array( 'meta.user_email' => 'a@b.c', 'meta.api_key' => 'secret', 'meta.x' => 'a' ), $asked );

		$this->assertSame( 'yes', Rule_Evaluator::evaluate( $this->condition( 'empty', null, array( 'inputs' => array( '*' => 'meta.user_email' ) ) ), 'post', $reader )['value'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->condition( 'not_empty', null, array( 'then' => array( 'source' => 'meta.api_key' ) ) ), 'post', $reader )['present'] );
		$this->assertSame( array( 'meta.x' ), $asked );
	}

	/**
	 * With `auto` the row takes the common type of its outputs (the input when an output is the input).
	 */
	public function test_condition_output_types() {
		$reader   = $this->reader( array( 'meta.x' => 'a', 'core.id' => 7 ) );
		$declared = $this->declared( array( 'core.id' => 'number', 'meta.x' => 'string' ) );
		$mapping  = array(
			'version' => 1,
			'rows'    => array(
				$this->condition( 'not_empty', null, array( 'key' => 'flag', 'then' => array( 'fixed' => true ), 'else' => array( 'fixed' => false ) ) ),
				$this->condition( 'not_empty', null, array( 'key' => 'number', 'then' => array( 'source' => 'core.id' ), 'else' => array( 'fixed' => 0 ) ) ),
				$this->condition( 'not_empty', null, array( 'key' => 'mixed', 'then' => array( 'source' => 'core.id' ), 'else' => array( 'input' => true ) ) ),
				$this->condition( 'empty', null, array( 'key' => 'none' ) ),
			),
		);

		$this->assertSame(
			array(
				'flag'   => true,
				'number' => 7,
				'mixed'  => '7',
				'none'   => 'no',
			),
			Rule_Evaluator::evaluate_mapping( $mapping, 'post', $reader, $declared )
		);
	}

	/**
	 * A damaged condition row has no value.
	 */
	public function test_a_damaged_condition_has_no_value() {
		$reader = $this->reader( array( 'meta.x' => 'a' ) );

		$this->assertFalse( Rule_Evaluator::evaluate( $this->condition( 'starts_with', array( 'a' ) ), 'post', $reader )['present'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->condition( 'equals', array( 'a' ), array( 'matches' => 'a' ) ), 'post', $reader )['present'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->condition( 'equals', null ), 'post', $reader )['present'] );
		$this->assertFalse( Rule_Evaluator::evaluate( $this->condition( 'equals', array( 'a' ), array( 'inputs' => 'x' ) ), 'post', $reader )['present'] );
	}
}

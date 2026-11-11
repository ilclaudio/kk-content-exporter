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
}

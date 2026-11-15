<?php
/**
 * Unit tests of the check of a mapping against the catalog of a feed (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Catalog_Validator;
use KKCE\Output\Mapping;
use KKCE\Sources\Catalog;
use KKCE\Sources\Source;

/**
 * A valid mapping is also checked against the sources of the post types of its feed.
 */
class KKCE_Catalog_Validator_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Catalog of a feed with posts and events: common core fields, a list of the posts, a text of the events.
	 *
	 * @return Catalog
	 */
	private function catalog(): Catalog {
		return new Catalog(
			array(
				new Source( 'core.title', 'Title', '', 'string' ),
				new Source( 'core.id', 'ID', '', 'number' ),
				new Source( 'core.content', 'Content', '', 'html', array( 'raw', 'shortcodes' ) ),
				new Source( 'meta.tags', 'Tags', 'post', 'list' ),
				new Source( 'meta.place', 'Place', 'event', 'string' ),
			),
			array( 'post', 'event' )
		);
	}

	/**
	 * Errors of a mapping with the given rows, as `row:field:code` strings.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows.
	 * @return list<string>
	 */
	private function errors( array $rows ): array {
		return array_map(
			static fn( array $error ): string => $error['row'] . ':' . $error['field'] . ':' . $error['code'],
			Catalog_Validator::validate( array( 'version' => 1, 'rows' => $rows ), $this->catalog() )
		);
	}

	/**
	 * A row of the given kind with its fields.
	 *
	 * @param string               $kind   Kind.
	 * @param array<string, mixed> $fields Fields of the kind.
	 * @return array<string, mixed>
	 */
	private function row( string $kind, array $fields ): array {
		static $count = 0;
		++$count;

		return array_merge( array( 'key' => 'k' . $count, 'kind' => $kind ), $fields );
	}

	/**
	 * Sources of the catalog give no error in every kind of row, also a source of one post type of the feed
	 * used for every post type.
	 */
	public function test_known_sources_are_accepted() {
		$rows = array(
			$this->row( 'field', array( 'sources' => array( '*' => array( 'meta.place', 'core.title' ), 'post' => array( 'meta.tags' ) ) ) ),
			$this->row( 'field', array( 'sources' => array( '*' => array( 'core.content' ) ), 'options' => array( 'raw' => true ) ) ),
			$this->row( 'fixed', array( 'values' => array( '*' => 'it' ) ) ),
			$this->row( 'template', array( 'templates' => array( '*' => '{core.title}[ – {meta.place}] {meta.tags}' ) ) ),
			$this->row( 'lookup', array( 'inputs' => array( '*' => 'core.title', 'event' => 'meta.place' ), 'cases' => array( array( 'match' => 'a', 'output' => array( 'source' => 'meta.tags' ) ) ), 'default' => array( 'source' => 'core.id' ) ) ),
			$this->row( 'condition', array( 'inputs' => array( '*' => 'meta.tags' ), 'operator' => 'contains', 'matches' => array( 'x' ), 'then' => array( 'source' => 'meta.place' ), 'else' => array( 'input' => true ) ) ),
		);

		$this->assertSame( array(), $this->errors( $rows ) );
	}

	/**
	 * A source that the catalog does not have is reported on the field that uses it, once per field.
	 */
	public function test_unknown_sources_are_refused() {
		$rows = array(
			$this->row( 'field', array( 'sources' => array( '*' => array( 'core.title', 'meta.nothing', 'meta.other' ) ) ) ),
			$this->row( 'template', array( 'templates' => array( '*' => '{core.title|meta.nothing}' ) ) ),
			$this->row( 'lookup', array( 'inputs' => array( '*' => 'meta.nothing' ), 'cases' => array( array( 'match' => 'a', 'output' => array( 'source' => 'meta.nothing' ) ) ), 'default' => array( 'source' => 'meta.nothing' ) ) ),
			$this->row( 'condition', array( 'inputs' => array( '*' => 'core.title' ), 'operator' => 'not_empty', 'then' => array( 'source' => 'meta.nothing' ), 'else' => array( 'source' => 'meta.nothing' ) ) ),
		);

		$this->assertSame(
			array( '0:sources:unknown', '1:templates:unknown', '2:inputs:unknown', '2:cases:unknown', '2:default:unknown', '3:then:unknown', '3:else:unknown' ),
			$this->errors( $rows )
		);
	}

	/**
	 * The exception of a post type uses the sources of that post type (and the common ones); an exception for
	 * a post type that the feed no longer reads is kept and not checked.
	 */
	public function test_exceptions_use_the_sources_of_their_post_type() {
		$this->assertSame( array( '0:sources:unknown' ), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ), 'post' => array( 'meta.place' ) ) ) ) ) ) );
		$this->assertSame( array( '0:inputs:unknown' ), $this->errors( array( $this->row( 'lookup', array( 'inputs' => array( '*' => 'core.title', 'post' => 'meta.place' ), 'cases' => array( array( 'match' => 'a', 'output' => array( 'fixed' => 'b' ) ) ) ) ) ) ) );
		$this->assertSame( array( '0:templates:unknown' ), $this->errors( array( $this->row( 'template', array( 'templates' => array( '*' => 'x', 'event' => '{meta.tags}' ) ) ) ) ) );
		$this->assertSame( array(), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ), 'page' => array( 'meta.gone' ) ) ) ) ) ), 'Post type not in the feed.' );
	}

	/**
	 * A list cannot be the input of a lookup table, nor of a condition that compares texts.
	 */
	public function test_lists_where_a_text_is_compared_are_refused() {
		$this->assertSame( array( '0:inputs:list' ), $this->errors( array( $this->row( 'lookup', array( 'inputs' => array( '*' => 'core.title', 'post' => 'meta.tags' ), 'cases' => array( array( 'match' => 'a', 'output' => array( 'fixed' => 'b' ) ) ) ) ) ) ) );

		foreach ( array( 'equals', 'not_equals', 'one_of' ) as $operator ) {
			$this->assertSame( array( '0:inputs:list' ), $this->errors( array( $this->row( 'condition', array( 'inputs' => array( '*' => 'meta.tags' ), 'operator' => $operator, 'matches' => array( 'x' ) ) ) ) ), $operator );
		}
		foreach ( array( 'empty', 'not_empty' ) as $operator ) {
			$this->assertSame( array(), $this->errors( array( $this->row( 'condition', array( 'inputs' => array( '*' => 'meta.tags' ), 'operator' => $operator ) ) ) ), $operator );
		}
	}

	/**
	 * An option turned on must be accepted by a source of the row.
	 */
	public function test_options_must_be_accepted_by_a_source() {
		$this->assertSame( array( '0:options:not_allowed' ), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ) ), 'options' => array( 'raw' => true ) ) ) ) ) );
		$this->assertSame( array(), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ) ), 'options' => array( 'raw' => false ) ) ) ) ), 'An option turned off costs nothing.' );
		$this->assertSame( array(), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title', 'core.content' ) ), 'options' => array( 'shortcodes' => true ) ) ) ) ) );
	}

	/**
	 * The format is checked first: a mapping with format errors gets only those, the catalog is not used.
	 */
	public function test_format_errors_come_first() {
		$this->assertSame( array( '0:kind:unknown' ), $this->errors( array( array( 'key' => 'a', 'kind' => 'other', 'sources' => array( '*' => array( 'meta.nothing' ) ) ) ) ) );
		$this->assertSame( array( '-1::not_object' ), array_map( static fn( array $error ): string => $error['row'] . ':' . $error['field'] . ':' . $error['code'], Catalog_Validator::validate( 'x', $this->catalog() ) ) );
	}

	/**
	 * A text of a mapping: unreadable gives `json`, longer than the limit in bytes gives `too_large`; a valid
	 * one gives its canonical form and no error.
	 */
	public function test_validate_text() {
		$this->assertSame( array( 'mapping' => null, 'errors' => array( array( 'row' => -1, 'field' => '', 'code' => 'json' ) ) ), Catalog_Validator::validate_text( '{"version":1,', $this->catalog(), 'json_encode' ) );
		$this->assertSame( array( 'mapping' => null, 'errors' => array( array( 'row' => -1, 'field' => '', 'code' => 'json' ) ) ), Catalog_Validator::validate_text( 5, $this->catalog(), 'json_encode' ) );
		$this->assertSame( 'too_large', Catalog_Validator::validate_text( str_repeat( ' ', Mapping::MAX_BYTES + 1 ), $this->catalog(), 'json_encode' )['errors'][0]['code'] );

		$result = Catalog_Validator::validate_text( '{"version":1,"rows":[{"key":"t","kind":"field","sources":{"*":["core.title"]}}]}', $this->catalog(), 'json_encode' );
		$this->assertSame( array(), $result['errors'] );
		$this->assertSame( 'auto', $result['mapping']['rows'][0]['type'], 'Canonical form.' );

		$this->assertSame( array( array( 'row' => 0, 'field' => 'sources', 'code' => 'unknown' ) ), Catalog_Validator::validate_text( '{"version":1,"rows":[{"key":"t","kind":"field","sources":{"*":["meta.nothing"]}}]}', $this->catalog(), 'json_encode' )['errors'] );
	}

	/**
	 * The saved form of a mapping writes accents as `\uXXXX`, so it can be larger than the text that was sent:
	 * a mapping whose saved form is over the limit is refused as too large, never saved as empty.
	 */
	public function test_a_mapping_too_large_once_saved_is_refused() {
		$rows = array();
		for ( $i = 0; $i < 100; $i++ ) {
			$rows[] = array( 'key' => 'k' . $i, 'kind' => 'fixed', 'type' => 'string', 'values' => array( '*' => str_repeat( 'è', 1000 ) ) );
		}
		$text = json_encode( array( 'version' => 1, 'rows' => $rows ), JSON_UNESCAPED_UNICODE );
		$this->assertLessThan( Mapping::MAX_BYTES, strlen( $text ), 'The text sent is within the limit.' );

		$this->assertSame( array( 'mapping' => null, 'errors' => array( array( 'row' => -1, 'field' => '', 'code' => 'too_large' ) ) ), Catalog_Validator::validate_text( $text, $this->catalog(), 'json_encode' ) );
	}

	/**
	 * The options of a row are not checked when some of its sources belong to a post type that the feed no
	 * longer reads (those entries are kept and not checked); an option set to an empty value is off.
	 */
	public function test_options_of_unchecked_entries_and_options_turned_off() {
		$this->assertSame( array(), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ), 'page' => array( 'meta.gone' ) ), 'options' => array( 'decimals' => true ) ) ) ) ) );

		foreach ( array( 0, '0', '', false ) as $off ) {
			$this->assertSame( array(), $this->errors( array( $this->row( 'field', array( 'sources' => array( '*' => array( 'core.title' ) ), 'options' => array( 'raw' => $off ) ) ) ) ), var_export( $off, true ) );
		}
	}
}

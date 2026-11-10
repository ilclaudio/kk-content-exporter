<?php
/**
 * Unit tests of the normalization of the exported values (pure logic, no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Output\Normalizer;

/**
 * Every output type with valid, borderline and invalid values.
 */
class KKCE_Normalizer_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Origin of the site used to make root-relative URLs absolute.
	 */
	private const ORIGIN = 'https://example.org';

	/**
	 * Normalizes a value and returns the value, or a marker for "no value".
	 *
	 * @param mixed  $value Value.
	 * @param string $type  Output type.
	 * @return mixed The value, `'<absent>'` or `'<not_convertible>'`.
	 */
	private function out( $value, string $type ) {
		$result = Normalizer::normalize( $value, $type, self::ORIGIN );

		if ( $result['present'] ) {
			$this->assertSame( '', $result['error'] );
			return $result['value'];
		}

		return '' === $result['error'] ? '<absent>' : '<' . $result['error'] . '>';
	}

	/**
	 * Cases of one type.
	 *
	 * @param string             $type  Output type.
	 * @param array<int, array{0: mixed, 1: mixed}> $cases Input => expected output.
	 */
	private function check( string $type, array $cases ): void {
		foreach ( $cases as $index => $case ) {
			$this->assertSame( $case[1], $this->out( $case[0], $type ), $type . ' case ' . $index . ': ' . var_export( $case[0], true ) );
		}
	}

	/**
	 * Text: plain text without tags, entities decoded; numbers as text, booleans as words, lists joined.
	 */
	public function test_string() {
		$this->check(
			'string',
			array(
				array( 'Plain', 'Plain' ),
				array( 'Concert &amp; more &#8220;live&#8221;', 'Concert & more “live”' ),
				array( '<em>Big</em> news', 'Big news' ),
				array( '&lt;b&gt; is a tag', '<b> is a tag' ),
				array( 'caffè', 'caffè' ),
				array( 0, '0' ),
				array( 15, '15' ),
				array( 1.5, '1.5' ),
				array( true, 'true' ),
				array( false, 'false' ),
				array( array( 'Arts', 'News' ), 'Arts, News' ),
				array( array( 'a' => 'x' ), '<not_convertible>' ),
				array( array( array( 'x' ) ), '<not_convertible>' ),
				array( '', '' ),
				array( array(), '' ),
				array( null, '<absent>' ),
			)
		);
	}

	/**
	 * HTML: text and numbers as they are; booleans and lists refused.
	 */
	public function test_html() {
		$this->check(
			'html',
			array(
				array( '<p>Hello &amp; welcome</p>', '<p>Hello &amp; welcome</p>' ),
				array( 3, '3' ),
				array( '', '' ),
				array( true, '<not_convertible>' ),
				array( array( 'a' ), '<not_convertible>' ),
			)
		);
	}

	/**
	 * Numbers: integers and decimals, numeric text converted; `0` kept; anything else refused.
	 */
	public function test_number() {
		$this->check(
			'number',
			array(
				array( 0, 0 ),
				array( 15, 15 ),
				array( -3, -3 ),
				array( 1.5, 1.5 ),
				array( '0', 0 ),
				array( '42', 42 ),
				array( ' 42 ', 42 ),
				array( '-7', -7 ),
				array( '3.25', 3.25 ),
				array( '007', 7 ),
				array( '1e3', 1000.0 ),
				array( '15,50', '<not_convertible>' ),
				array( '12 euro', '<not_convertible>' ),
				array( '1e999', '<not_convertible>' ),
				array( true, '<not_convertible>' ),
				array( array( 1 ), '<not_convertible>' ),
				array( '', '<absent>' ),
				array( array(), '<absent>' ),
			)
		);
	}

	/**
	 * Booleans: real booleans, `1`/`0`, and the usual words in any case; `false` kept.
	 */
	public function test_boolean() {
		$this->check(
			'boolean',
			array(
				array( true, true ),
				array( false, false ),
				array( 1, true ),
				array( 0, false ),
				array( '1', true ),
				array( '0', false ),
				array( 'TRUE', true ),
				array( 'false', false ),
				array( ' Yes ', true ),
				array( 'no', false ),
				array( 'on', true ),
				array( 'Off', false ),
				array( 2, '<not_convertible>' ),
				array( 'maybe', '<not_convertible>' ),
				array( array( true ), '<not_convertible>' ),
				array( '', '<absent>' ),
			)
		);
	}

	/**
	 * Dates: date only kept, date and time in UTC ISO 8601, offsets converted to UTC, Unix timestamps.
	 */
	public function test_datetime() {
		$this->check(
			'datetime',
			array(
				array( '2026-07-01', '2026-07-01' ),
				array( '2026-07-01 08:30:00', '2026-07-01T08:30:00Z' ),
				array( '2026-07-01T08:30:00Z', '2026-07-01T08:30:00Z' ),
				array( '2026-07-01T10:30:00+02:00', '2026-07-01T08:30:00Z' ),
				array( '2026-03-29T03:30:00+02:00', '2026-03-29T01:30:00Z' ),
				array( '2026-12-31T23:30:00-05:00', '2027-01-01T04:30:00Z' ),
				array( 0, '1970-01-01T00:00:00Z' ),
				array( 1782894600, '2026-07-01T08:30:00Z' ),
				array( '2026-02-30', '<not_convertible>' ),
				array( '2026-07-01 25:00:00', '<not_convertible>' ),
				array( '0000-00-00 00:00:00', '<not_convertible>' ),
				array( '0000-00-00', '<not_convertible>' ),
				array( '01/07/2026', '<not_convertible>' ),
				array( 'tomorrow', '<not_convertible>' ),
				array( '1782894600', '<not_convertible>' ),
				array( true, '<not_convertible>' ),
				array( 1.5, '<not_convertible>' ),
				array( '', '<absent>' ),
			)
		);
	}

	/**
	 * URLs: absolute http and https; root-relative paths made absolute with the origin of the site; any
	 * other scheme or relative address refused.
	 */
	public function test_url() {
		$this->check(
			'url',
			array(
				array( 'https://example.org/a/b?c=1#d', 'https://example.org/a/b?c=1#d' ),
				array( 'http://other.test/x', 'http://other.test/x' ),
				array( '  https://example.org/a  ', 'https://example.org/a' ),
				array( '/wp-content/uploads/a.jpg', 'https://example.org/wp-content/uploads/a.jpg' ),
				array( '//cdn.test/a.js', '<not_convertible>' ),
				array( 'javascript:alert(1)', '<not_convertible>' ),
				array( 'mailto:a@example.org', '<not_convertible>' ),
				array( 'ftp://example.org/a', '<not_convertible>' ),
				array( 'images/a.jpg', '<not_convertible>' ),
				array( 'https://exa mple.org', '<not_convertible>' ),
				array( 5, '<not_convertible>' ),
				array( '', '<absent>' ),
			)
		);
	}

	/**
	 * A root-relative path cannot be made absolute without the origin of the site.
	 */
	public function test_url_without_origin() {
		$result = Normalizer::normalize( '/a.jpg', 'url', '' );

		$this->assertFalse( $result['present'] );
		$this->assertSame( 'not_convertible', $result['error'] );
	}

	/**
	 * Lists: a list of plain values as it is, a plain value becomes a list of one; maps and nested lists refused.
	 */
	public function test_list() {
		$this->check(
			'list',
			array(
				array( array( 'Arts, culture', 'News' ), array( 'Arts, culture', 'News' ) ),
				array( array( 1, false, 'x' ), array( 1, false, 'x' ) ),
				array( 'One', array( 'One' ) ),
				array( 0, array( 0 ) ),
				array( array( 'a' => 'x' ), '<not_convertible>' ),
				array( array( array( 'x' ) ), '<not_convertible>' ),
				array( array( null ), '<not_convertible>' ),
				array( array(), array() ),
				array( '', array() ),
			)
		);
	}

	/**
	 * An unknown type, or `auto` that was not resolved, is a mistake of the caller: the value is refused.
	 */
	public function test_unknown_types_are_refused() {
		$this->assertSame( '<not_convertible>', $this->out( 'x', 'auto' ) );
		$this->assertSame( '<not_convertible>', $this->out( 'x', 'date' ) );
	}

	/**
	 * The type of a row with `auto`: the common declared type of its sources, otherwise text; an unknown
	 * declared type counts as text.
	 */
	public function test_common_type() {
		$this->assertSame( 'datetime', Normalizer::common_type( array( 'datetime', 'datetime' ) ) );
		$this->assertSame( 'number', Normalizer::common_type( array( 'number' ) ) );
		$this->assertSame( 'string', Normalizer::common_type( array( 'html', 'string' ) ) );
		$this->assertSame( 'string', Normalizer::common_type( array( 'number', null ) ) );
		$this->assertSame( 'string', Normalizer::common_type( array( null ) ) );
		$this->assertSame( 'string', Normalizer::common_type( array() ) );
	}

	/**
	 * The type of a fixed value: its JSON type.
	 */
	public function test_type_of_a_fixed_value() {
		$this->assertSame( 'string', Normalizer::type_of_value( 'it' ) );
		$this->assertSame( 'number', Normalizer::type_of_value( 0 ) );
		$this->assertSame( 'number', Normalizer::type_of_value( 2.5 ) );
		$this->assertSame( 'boolean', Normalizer::type_of_value( false ) );
	}
}

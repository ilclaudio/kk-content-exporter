<?php
/**
 * Unit tests of the configuration meta of a feed, with WordPress mocked by Brain Monkey.
 *
 * @package KKCE
 */

use Brain\Monkey\Functions;
use KKCE\Feed_Meta;

/**
 * Registration, sanitizers and readers of the post meta of a `kkce_feed`.
 */
class KKCE_Feed_Meta_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * register() registers the five configuration meta of the feed, none of them in REST.
	 */
	public function test_register_registers_the_five_meta_hidden_from_rest() {
		$registered = array();

		Functions\expect( 'register_post_meta' )
			->times( 5 )
			->andReturnUsing(
				static function ( $post_type, $key, $args ) use ( &$registered ) {
					$registered[ $key ] = array( $post_type, $args );
					return true;
				}
			);

		Feed_Meta::register();

		$this->assertSame(
			array( '_kkce_post_type', '_kkce_mapping', '_kkce_access', '_kkce_active', '_kkce_per_page' ),
			array_keys( $registered )
		);
		foreach ( $registered as $key => $entry ) {
			$this->assertSame( 'kkce_feed', $entry[0] );
			$this->assertFalse( $entry[1]['show_in_rest'] );
			$this->assertSame( '_kkce_post_type' !== $key, $entry[1]['single'], $key );
			$this->assertIsCallable( $entry[1]['sanitize_callback'] );
			$this->assertIsCallable( $entry[1]['auth_callback'] );
		}
		$this->assertArrayNotHasKey( 'default', $registered['_kkce_post_type'][1], 'A default would give a list with an empty post type.' );
		$this->assertSame( '', $registered['_kkce_mapping'][1]['default'] );
		$this->assertSame( 'public', $registered['_kkce_access'][1]['default'] );
		$this->assertFalse( $registered['_kkce_active'][1]['default'] );
		$this->assertSame( 50, $registered['_kkce_per_page'][1]['default'] );
	}

	/**
	 * The access is either `public` or `authenticated`; anything else falls back to `public`.
	 *
	 * @dataProvider access_provider
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $expected Expected access.
	 */
	public function test_sanitize_access( $value, string $expected ) {
		$this->assertSame( $expected, Feed_Meta::sanitize_access( $value ) );
	}

	/**
	 * Data for test_sanitize_access().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function access_provider(): array {
		return array(
			'public'        => array( 'public', 'public' ),
			'authenticated' => array( 'authenticated', 'authenticated' ),
			'unknown'       => array( 'private', 'authenticated' ),
			'empty'         => array( '', 'authenticated' ),
			'wrong case'    => array( 'Public', 'authenticated' ),
			'trailing space' => array( 'public ', 'authenticated' ),
			'array'         => array( array( 'public' ), 'authenticated' ),
			'null'          => array( null, 'authenticated' ),
		);
	}

	/**
	 * The active flag is a strict boolean; only clear truthy values enable the feed.
	 *
	 * @dataProvider active_provider
	 *
	 * @param mixed $value    Raw value.
	 * @param bool  $expected Expected flag.
	 */
	public function test_sanitize_active( $value, bool $expected ) {
		$this->assertSame( $expected, Feed_Meta::sanitize_active( $value ) );
	}

	/**
	 * Data for test_sanitize_active().
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public function active_provider(): array {
		return array(
			'true'          => array( true, true ),
			'string one'    => array( '1', true ),
			'int one'       => array( 1, true ),
			'false'         => array( false, false ),
			'string zero'   => array( '0', false ),
			'empty string'  => array( '', false ),
			'null'          => array( null, false ),
			'random string' => array( 'yes please', false ),
			'array'         => array( array( 1 ), false ),
		);
	}

	/**
	 * The items per page is an integer of at least 1; anything else falls back to the default of 50.
	 *
	 * @dataProvider per_page_provider
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $expected Expected number.
	 */
	public function test_sanitize_per_page( $value, int $expected ) {
		$this->assertSame( $expected, Feed_Meta::sanitize_per_page( $value ) );
	}

	/**
	 * Data for test_sanitize_per_page().
	 *
	 * @return array<string, array{0: mixed, 1: int}>
	 */
	public function per_page_provider(): array {
		return array(
			'integer'        => array( 25, 25 ),
			'numeric string' => array( '25', 25 ),
			'one'            => array( 1, 1 ),
			'large'          => array( 5000, 5000 ),
			'zero'           => array( 0, 50 ),
			'negative'       => array( -5, 50 ),
			'text'           => array( 'many', 50 ),
			'float string'   => array( '2.5', 50 ),
			'empty'          => array( '', 50 ),
			'null'           => array( null, 50 ),
			'array'          => array( array( 10 ), 50 ),
		);
	}

	/**
	 * The source post type is normalized to a lowercase key of at most 20 characters.
	 *
	 * @dataProvider post_type_provider
	 *
	 * @param mixed  $value    Raw value.
	 * @param string $expected Expected key.
	 */
	public function test_sanitize_post_type( $value, string $expected ) {
		$this->assertSame( $expected, Feed_Meta::sanitize_post_type( $value ) );
	}

	/**
	 * Data for test_sanitize_post_type().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function post_type_provider(): array {
		return array(
			'plain'         => array( 'post', 'post' ),
			'with dash'     => array( 'my-type_2', 'my-type_2' ),
			'uppercase'     => array( 'Page', 'page' ),
			'markup'        => array( '<b>post</b>', 'bpostb' ),
			'spaces'        => array( ' po st ', 'post' ),
			'too long'      => array( str_repeat( 'a', 30 ), str_repeat( 'a', 20 ) ),
			'empty'         => array( '', '' ),
			'null'          => array( null, '' ),
			'array'         => array( array( 'post' ), '' ),
		);
	}

	/**
	 * A list of source post types is normalized value by value, without empty values or duplicates,
	 * as a list in the original order.
	 *
	 * @dataProvider post_types_provider
	 *
	 * @param mixed        $value    Raw value.
	 * @param list<string> $expected Expected list.
	 */
	public function test_sanitize_post_types( $value, array $expected ) {
		$this->assertSame( $expected, Feed_Meta::sanitize_post_types( $value ) );
	}

	/**
	 * Data for test_sanitize_post_types().
	 *
	 * @return array<string, array{0: mixed, 1: list<string>}>
	 */
	public function post_types_provider(): array {
		return array(
			'one'                   => array( array( 'post' ), array( 'post' ) ),
			'several in order'      => array( array( 'page', 'post', 'event' ), array( 'page', 'post', 'event' ) ),
			'duplicates'            => array( array( 'post', 'page', 'post' ), array( 'post', 'page' ) ),
			'same after normalizing' => array( array( 'Page', 'page' ), array( 'page' ) ),
			'empty values dropped'  => array( array( '', 'post', '<>' ), array( 'post' ) ),
			'non strings dropped'   => array( array( null, 3, array( 'page' ), 'post' ), array( 'post' ) ),
			'keys renumbered'       => array( array( 5 => 'post', 'x' => 'page' ), array( 'post', 'page' ) ),
			'empty list'            => array( array(), array() ),
			'string not a list'     => array( 'post', array() ),
			'null'                  => array( null, array() ),
		);
	}

	/**
	 * `wp_json_encode()` is the only WordPress function of the mapping sanitizer.
	 */
	private function use_json_encode(): void {
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value, int $flags = 0 ) => json_encode( $value, $flags )
		);
	}

	/**
	 * A valid mapping is saved in its canonical form, as JSON text.
	 */
	public function test_sanitize_mapping_saves_the_canonical_form() {
		$this->use_json_encode();

		$saved = Feed_Meta::sanitize_mapping( '{"rows":[{"kind":"fixed","key":"lang","values":{"*":"it"}}],"version":1}' );

		$this->assertSame(
			'{"version":1,"rows":[{"key":"lang","kind":"fixed","type":"auto","missing":"omit","values":{"*":"it"}}]}',
			$saved
		);
		$this->assertSame( $saved, Feed_Meta::sanitize_mapping( $saved ), 'The canonical form does not change when sanitized again.' );
	}

	/**
	 * Accents, quotes, slashes and backslashes survive the sanitizer.
	 */
	public function test_sanitize_mapping_keeps_special_characters() {
		$this->use_json_encode();
		$text = "caff\u{e8} \"x\" a/b c\\d";
		$json = (string) json_encode( array( 'version' => 1, 'rows' => array( array( 'key' => 'k', 'kind' => 'fixed', 'values' => array( '*' => $text ) ) ) ) );

		$decoded = json_decode( Feed_Meta::sanitize_mapping( $json ), true );

		$this->assertSame( $text, $decoded['rows'][0]['values']['*'] );
	}

	/**
	 * Anything that is not a valid mapping is saved as an empty string (no mapping), never half-saved.
	 *
	 * @dataProvider invalid_mapping_provider
	 *
	 * @param mixed $value Raw value.
	 */
	public function test_sanitize_mapping_gives_an_empty_string_for_an_invalid_mapping( $value ) {
		$this->use_json_encode();

		$this->assertSame( '', Feed_Meta::sanitize_mapping( $value ) );
	}

	/**
	 * Data for test_sanitize_mapping_gives_an_empty_string_for_an_invalid_mapping().
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public function invalid_mapping_provider(): array {
		return array(
			'empty'           => array( '' ),
			'damaged json'    => array( '{"version":1,' ),
			'array'           => array( array( 'version' => 1, 'rows' => array() ) ),
			'null'            => array( null ),
			'unknown version' => array( '{"version":2,"rows":[]}' ),
			'unknown kind'    => array( '{"version":1,"rows":[{"key":"a","kind":"template"}]}' ),
			'duplicate key'   => array( '{"version":1,"rows":[{"key":"a","kind":"fixed","values":{"*":1}},{"key":"a","kind":"fixed","values":{"*":2}}]}' ),
			'unconfirmed sensitive source' => array( '{"version":1,"rows":[{"key":"a","kind":"field","sources":{"*":["meta.api_key"]}}]}' ),
		);
	}

	/**
	 * The size limit applies to the stored text too: a mapping that grows above the limit once encoded
	 * (accents become `\uXXXX`) is not stored, so it can never be read back as an empty mapping.
	 */
	public function test_sanitize_mapping_refuses_a_text_that_grows_above_the_limit() {
		$this->use_json_encode();
		$rows = array();
		for ( $i = 1; $i <= 100; $i++ ) {
			$rows[] = array( 'key' => 'k' . $i, 'kind' => 'fixed', 'values' => array( '*' => str_repeat( "\u{e8}", 500 ) ) );
		}
		$json = (string) json_encode( array( 'version' => 1, 'rows' => $rows ), JSON_UNESCAPED_UNICODE );
		$this->assertLessThan( \KKCE\Output\Mapping::MAX_BYTES, strlen( $json ), 'The submitted text is within the limit.' );

		$this->assertSame( '', Feed_Meta::sanitize_mapping( $json ) );
	}
}

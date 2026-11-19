<?php
/**
 * Unit tests of the rules on post meta keys (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Sources\Meta_Keys;

/**
 * Which meta keys a mapping can read, and how a registered type becomes a declared type.
 */
class KKCE_Meta_Keys_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Keys of WordPress, of ACF and of this plugin are never offered or accepted.
	 *
	 * @dataProvider internal_provider
	 *
	 * @param string $key Meta key.
	 */
	public function test_internal_keys_are_not_selectable( string $key ) {
		$this->assertTrue( Meta_Keys::is_internal( $key ) );
		$this->assertFalse( Meta_Keys::is_selectable( $key ) );
	}

	/**
	 * Data for test_internal_keys_are_not_selectable().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function internal_provider(): array {
		return array(
			'edit lock'      => array( '_edit_lock' ),
			'edit last'      => array( '_edit_last' ),
			'wp page'        => array( '_wp_page_template' ),
			'wp old slug'    => array( '_wp_old_slug' ),
			'oembed'         => array( '_oembed_1a2b3c' ),
			'thumbnail id'   => array( '_thumbnail_id' ),
			'this plugin'    => array( '_kkce_mapping' ),
		);
	}

	/**
	 * Ordinary keys are selectable, with or without a leading underscore; only the underscore makes them hidden.
	 *
	 * @dataProvider selectable_provider
	 *
	 * @param string $key    Meta key.
	 * @param bool   $hidden Whether the key starts with an underscore.
	 */
	public function test_ordinary_keys_are_selectable( string $key, bool $hidden ) {
		$this->assertFalse( Meta_Keys::is_internal( $key ) );
		$this->assertTrue( Meta_Keys::is_selectable( $key ) );
		$this->assertSame( $hidden, Meta_Keys::is_hidden( $key ) );
	}

	/**
	 * Data for test_ordinary_keys_are_selectable().
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public function selectable_provider(): array {
		return array(
			'plain'           => array( 'place', false ),
			'with digits'     => array( 'price_2', false ),
			'camel case'      => array( 'startDate', false ),
			'hidden'          => array( '_price', true ),
			'dot and colon'   => array( 'acme.venue:name', false ),
			'prefix lookalike' => array( 'edit_notes', false ),
			'wp lookalike'    => array( 'wpml_language', false ),
		);
	}

	/**
	 * A key must have the form a source identifier allows: 1 to 191 characters among letters, digits and `_ . : -`.
	 *
	 * @dataProvider invalid_provider
	 *
	 * @param string $key Meta key.
	 */
	public function test_malformed_keys_are_not_selectable( string $key ) {
		$this->assertFalse( Meta_Keys::is_selectable( $key ) );
	}

	/**
	 * Data for test_malformed_keys_are_not_selectable().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_provider(): array {
		return array(
			'empty'     => array( '' ),
			'space'     => array( 'two words' ),
			'slash'     => array( 'a/b' ),
			'accent'    => array( 'città' ),
			'newline'   => array( "a\nb" ),
			'too long'  => array( str_repeat( 'a', 192 ) ),
			'bracket'   => array( 'a[0]' ),
		);
	}

	/**
	 * The longest key allowed is 191 characters.
	 */
	public function test_the_longest_key_is_accepted() {
		$this->assertTrue( Meta_Keys::is_selectable( str_repeat( 'a', 191 ) ) );
	}

	/**
	 * The key of a source identifier of the meta group, or null for another group or a key not selectable.
	 */
	public function test_key_from_source() {
		$this->assertSame( 'place', Meta_Keys::key_from_source( 'meta.place' ) );
		$this->assertSame( '_price', Meta_Keys::key_from_source( 'meta._price' ) );
		$this->assertSame( 'a.b', Meta_Keys::key_from_source( 'meta.a.b' ), 'Only the first dot splits the group.' );
		$this->assertNull( Meta_Keys::key_from_source( 'core.title' ) );
		$this->assertNull( Meta_Keys::key_from_source( 'meta._edit_lock' ) );
		$this->assertNull( Meta_Keys::key_from_source( 'meta.' ) );
		$this->assertNull( Meta_Keys::key_from_source( 'meta' ) );
	}

	/**
	 * A type given to `register_meta()` becomes a declared type; structures are not offered.
	 *
	 * @dataProvider type_provider
	 *
	 * @param mixed       $registered Type of the registration.
	 * @param string|null $expected   Declared type, or null when the key cannot be exported.
	 */
	public function test_registered_types( $registered, ?string $expected ) {
		$this->assertSame( $expected, Meta_Keys::declared_type( $registered ) );
	}

	/**
	 * Data for test_registered_types().
	 *
	 * @return array<string, array{0: mixed, 1: string|null}>
	 */
	public function type_provider(): array {
		return array(
			'string'  => array( 'string', 'string' ),
			'integer' => array( 'integer', 'number' ),
			'number'  => array( 'number', 'number' ),
			'boolean' => array( 'boolean', 'boolean' ),
			'array'   => array( 'array', null ),
			'object'  => array( 'object', null ),
			'unknown' => array( 'date', 'string' ),
			'missing' => array( null, 'string' ),
			'not text' => array( 12, 'string' ),
		);
	}

	/**
	 * The query that samples the keys has one placeholder for each internal prefix and key: four prefixes and one
	 * key. Adding one to the lists means changing `Meta_Provider::sampled_keys()` in the same change.
	 */
	public function test_the_sampling_query_covers_every_internal_key() {
		$this->assertCount( 4, Meta_Keys::INTERNAL_PREFIXES );
		$this->assertCount( 1, Meta_Keys::INTERNAL_KEYS );
	}

	/**
	 * The unregistered keys are text, like the meta of a post saved by hand.
	 */
	public function test_unregistered_keys_are_text() {
		$this->assertSame( 'string', Meta_Keys::UNREGISTERED_TYPE );
	}
}

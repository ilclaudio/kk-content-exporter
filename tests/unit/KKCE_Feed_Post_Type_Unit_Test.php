<?php
/**
 * Unit tests of the feed post type wiring, with WordPress mocked by Brain Monkey.
 *
 * @package KKCE
 */

use Brain\Monkey\Functions;
use KKCE\Feed_Post_Type;

/**
 * Registration of the `kkce_feed` post type.
 */
class KKCE_Feed_Post_Type_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * register() registers the `kkce_feed` post type once, with the private arguments.
	 */
	public function test_register_calls_register_post_type_with_private_arguments() {
		Functions\expect( 'register_post_type' )
			->once()
			->with(
				'kkce_feed',
				\Mockery::on(
					static function ( $args ) {
						return is_array( $args )
							&& false === $args['public']
							&& false === $args['show_in_rest']
							&& false === $args['rewrite']
							&& true === $args['map_meta_cap']
							&& array( 'title' ) === $args['supports'];
					}
				)
			);

		Feed_Post_Type::register();
	}

	/**
	 * Every capability of the post type requires the administration capability.
	 */
	public function test_capabilities_are_all_mapped_to_manage_options() {
		$capabilities = Feed_Post_Type::capabilities();

		$this->assertNotEmpty( $capabilities );
		foreach ( $capabilities as $primitive ) {
			$this->assertSame( 'manage_options', $primitive );
		}
		$this->assertArrayHasKey( 'edit_posts', $capabilities );
		$this->assertArrayHasKey( 'create_posts', $capabilities );
		$this->assertArrayHasKey( 'delete_others_posts', $capabilities );
	}

	/**
	 * register_meta() registers the four configuration meta of the feed, none of them in REST.
	 */
	public function test_register_meta_registers_the_four_meta_hidden_from_rest() {
		$registered = array();

		Functions\expect( 'register_post_meta' )
			->times( 4 )
			->andReturnUsing(
				static function ( $post_type, $key, $args ) use ( &$registered ) {
					$registered[ $key ] = array( $post_type, $args );
					return true;
				}
			);

		Feed_Post_Type::register_meta();

		$this->assertSame(
			array( '_kkce_post_type', '_kkce_access', '_kkce_active', '_kkce_per_page' ),
			array_keys( $registered )
		);
		foreach ( $registered as $entry ) {
			$this->assertSame( 'kkce_feed', $entry[0] );
			$this->assertFalse( $entry[1]['show_in_rest'] );
			$this->assertTrue( $entry[1]['single'] );
			$this->assertIsCallable( $entry[1]['sanitize_callback'] );
			$this->assertIsCallable( $entry[1]['auth_callback'] );
		}
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
		$this->assertSame( $expected, Feed_Post_Type::sanitize_access( $value ) );
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
			'unknown'       => array( 'private', 'public' ),
			'empty'         => array( '', 'public' ),
			'wrong case'    => array( 'Authenticated', 'public' ),
			'array'         => array( array( 'authenticated' ), 'public' ),
			'null'          => array( null, 'public' ),
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
		$this->assertSame( $expected, Feed_Post_Type::sanitize_active( $value ) );
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
		$this->assertSame( $expected, Feed_Post_Type::sanitize_per_page( $value ) );
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
		$this->assertSame( $expected, Feed_Post_Type::sanitize_post_type( $value ) );
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
}

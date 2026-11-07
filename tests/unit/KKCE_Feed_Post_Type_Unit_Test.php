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
}

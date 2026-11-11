<?php
/**
 * Unit tests of the capabilities of the feed post type (pure logic, no mocks); the registration itself is
 * checked by KKCE_Feed_Post_Type_Integration_Test.
 *
 * @package KKCE
 */

use KKCE\Feed_Post_Type;

/**
 * Capabilities of the `kkce_feed` post type.
 */
class KKCE_Feed_Post_Type_Unit_Test extends KKCE_Unit_Test_Case {

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

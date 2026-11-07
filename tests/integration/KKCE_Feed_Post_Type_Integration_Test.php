<?php
/**
 * Integration tests of the private feed post type `kkce_feed`.
 *
 * @package KKCE
 */

use KKCE\Feed_Post_Type;

/**
 * The feed post type is registered, private, not exposed in REST and restricted to administrators.
 */
class KKCE_Feed_Post_Type_Integration_Test extends WP_UnitTestCase {

	/**
	 * The post type exists with the expected name.
	 */
	public function test_feed_post_type_is_registered() {
		$this->assertSame( 'kkce_feed', Feed_Post_Type::POST_TYPE );
		$this->assertTrue( post_type_exists( 'kkce_feed' ) );
	}

	/**
	 * The post type is private: no front end, no search, no REST, not in the public post type list.
	 */
	public function test_feed_post_type_is_private_and_not_exposed() {
		$type = get_post_type_object( 'kkce_feed' );

		$this->assertNotNull( $type );
		$this->assertFalse( $type->public );
		$this->assertFalse( $type->publicly_queryable );
		$this->assertTrue( $type->exclude_from_search );
		$this->assertFalse( $type->show_in_rest );
		$this->assertFalse( $type->has_archive );
		$this->assertFalse( $type->rewrite );
		$this->assertNotContains( 'kkce_feed', get_post_types( array( 'public' => true ) ) );
	}

	/**
	 * The REST API of WordPress offers no route for the feed post type.
	 */
	public function test_feed_post_type_has_no_rest_route() {
		$routes = rest_get_server()->get_routes();

		foreach ( array_keys( $routes ) as $route ) {
			$this->assertStringNotContainsString( 'kkce_feed', $route );
		}
	}

	/**
	 * The editor of a feed has a title and nothing else.
	 */
	public function test_feed_post_type_supports_only_the_title() {
		$this->assertTrue( post_type_supports( 'kkce_feed', 'title' ) );
		$this->assertFalse( post_type_supports( 'kkce_feed', 'editor' ) );
		$this->assertFalse( post_type_supports( 'kkce_feed', 'thumbnail' ) );
	}

	/**
	 * Only administrators can create and edit feeds.
	 */
	public function test_only_administrators_manage_feeds() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$feed   = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		$type   = get_post_type_object( 'kkce_feed' );

		$this->assertTrue( user_can( $admin, 'edit_post', $feed ) );
		$this->assertTrue( user_can( $admin, $type->cap->create_posts ) );
		$this->assertFalse( user_can( $editor, 'edit_post', $feed ) );
		$this->assertFalse( user_can( $editor, 'delete_post', $feed ) );
		$this->assertFalse( user_can( $editor, $type->cap->create_posts ) );
		$this->assertFalse( user_can( $editor, $type->cap->edit_posts ) );
	}
}

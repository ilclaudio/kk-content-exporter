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
	 * The WordPress test case unregisters every meta key before each test, so the configuration
	 * meta are registered again here with the same method that `init` calls.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Post_Type::register_meta();
	}

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

	/**
	 * The four configuration meta are registered for the feed post type and hidden from REST.
	 */
	public function test_configuration_meta_are_registered_and_hidden_from_rest() {
		$registered = get_registered_meta_keys( 'post', 'kkce_feed' );

		foreach ( array( '_kkce_post_type', '_kkce_access', '_kkce_active', '_kkce_per_page' ) as $key ) {
			$this->assertArrayHasKey( $key, $registered );
			$this->assertFalse( $registered[ $key ]['show_in_rest'] );
			$this->assertTrue( $registered[ $key ]['single'] );
		}
		$this->assertArrayNotHasKey( '_kkce_access', get_registered_meta_keys( 'post', 'post' ) );
	}

	/**
	 * A feed without saved meta is safe: public access, disabled, 50 items, no source post type.
	 */
	public function test_a_new_feed_has_safe_defaults() {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		$this->assertSame( '', get_post_meta( $feed, '_kkce_post_type', true ) );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertFalse( get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertSame( 50, get_post_meta( $feed, '_kkce_per_page', true ) );
	}

	/**
	 * Values are sanitized when saved, whatever the caller sends.
	 *
	 * WordPress gives stored values back as strings (`'1'` or an empty string for a boolean, `'20'`
	 * for an integer): readers convert them with `sanitize_active()` and `sanitize_per_page()`.
	 */
	public function test_meta_values_are_sanitized_on_save() {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		update_post_meta( $feed, '_kkce_post_type', '<b>Page</b>' );
		update_post_meta( $feed, '_kkce_access', 'everyone' );
		update_post_meta( $feed, '_kkce_active', 'yes please' );
		update_post_meta( $feed, '_kkce_per_page', '-3' );

		$this->assertSame( 'bpageb', get_post_meta( $feed, '_kkce_post_type', true ) );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertSame( '', get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertFalse( Feed_Post_Type::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( '50', get_post_meta( $feed, '_kkce_per_page', true ) );

		update_post_meta( $feed, '_kkce_access', 'authenticated' );
		update_post_meta( $feed, '_kkce_active', '1' );
		update_post_meta( $feed, '_kkce_per_page', '20' );

		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertSame( '1', get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertTrue( Feed_Post_Type::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( '20', get_post_meta( $feed, '_kkce_per_page', true ) );
	}

	/**
	 * Only administrators can edit the configuration meta of a feed.
	 */
	public function test_only_administrators_can_edit_the_configuration_meta() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$feed   = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		foreach ( array( '_kkce_post_type', '_kkce_access', '_kkce_active', '_kkce_per_page' ) as $key ) {
			$this->assertTrue( user_can( $admin, 'edit_post_meta', $feed, $key ), $key );
			$this->assertFalse( user_can( $editor, 'edit_post_meta', $feed, $key ), $key );
		}
	}
}

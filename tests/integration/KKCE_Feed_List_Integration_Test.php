<?php
/**
 * Integration tests of the list of the feeds in the administration.
 *
 * @package KKCE
 */

use KKCE\Feed_Post_Type;

/**
 * Columns of the `kkce_feed` list: slug, source post type, access and status, always escaped.
 */
class KKCE_Feed_List_Integration_Test extends WP_UnitTestCase {

	/**
	 * The WordPress test case unregisters every meta key before each test.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Post_Type::register_meta();
	}

	/**
	 * Prints a column of a feed through the real WordPress hook and returns the output.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Feed ID.
	 * @return string
	 */
	private function cell( string $column, int $post_id ): string {
		ob_start();
		do_action( 'manage_kkce_feed_posts_custom_column', $column, $post_id );

		return (string) ob_get_clean();
	}

	/**
	 * Creates a published feed.
	 *
	 * @param array<string, string> $meta Meta key => value.
	 * @param string                $slug Slug of the feed.
	 * @return int
	 */
	private function feed( array $meta = array(), string $slug = 'news' ): int {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kkce_feed',
				'post_status' => 'publish',
				'post_title'  => 'News feed',
				'post_name'   => $slug,
			)
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * The list screen of the post type gets the four feed columns and loses the date.
	 */
	public function test_the_list_has_the_feed_columns() {
		$columns = apply_filters(
			'manage_kkce_feed_posts_columns',
			array(
				'cb'    => 'cb',
				'title' => 'Title',
				'date'  => 'Date',
			)
		);

		$this->assertSame(
			array( 'cb', 'title', 'kkce_slug', 'kkce_source', 'kkce_access', 'kkce_status' ),
			array_keys( $columns )
		);
	}

	/**
	 * A configured feed shows its slug, source, access and status.
	 */
	public function test_a_configured_feed_is_described_in_the_columns() {
		$id = $this->feed(
			array(
				'_kkce_post_type' => 'page',
				'_kkce_access'    => 'authenticated',
				'_kkce_active'    => '1',
			)
		);

		$this->assertSame( 'news', $this->cell( 'kkce_slug', $id ) );
		$this->assertSame( esc_html( get_post_type_object( 'page' )->label ), $this->cell( 'kkce_source', $id ) );
		$this->assertSame( 'Authenticated', $this->cell( 'kkce_access', $id ) );
		$this->assertSame( 'Active', $this->cell( 'kkce_status', $id ) );
	}

	/**
	 * A feed without configuration is public, disabled and has no source, with a screen reader text.
	 */
	public function test_a_new_feed_shows_the_safe_defaults() {
		$id = $this->feed();

		$this->assertSame( 'Public', $this->cell( 'kkce_access', $id ) );
		$this->assertSame( 'Disabled', $this->cell( 'kkce_status', $id ) );
		$this->assertStringContainsString( 'screen-reader-text', $this->cell( 'kkce_source', $id ) );
		$this->assertStringContainsString( 'Not set', $this->cell( 'kkce_source', $id ) );
	}

	/**
	 * A draft has no slug yet: the cell says so instead of staying empty.
	 */
	public function test_a_feed_without_slug_shows_the_empty_marker() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'kkce_feed',
				'post_status' => 'draft',
			)
		);

		$this->assertStringContainsString( 'Not set', $this->cell( 'kkce_slug', $id ) );
	}

	/**
	 * A source post type that no longer exists is shown by its key.
	 */
	public function test_a_missing_source_post_type_is_shown_by_key() {
		$id = $this->feed( array( '_kkce_post_type' => 'removed-type' ) );

		$this->assertSame( 'removed-type', $this->cell( 'kkce_source', $id ) );
	}

	/**
	 * A corrupted source post type stored in the database never reaches the page as markup.
	 */
	public function test_a_corrupted_source_post_type_is_neutralized() {
		global $wpdb;

		$id = $this->feed();
		// Simulates a corrupted database: the sanitizers of the write path are bypassed on purpose.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => '_kkce_post_type', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '<script>alert(1)</script>', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertStringNotContainsString( '<', $this->cell( 'kkce_source', $id ) );
	}

	/**
	 * A slug stored with markup is escaped when printed (the slug is not sanitized by the plugin).
	 */
	public function test_a_slug_with_markup_is_escaped() {
		global $wpdb;

		$id = $this->feed();
		$wpdb->update( $wpdb->posts, array( 'post_name' => '<script>alert(1)</script>' ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		$output = $this->cell( 'kkce_slug', $id );

		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertSame( '&lt;script&gt;alert(1)&lt;/script&gt;', $output );
	}

	/**
	 * The name of a source post type is escaped when printed.
	 */
	public function test_a_post_type_label_with_markup_is_escaped() {
		register_post_type(
			'evil-type',
			array(
				'public' => true,
				'label'  => '<b>Evil</b>',
			)
		);
		$id = $this->feed( array( '_kkce_post_type' => 'evil-type' ) );

		$this->assertSame( '&lt;b&gt;Evil&lt;/b&gt;', $this->cell( 'kkce_source', $id ) );

		unregister_post_type( 'evil-type' );
	}

	/**
	 * A stored access or status that is not a known value falls back to the safe one.
	 */
	public function test_unknown_stored_values_fall_back_to_the_safe_ones() {
		global $wpdb;

		$id = $this->feed();
		foreach ( array(
			'_kkce_access' => 'everyone',
			'_kkce_active' => 'yes please',
		) as $key => $value ) {
			$wpdb->insert(
				$wpdb->postmeta,
				array(
					'post_id'    => $id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}
		wp_cache_delete( $id, 'post_meta' );

		$this->assertSame( 'Public', $this->cell( 'kkce_access', $id ) );
		$this->assertSame( 'Disabled', $this->cell( 'kkce_status', $id ) );
	}

	/**
	 * Quick edit is removed from the feeds, because it could change the slug without validation.
	 */
	public function test_quick_edit_is_removed_for_feeds_only() {
		$actions = array(
			'edit'                 => '<a href="#">Edit</a>',
			'inline hide-if-no-js' => '<button>Quick Edit</button>',
		);

		$feed = apply_filters( 'post_row_actions', $actions, get_post( $this->feed() ) );
		$post = apply_filters( 'post_row_actions', $actions, get_post( self::factory()->post->create() ) );

		$this->assertArrayNotHasKey( 'inline hide-if-no-js', $feed );
		$this->assertArrayHasKey( 'edit', $feed );
		$this->assertSame( $actions, $post );
	}

	/**
	 * Other columns print nothing.
	 */
	public function test_other_columns_print_nothing() {
		$this->assertSame( '', $this->cell( 'comments', $this->feed() ) );
	}
}

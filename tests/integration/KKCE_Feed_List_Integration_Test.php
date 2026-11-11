<?php
/**
 * Integration tests of the list of the feeds in the administration.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;

/**
 * Columns of the `kkce_feed` list: slug, source post type, access and status, always escaped.
 */
class KKCE_Feed_List_Integration_Test extends WP_UnitTestCase {

	/**
	 * The WordPress test case unregisters every meta key before each test.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
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
	 * A configured feed shows its slug, source, access and status.
	 */
	public function test_a_configured_feed_is_described_in_the_columns() {
		$this->set_permalink_structure( '/%postname%/' );
		$id = $this->feed(
			array(
				'_kkce_post_type' => 'page',
				'_kkce_access'    => 'authenticated',
				'_kkce_active'    => '1',
			)
		);

		$this->assertStringContainsString( '<code>/wp-json/kkce/v1/news</code>', $this->cell( 'kkce_endpoint', $id ) );
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

		$this->assertStringContainsString( 'Not set', $this->cell( 'kkce_endpoint', $id ) );
	}

	/**
	 * A feed with several post types lists their labels, separated by commas, in the saved order.
	 */
	public function test_several_post_types_are_listed_in_the_source_column() {
		$id = $this->feed();
		add_post_meta( $id, '_kkce_post_type', 'page' );
		add_post_meta( $id, '_kkce_post_type', 'removed-type' );
		add_post_meta( $id, '_kkce_post_type', 'post' );

		$expected = esc_html( get_post_type_object( 'page' )->label . ', removed-type, ' . get_post_type_object( 'post' )->label );

		$this->assertSame( $expected, $this->cell( 'kkce_source', $id ) );
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
				'meta_key'   => '_kkce_post_type',
				'meta_value' => '<script>alert(1)</script>',
			)
		);
		wp_cache_delete( $id, 'post_meta' );

		$this->assertStringNotContainsString( '<', $this->cell( 'kkce_source', $id ) );
	}

	/**
	 * The endpoint column shows the relative address, with the absolute one in the title attribute.
	 */
	public function test_the_endpoint_is_the_relative_address_with_the_absolute_one_in_the_title() {
		$this->set_permalink_structure( '/%postname%/' );
		$id = $this->feed();

		$output = $this->cell( 'kkce_endpoint', $id );

		$this->assertSame( '<span title="' . esc_attr( rest_url( 'kkce/v1/news' ) ) . '"><code>/wp-json/kkce/v1/news</code></span>', $output );
	}

	/**
	 * With plain permalinks the relative address keeps the `rest_route` query argument.
	 */
	public function test_the_endpoint_follows_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$id = $this->feed();

		$this->assertStringContainsString( '<code>/index.php?rest_route=/kkce/v1/news</code>', $this->cell( 'kkce_endpoint', $id ) );
	}

	/**
	 * A slug that cannot serve a feed (markup, reserved) shows no address, only the empty marker, and
	 * nothing unescaped.
	 *
	 * @dataProvider unusable_slug_provider
	 *
	 * @param string $slug Stored slug.
	 */
	public function test_an_unusable_slug_shows_no_address( string $slug ) {
		global $wpdb;

		$id = $this->feed();
		$wpdb->update( $wpdb->posts, array( 'post_name' => $slug ), array( 'ID' => $id ) );
		clean_post_cache( $id );

		$output = $this->cell( 'kkce_endpoint', $id );

		$this->assertStringContainsString( 'Not set', $output );
		$this->assertStringNotContainsString( '<code>', $output );
		$this->assertStringNotContainsString( '<script>', $output );
	}

	/**
	 * Data for test_an_unusable_slug_shows_no_address().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function unusable_slug_provider(): array {
		return array(
			'markup'   => array( '<script>alert(1)</script>' ),
			'reserved' => array( 'feeds' ),
			'format'   => array( 'Bad Slug' ),
		);
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
					'meta_key'   => $key,
					'meta_value' => $value,
				)
			);
		}
		wp_cache_delete( $id, 'post_meta' );

		$this->assertSame( 'Authenticated', $this->cell( 'kkce_access', $id ), 'A damaged access fails closed.' );
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

}

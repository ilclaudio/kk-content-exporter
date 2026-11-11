<?php
/**
 * Integration tests of the configuration meta of a feed.
 *
 * @package KKCE
 */

use KKCE\Feed_Meta;

/**
 * The meta are registered, hidden from REST, sanitized on every write and editable by administrators only.
 */
class KKCE_Feed_Meta_Integration_Test extends WP_UnitTestCase {

	/**
	 * The WordPress test case unregisters every meta key before each test, so the configuration
	 * meta are registered again here with the same method that `init` calls.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
	}

	/**
	 * The five configuration meta are registered for the feed post type and hidden from REST.
	 */
	public function test_configuration_meta_are_registered_and_hidden_from_rest() {
		$registered = get_registered_meta_keys( 'post', 'kkce_feed' );

		foreach ( array( '_kkce_post_type', '_kkce_mapping', '_kkce_access', '_kkce_active', '_kkce_per_page' ) as $key ) {
			$this->assertArrayHasKey( $key, $registered );
			$this->assertFalse( $registered[ $key ]['show_in_rest'] );
			$this->assertSame( '_kkce_post_type' !== $key, $registered[ $key ]['single'], $key );
		}
		$this->assertArrayNotHasKey( '_kkce_access', get_registered_meta_keys( 'post', 'post' ) );
	}

	/**
	 * A feed without saved meta is safe: public access, disabled, 50 items, no source post type.
	 */
	public function test_a_new_feed_has_safe_defaults() {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		$this->assertSame( array(), get_post_meta( $feed, '_kkce_post_type', false ) );
		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertFalse( get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertSame( 50, get_post_meta( $feed, '_kkce_per_page', true ) );
		$this->assertSame( '', get_post_meta( $feed, '_kkce_mapping', true ) );
		$this->assertSame( array( 'version' => 1, 'rows' => array() ), Feed_Meta::mapping( $feed ) );
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
		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ), 'An unknown access fails closed.' );
		$this->assertSame( '', get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( '50', get_post_meta( $feed, '_kkce_per_page', true ) );

		update_post_meta( $feed, '_kkce_access', 'authenticated' );
		update_post_meta( $feed, '_kkce_active', '1' );
		update_post_meta( $feed, '_kkce_per_page', '20' );

		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertSame( '1', get_post_meta( $feed, '_kkce_active', true ) );
		$this->assertTrue( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( '20', get_post_meta( $feed, '_kkce_per_page', true ) );
	}

	/**
	 * Every row of the source post types is sanitized on its own, and the reader gives a clean list
	 * without empty values or duplicates.
	 */
	public function test_post_type_rows_are_sanitized_and_read_as_a_list() {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		add_post_meta( $feed, '_kkce_post_type', 'Page' );
		add_post_meta( $feed, '_kkce_post_type', '<b>post</b>' );
		add_post_meta( $feed, '_kkce_post_type', 'page' );
		add_post_meta( $feed, '_kkce_post_type', '' );

		$this->assertSame( array( 'page', 'bpostb', 'page', '' ), get_post_meta( $feed, '_kkce_post_type', false ) );
		$this->assertSame( array( 'page', 'bpostb' ), Feed_Meta::post_types( $feed ) );
	}

	/**
	 * A feed saved with one row (the format of the first version, which could also be an empty string)
	 * is read as a list of one post type, or as an empty list.
	 */
	public function test_a_single_row_is_read_as_a_list_of_one() {
		$feed  = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		$empty = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		update_post_meta( $feed, '_kkce_post_type', 'page' );
		update_post_meta( $empty, '_kkce_post_type', '' );

		$this->assertSame( array( 'page' ), Feed_Meta::post_types( $feed ) );
		$this->assertSame( array(), Feed_Meta::post_types( $empty ) );
	}

	/**
	 * Feeds can be found by one of their post types with a meta query, one row per post type.
	 */
	public function test_feeds_can_be_found_by_post_type() {
		$both  = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		$pages = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		add_post_meta( $both, '_kkce_post_type', 'post' );
		add_post_meta( $both, '_kkce_post_type', 'page' );
		add_post_meta( $pages, '_kkce_post_type', 'page' );

		$find = static fn( string $type ): array => get_posts(
			array(
				'post_type'   => 'kkce_feed',
				'post_status' => 'any',
				'fields'      => 'ids',
				'orderby'     => 'ID',
				'order'       => 'ASC',
				'meta_query'  => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_kkce_post_type',
						'value' => $type,
					),
				),
			)
		);

		$this->assertSame( array( $both ), $find( 'post' ) );
		$this->assertSame( array( $both, $pages ), $find( 'page' ) );
		$this->assertSame( array(), $find( 'event' ) );
	}

	/**
	 * Only administrators can edit the configuration meta of a feed.
	 */
	public function test_only_administrators_can_edit_the_configuration_meta() {
		$admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$feed   = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		foreach ( array( '_kkce_post_type', '_kkce_mapping', '_kkce_access', '_kkce_active', '_kkce_per_page' ) as $key ) {
			$this->assertTrue( user_can( $admin, 'edit_post_meta', $feed, $key ), $key );
			$this->assertFalse( user_can( $editor, 'edit_post_meta', $feed, $key ), $key );
		}
	}

	/**
	 * A mapping with accents, quotes, slashes and backslashes is saved and read back without losing a
	 * character: WordPress removes backslashes from meta values, so the save goes through `wp_slash()`.
	 */
	public function test_a_mapping_is_saved_and_read_back_without_losses() {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		$text = "caff\u{e8} \"quoted\" a/b c\\d \\u00e8 \\\"";
		$json = wp_json_encode(
			array(
				'version' => 1,
				'rows'    => array(
					array(
						'key'     => 'when',
						'kind'    => 'field',
						'type'    => 'datetime',
						'sources' => array( 'event' => array( 'meta.when' ), '*' => array( 'core.title' ) ),
						'options' => array( 'date_format' => 'd/m/Y' ),
					),
					array(
						'key'    => 'note',
						'kind'   => 'fixed',
						'type'   => 'string',
						'values' => array( '*' => $text ),
					),
				),
			)
		);

		Feed_Meta::save_mapping( $feed, (string) $json );

		$mapping = Feed_Meta::mapping( $feed );
		$this->assertCount( 2, $mapping['rows'] );
		$this->assertSame( $text, $mapping['rows'][1]['values']['*'] );
		$this->assertSame( array( 'event' => array( 'meta.when' ), '*' => array( 'core.title' ) ), $mapping['rows'][0]['sources'] );
		$this->assertArrayNotHasKey( 'missing', $mapping['rows'][0], 'Rows have no missing-value policy.' );
		$this->assertSame( array( 'date_format' => 'd/m/Y' ), $mapping['rows'][0]['options'] );
		$stored = get_post_meta( $feed, '_kkce_mapping', true );
		$this->assertSame( $stored, Feed_Meta::sanitize_mapping( $stored ), 'The stored text is the canonical JSON.' );
		$this->assertMatchesRegularExpression( '/\A[\x20-\x7e]+\z/', $stored, 'The stored text is ASCII only.' );
	}

	/**
	 * An invalid mapping is stored as an empty string, whoever writes it, and leaves nothing half-saved.
	 *
	 * @dataProvider invalid_mapping_provider
	 *
	 * @param string $json Invalid mapping.
	 */
	public function test_an_invalid_mapping_is_not_stored( string $json ) {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );

		update_post_meta( $feed, '_kkce_mapping', wp_slash( $json ) );

		$this->assertSame( '', get_post_meta( $feed, '_kkce_mapping', true ) );
		$this->assertSame( array( 'version' => 1, 'rows' => array() ), Feed_Meta::mapping( $feed ) );
	}

	/**
	 * Data for test_an_invalid_mapping_is_not_stored().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_mapping_provider(): array {
		return array(
			'damaged json'    => array( '{"version":1,"rows":[' ),
			'unknown version' => array( '{"version":2,"rows":[]}' ),
			'unknown kind'    => array( '{"version":1,"rows":[{"key":"a","kind":"template"}]}' ),
			'bad key'         => array( '{"version":1,"rows":[{"key":"a b","kind":"fixed","values":{"*":1}}]}' ),
		);
	}

	/**
	 * A mapping stored outside the sanitizer (damaged database, direct write) is checked again when read:
	 * a damaged one gives an empty mapping, never a half-read one.
	 */
	public function test_a_damaged_stored_mapping_is_read_as_empty() {
		global $wpdb;

		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		// Simulates a value written outside the sanitizer: bypassed on purpose.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $feed,
				'meta_key'   => '_kkce_mapping', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => '{"version":1,"rows":[{"key":"a","kind":"expression"}]}', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $feed, 'post_meta' );

		$this->assertSame( array( 'version' => 1, 'rows' => array() ), Feed_Meta::mapping( $feed ) );
	}
}

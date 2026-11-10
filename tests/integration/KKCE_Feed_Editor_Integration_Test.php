<?php
/**
 * Integration tests of the feed editor: the meta box and the saving of a feed.
 *
 * @package KKCE
 */

use KKCE\Admin\Feed_Editor;
use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;

/**
 * Saving needs a valid nonce and the capability to edit the feed; invalid values are refused one by one.
 */
class KKCE_Feed_Editor_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator who edits the feeds.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Registers the meta again (the test case unregisters them) and signs in an administrator.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Cleans the request and the admin screen.
	 */
	public function tear_down() {
		wp_dequeue_script( 'kkce-feed-editor' );
		wp_deregister_script( 'kkce-feed-editor' );
		$_POST = array();
		unset( $GLOBALS['post'] );
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * Creates a draft feed, as WordPress does when the editor is opened.
	 *
	 * @param string $title Title of the feed.
	 * @return int
	 */
	private function feed( string $title = 'News feed' ): int {
		return self::factory()->post->create(
			array(
				'post_type'   => 'kkce_feed',
				'post_status' => 'draft',
				'post_title'  => $title,
			)
		);
	}

	/**
	 * Submits the editor form the way WordPress does: the fields and the nonce are in the request, then
	 * the feed is saved.
	 *
	 * @param int                   $feed_id Feed ID.
	 * @param array<string, mixed>  $fields  Values of the `kkce_feed` fields.
	 * @param array<string, mixed>  $options Keys: `nonce_for` (feed ID of the nonce, 0 for none), `title`.
	 */
	private function submit( int $feed_id, array $fields, array $options = array() ): void {
		$nonce_for = $options['nonce_for'] ?? $feed_id;

		$_POST = array( 'kkce_feed' => $fields );
		if ( $nonce_for ) {
			$_POST['kkce_feed_nonce'] = wp_create_nonce( 'kkce_save_feed_' . $nonce_for );
		}

		wp_update_post(
			wp_slash(
				array(
					'ID'          => $feed_id,
					'post_title'  => $options['title'] ?? get_the_title( $feed_id ),
					'post_status' => 'publish',
				)
			)
		);
	}

	/**
	 * Valid fields.
	 *
	 * @param array<string, mixed> $override Values to change.
	 * @return array<string, mixed>
	 */
	private function fields( array $override = array() ): array {
		return array_merge(
			array(
				'slug'       => 'news',
				'post_types' => array( 'page' ),
				'access'     => 'authenticated',
				'active'     => '1',
				'per_page'   => '20',
			),
			$override
		);
	}

	/**
	 * A valid submission saves the slug and the four configuration meta.
	 */
	public function test_a_valid_submission_saves_the_feed() {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields() );

		$this->assertSame( 'news', get_post_field( 'post_name', $feed ) );
		$this->assertSame( array( 'page' ), Feed_Meta::post_types( $feed ) );
		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertTrue( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( 20, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ) );
	}

	/**
	 * A feed saved without the checkbox is disabled: new feeds start disabled.
	 */
	public function test_a_feed_saved_without_the_checkbox_is_disabled() {
		$feed   = $this->feed();
		$fields = $this->fields();
		unset( $fields['active'] );

		$this->submit( $feed, $fields );

		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( 'news', get_post_field( 'post_name', $feed ) );
	}

	/**
	 * Without the nonce nothing of the form is saved.
	 */
	public function test_a_submission_without_nonce_saves_nothing() {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields(), array( 'nonce_for' => 0 ) );

		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertNotSame( 'news', get_post_field( 'post_name', $feed ) );
	}

	/**
	 * The nonce of another feed is refused.
	 */
	public function test_the_nonce_of_another_feed_is_refused() {
		$feed  = $this->feed();
		$other = $this->feed( 'Other feed' );

		$this->submit( $feed, $this->fields(), array( 'nonce_for' => $other ) );

		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
		$this->assertNotSame( 'news', get_post_field( 'post_name', $feed ) );
	}

	/**
	 * A user who cannot edit feeds saves nothing, even with a valid nonce of their own.
	 */
	public function test_a_user_without_capability_saves_nothing() {
		$feed   = $this->feed();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );

		$this->submit( $feed, $this->fields() );

		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertNotSame( 'news', get_post_field( 'post_name', $feed ) );
	}

	/**
	 * Post types outside the list of the public ones are refused, and the feed stays disabled.
	 *
	 * @dataProvider forbidden_post_type_provider
	 *
	 * @param string $post_type Submitted post type.
	 */
	public function test_a_source_outside_the_allow_list_is_refused( string $post_type ) {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields( array( 'post_types' => array( $post_type ) ) ) );

		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( 'news', get_post_field( 'post_name', $feed ), 'The valid fields are still saved.' );
	}

	/**
	 * Data for test_a_source_outside_the_allow_list_is_refused().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function forbidden_post_type_provider(): array {
		return array(
			'attachment'    => array( 'attachment' ),
			'the feed type' => array( 'kkce_feed' ),
			'revision'      => array( 'revision' ),
			'unknown'       => array( 'does-not-exist' ),
		);
	}

	/**
	 * A slug already used by another feed is refused and the other fields are still saved.
	 */
	public function test_a_taken_slug_is_refused() {
		$first  = $this->feed( 'First' );
		$second = $this->feed( 'Second' );
		$this->submit( $first, $this->fields() );

		$this->submit( $second, $this->fields( array( 'slug' => 'news', 'per_page' => '7' ) ) );

		$this->assertSame( 'news', get_post_field( 'post_name', $first ) );
		$this->assertNotSame( 'news', get_post_field( 'post_name', $second ) );
		$this->assertSame( 7, Feed_Meta::sanitize_per_page( get_post_meta( $second, '_kkce_per_page', true ) ) );
	}

	/**
	 * A feed can keep its own slug when it is saved again.
	 */
	public function test_a_feed_can_be_saved_again_with_its_own_slug() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );

		$this->submit( $feed, $this->fields( array( 'per_page' => '30' ) ) );

		$this->assertSame( 'news', get_post_field( 'post_name', $feed ) );
		$this->assertSame( 30, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ) );
	}

	/**
	 * Reserved slugs, including those that WordPress would rename on its own, are refused.
	 *
	 * @dataProvider reserved_slug_provider
	 *
	 * @param string $slug Submitted slug.
	 */
	public function test_a_reserved_slug_is_refused( string $slug ) {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields( array( 'slug' => $slug ) ) );

		$this->assertNotSame( $slug, get_post_field( 'post_name', $feed ) );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
	}

	/**
	 * Data for test_a_reserved_slug_is_refused().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function reserved_slug_provider(): array {
		return array(
			'plugin word' => array( 'feeds' ),
			'rss'         => array( 'rss' ),
			'feed'        => array( 'feed' ),
			'embed'       => array( 'embed' ),
		);
	}

	/**
	 * A feed restored from the trash starts disabled, and it cannot be enabled again while another feed
	 * uses its slug. WordPress gives the old slug back on restore and does not check drafts for duplicates.
	 */
	public function test_a_feed_restored_from_the_trash_cannot_be_active_on_a_used_slug() {
		$first = $this->feed( 'First' );
		$this->submit( $first, $this->fields() );
		$_POST = array();
		wp_trash_post( $first );

		$second = $this->feed( 'Second' );
		$this->submit( $second, $this->fields() );
		$_POST = array();
		wp_untrash_post( $first );

		$this->assertSame( 'news', get_post_field( 'post_name', $first ), 'WordPress gives the old slug back.' );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $first, '_kkce_active', true ) ), 'A restored feed starts disabled.' );

		$this->submit( $first, $this->fields( array( 'per_page' => '7' ) ) );

		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $first, '_kkce_active', true ) ) );
		$this->assertTrue( Feed_Meta::sanitize_active( get_post_meta( $second, '_kkce_active', true ) ) );
		$this->assertSame( 7, Feed_Meta::sanitize_per_page( get_post_meta( $first, '_kkce_per_page', true ) ), 'The other fields are still saved.' );
	}

	/**
	 * A slug that WordPress generated from the title, unvalidated, cannot enable the feed.
	 */
	public function test_a_slug_generated_by_wordpress_cannot_enable_the_feed() {
		$feed = $this->feed( 'Settings' );

		// The slug is refused and the feed has none: WordPress generates one from the title after the filter.
		$this->submit( $feed, $this->fields( array( 'slug' => 'feeds' ) ) );
		$this->assertSame( 'settings', get_post_field( 'post_name', $feed ) );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );

		$this->submit( $feed, $this->fields( array( 'slug' => 'settings' ) ) );

		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ), 'A reserved slug never enables the feed.' );
	}

	/**
	 * Restoring a feed from the trash does not touch the other post types.
	 */
	public function test_restoring_another_post_type_is_not_touched() {
		$post = self::factory()->post->create();
		update_post_meta( $post, '_kkce_active', '1' );
		wp_trash_post( $post );
		wp_untrash_post( $post );

		$this->assertSame( '1', get_post_meta( $post, '_kkce_active', true ) );
	}

	/**
	 * The slug is required: an empty slug is reported and never taken from the title by the editor; the
	 * feed stays disabled and the other fields are saved.
	 */
	public function test_an_empty_slug_is_an_error() {
		$feed = $this->feed( 'Latest News' );
		$this->submit( $feed, $this->fields() );

		$this->submit( $feed, $this->fields( array( 'slug' => '', 'per_page' => '7' ) ) );

		$this->assertSame( 'news', get_post_field( 'post_name', $feed ), 'The saved slug is kept.' );
		$this->assertSame( 7, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ) );
		$this->assertSame( 'empty', get_transient( 'kkce_feed_errors_' . $this->admin . '_' . $feed )['slug'] );
	}

	/**
	 * A new feed saved without slug and without post types is saved disabled, with both errors.
	 */
	public function test_a_new_feed_without_slug_and_post_types_reports_both() {
		$feed   = $this->feed( 'Latest News' );
		$fields = $this->fields( array( 'slug' => '' ) );
		unset( $fields['post_types'] );

		$this->submit( $feed, $fields );

		$errors = get_transient( 'kkce_feed_errors_' . $this->admin . '_' . $feed );
		$this->assertSame( 'empty', $errors['slug'] );
		$this->assertSame( 'empty', $errors['post_types'] );
		$this->assertSame( 'incomplete', $errors['active'] );
		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
		$this->assertSame( array(), Feed_Meta::post_types( $feed ) );
	}

	/**
	 * A feed without a source cannot be enabled.
	 */
	public function test_a_feed_without_source_cannot_be_enabled() {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields( array( 'post_types' => array() ) ) );

		$this->assertFalse( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
	}

	/**
	 * Invalid access and items per page keep the previous values.
	 */
	public function test_invalid_access_and_items_per_page_keep_the_previous_values() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );

		$this->submit( $feed, $this->fields( array( 'access' => 'everyone', 'per_page' => '-4' ) ) );

		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ) );
		$this->assertSame( 20, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ) );
	}

	/**
	 * A damaged stored access never opens a feed: the editor shows it as authenticated and keeps it
	 * authenticated when the form sends an unknown value.
	 */
	public function test_a_damaged_access_stays_authenticated() {
		global $wpdb;

		$feed = $this->feed();
		// Simulates a value written outside the editor: the sanitizer is bypassed on purpose.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $feed,
				'meta_key'   => '_kkce_access', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'Authenticated', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		wp_cache_delete( $feed, 'post_meta' );

		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed ) );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/value="authenticated"[^>]*checked/', $html );

		$this->submit( $feed, $this->fields( array( 'access' => 'everyone' ) ) );

		$this->assertSame( 'authenticated', get_post_meta( $feed, '_kkce_access', true ) );
	}

	/**
	 * The items per page of a feed cannot exceed the global maximum of the settings.
	 */
	public function test_items_per_page_cannot_exceed_the_global_maximum() {
		update_option( \KKCE\Settings::OPTION, array( 'enabled' => '1', 'max_per_page' => '30' ) );
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'per_page' => '30' ) ) );
		$this->assertSame( 30, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ), 'The maximum itself is accepted.' );

		$this->submit( $feed, $this->fields( array( 'per_page' => '31', 'access' => 'public' ) ) );

		$this->assertSame( 30, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ), 'The previous value is kept.' );
		$this->assertSame( 'public', get_post_meta( $feed, '_kkce_access', true ), 'The other fields are still saved.' );

		update_option( \KKCE\Settings::OPTION, array( 'enabled' => '1', 'max_per_page' => '500' ) );
		$this->submit( $feed, $this->fields( array( 'per_page' => '200' ) ) );

		$this->assertSame( 200, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ), 'A higher maximum allows it.' );
	}

	/**
	 * Other post types are not touched by the editor, even when the request carries the fields.
	 */
	public function test_other_post_types_are_not_touched() {
		$post = self::factory()->post->create( array( 'post_title' => 'A post' ) );

		$_POST = array(
			'kkce_feed'       => $this->fields(),
			'kkce_feed_nonce' => wp_create_nonce( 'kkce_save_feed_' . $post ),
		);
		wp_update_post( array( 'ID' => $post, 'post_title' => 'Changed' ) );

		$this->assertNotSame( 'news', get_post_field( 'post_name', $post ) );
		$this->assertSame( '', get_post_meta( $post, '_kkce_access', true ) );
	}

	/**
	 * Errors are shown once, in a notice, to the user who saved and only on the screen of the feed.
	 */
	public function test_errors_are_shown_once_in_a_notice() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'slug' => 'feeds' ) ) );

		set_current_screen( 'kkce_feed' );
		$GLOBALS['post'] = get_post( $feed );

		ob_start();
		do_action( 'admin_notices' );
		$first = (string) ob_get_clean();

		ob_start();
		do_action( 'admin_notices' );
		$second = (string) ob_get_clean();

		$this->assertStringContainsString( 'notice-error', $first );
		$this->assertStringContainsString( 'reserved', $first );
		$this->assertStringNotContainsString( 'notice-error', $second );
	}

	/**
	 * Another user does not see the errors of the administrator who saved.
	 */
	public function test_errors_are_not_shown_to_another_user() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'slug' => 'feeds' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'kkce_feed' );
		$GLOBALS['post'] = get_post( $feed );

		ob_start();
		do_action( 'admin_notices' );

		$this->assertStringNotContainsString( 'notice-error', (string) ob_get_clean() );
	}

	/**
	 * The settings box is registered for the feed post type, high in the main column.
	 */
	public function test_the_meta_box_is_registered_for_feeds() {
		global $wp_meta_boxes;

		do_action( 'add_meta_boxes_kkce_feed', get_post( $this->feed() ) );

		$this->assertArrayHasKey( 'kkce_feed_settings', $wp_meta_boxes['kkce_feed']['normal']['high'] );
	}

	/**
	 * The selectable source post types are the public ones, `attachment` excluded.
	 */
	public function test_selectable_post_types() {
		$types = Feed_Editor::selectable_post_types();

		$this->assertArrayHasKey( 'post', $types );
		$this->assertArrayHasKey( 'page', $types );
		$this->assertArrayNotHasKey( 'attachment', $types );
		$this->assertArrayNotHasKey( 'kkce_feed', $types );
		$this->assertArrayNotHasKey( 'revision', $types );
	}

	/**
	 * The box prints a nonce, the fields, the current values and nothing unescaped.
	 */
	public function test_the_meta_box_prints_the_fields_with_the_current_values() {
		register_post_type(
			'evil-type',
			array(
				'public' => true,
				'label'  => '<b>Evil</b>',
			)
		);
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page' ) ) ) );

		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="kkce_feed_nonce"', $html );
		$this->assertStringContainsString( 'name="kkce_feed[slug]"', $html );
		$this->assertStringContainsString( 'value="news"', $html );
		$this->assertMatchesRegularExpression( '/name="kkce_feed\[post_types\]\[\]" value="page"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_feed\[post_types\]\[\]" value="post"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/value="authenticated"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="kkce_feed\[active\]"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="20"', $html );
		$this->assertStringNotContainsString( 'value="attachment"', $html );
		$this->assertStringNotContainsString( 'value="kkce_feed"', $html );
		$this->assertStringContainsString( '&lt;b&gt;Evil&lt;/b&gt;', $html );
		$this->assertStringNotContainsString( '<b>Evil', $html );

		unregister_post_type( 'evil-type' );
	}

	/**
	 * A new feed shows the safe defaults: public, disabled, 50 items, no source.
	 */
	public function test_the_meta_box_of_a_new_feed_shows_the_safe_defaults() {
		$feed = $this->feed();

		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="public"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_feed\[active\]"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="50"', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_feed\[post_types\]\[\]"[^>]*checked/', $html );
	}

	/**
	 * Several source post types are saved, one meta row each, and read back as a list.
	 */
	public function test_several_post_types_are_saved_one_row_each() {
		$feed = $this->feed();

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page', 'post' ) ) ) );

		$this->assertSame( array( 'page', 'post' ), get_post_meta( $feed, '_kkce_post_type', false ) );
		$this->assertSame( array( 'page', 'post' ), Feed_Meta::post_types( $feed ) );
		$this->assertTrue( Feed_Meta::sanitize_active( get_post_meta( $feed, '_kkce_active', true ) ) );
	}

	/**
	 * Saving again rewrites the rows: a removed post type leaves no row behind, a duplicate is saved once.
	 */
	public function test_saving_again_rewrites_the_rows() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page', 'post' ) ) ) );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post', 'post' ) ) ) );

		$this->assertSame( array( 'post' ), get_post_meta( $feed, '_kkce_post_type', false ) );
	}

	/**
	 * A list with one forbidden post type is refused as a whole and the previous list is kept.
	 */
	public function test_a_list_with_a_forbidden_post_type_keeps_the_previous_list() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page', 'post' ) ) ) );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page', 'attachment' ), 'per_page' => '7' ) ) );

		$this->assertSame( array( 'page', 'post' ), get_post_meta( $feed, '_kkce_post_type', false ) );
		$this->assertSame( 7, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ), 'The other fields are still saved.' );
	}

	/**
	 * A value that is not a list (a tampered request) is refused and the previous list is kept.
	 */
	public function test_a_post_type_value_that_is_not_a_list_is_refused() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );

		$this->submit( $feed, $this->fields( array( 'post_types' => 'post' ) ) );

		$this->assertSame( array( 'page' ), Feed_Meta::post_types( $feed ) );
	}

	/**
	 * At least one post type is required: unchecking every post type is reported and keeps the saved list.
	 */
	public function test_unchecking_every_post_type_keeps_the_saved_list() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );
		$fields = $this->fields();
		unset( $fields['post_types'] );

		$this->submit( $feed, $fields );

		$this->assertSame( array( 'page' ), get_post_meta( $feed, '_kkce_post_type', false ) );
		$this->assertSame( 'empty', get_transient( 'kkce_feed_errors_' . $this->admin . '_' . $feed )['post_types'] );
	}

	/**
	 * The box shows every saved post type checked, each checkbox with its own label.
	 */
	public function test_the_meta_box_checks_every_saved_post_type() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page', 'post' ) ) ) );

		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed ) );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/name="kkce_feed\[post_types\]\[\]" value="page"[^>]*checked/', $html );
		$this->assertMatchesRegularExpression( '/name="kkce_feed\[post_types\]\[\]" value="post"[^>]*checked/', $html );
		$this->assertStringContainsString( '<legend', $html );
		$this->assertMatchesRegularExpression( '/<label[^>]*>\s*<input type="checkbox"[^>]*value="page"/', $html );
	}

	/**
	 * Prints the settings box of a feed and returns the HTML.
	 *
	 * @param int $feed_id Feed ID.
	 * @return string
	 */
	private function box( int $feed_id ): string {
		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed_id ) );

		return (string) ob_get_clean();
	}

	/**
	 * The slug field is marked as required, without the HTML attribute that would block saving a draft.
	 */
	public function test_the_slug_field_is_marked_as_required() {
		$html = $this->box( $this->feed() );

		$this->assertMatchesRegularExpression( '/<input[^>]*id="kkce-feed-slug"[^>]*aria-required="true"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*id="kkce-feed-slug"[^>]*\srequired[\s>=]/', $html );
		$this->assertStringContainsString( '(required)', $html );
	}

	/**
	 * With pretty permalinks the box shows the address of the saved feed under `/wp-json/`, escaped.
	 */
	public function test_the_box_shows_the_address_of_the_feed() {
		$this->set_permalink_structure( '/%postname%/' );
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );

		$html = $this->box( $feed );

		$this->assertStringContainsString( '/wp-json/kkce/v1/news', rest_url( 'kkce/v1/news' ) );
		$this->assertStringContainsString( '<code>' . esc_html( rest_url( 'kkce/v1/news' ) ) . '</code>', $html );
		$this->assertStringContainsString( 'data-template="' . esc_attr( rest_url( 'kkce/v1/__kkce_slug__' ) ) . '"', $html );
		$this->assertMatchesRegularExpression( '/<output[^>]*id="kkce-feed-url"[^>]*for="kkce-feed-slug"/', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*id="kkce-feed-slug"[^>]*aria-describedby="[^"]*kkce-feed-url-help/', $html );
	}

	/**
	 * With plain permalinks the address uses the `rest_route` query argument.
	 */
	public function test_the_address_follows_plain_permalinks() {
		$this->set_permalink_structure( '' );
		$feed = $this->feed();
		$this->submit( $feed, $this->fields() );

		$html = $this->box( $feed );

		$this->assertStringContainsString( 'rest_route=/kkce/v1/news', $html );
		$this->assertStringContainsString( 'data-template="' . esc_attr( rest_url( 'kkce/v1/__kkce_slug__' ) ) . '"', $html );
	}

	/**
	 * The script receives the slug rules of the validator: pattern, maximum length and reserved slugs.
	 */
	public function test_the_box_passes_the_slug_rules_to_the_script() {
		$html = $this->box( $this->feed() );

		$this->assertStringContainsString( 'data-pattern="' . esc_attr( \KKCE\Feed_Validator::SLUG_PATTERN ) . '"', $html );
		$this->assertStringContainsString( 'data-max-length="64"', $html );
		$this->assertStringContainsString( 'data-reserved="' . esc_attr( implode( ',', \KKCE\Feed_Validator::RESERVED_SLUGS ) ) . '"', $html );
	}

	/**
	 * A feed without slug shows no address, only the message that asks for one.
	 */
	public function test_a_feed_without_slug_shows_no_address() {
		$html = $this->box( $this->feed() );

		$this->assertStringNotContainsString( 'kkce/v1/', preg_replace( '/data-template="[^"]*"/', '', $html ) );
		$this->assertMatchesRegularExpression( '/<span data-kkce-message="empty">/', $html );
		$this->assertMatchesRegularExpression( '/<span data-kkce-message="format" hidden>/', $html );
	}

	/**
	 * A saved slug that cannot be used (generated by WordPress from the title) shows its error, not an address.
	 */
	public function test_a_reserved_saved_slug_shows_its_error() {
		$feed = $this->feed( 'Settings' );
		$this->submit( $feed, $this->fields( array( 'slug' => 'feeds' ) ) );
		$this->assertSame( 'settings', get_post_field( 'post_name', $feed ) );

		$html = $this->box( $feed );

		$this->assertMatchesRegularExpression( '/<span data-kkce-message="reserved">/', $html );
		$this->assertStringNotContainsString( '<code>', $html );
	}

	/**
	 * The script of the editor is loaded on the screens that edit or create a feed, in the footer, with
	 * the version of the plugin.
	 *
	 * @dataProvider feed_screen_provider
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function test_the_script_is_loaded_on_the_feed_screens( string $hook_suffix ) {
		set_current_screen( 'kkce_feed' );

		Feed_Editor::enqueue_assets( $hook_suffix );

		$script = wp_scripts()->registered['kkce-feed-editor'] ?? null;
		$this->assertTrue( wp_script_is( 'kkce-feed-editor', 'enqueued' ) );
		$this->assertNotNull( $script );
		$this->assertSame( plugins_url( 'assets/js/feed-editor.js', KKCE_PLUGIN_FILE ), $script->src );
		$this->assertSame( KKCE_VERSION, $script->ver );
		$this->assertSame( 1, wp_scripts()->get_data( 'kkce-feed-editor', 'group' ), 'Loaded in the footer.' );
		$this->assertFileExists( KKCE_PLUGIN_DIR . 'assets/js/feed-editor.js' );
	}

	/**
	 * Data for test_the_script_is_loaded_on_the_feed_screens().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function feed_screen_provider(): array {
		return array(
			'edit'   => array( 'post.php' ),
			'create' => array( 'post-new.php' ),
		);
	}

	/**
	 * The script is not loaded elsewhere: the editor of other post types, the list of the feeds, the
	 * settings page.
	 *
	 * @dataProvider other_screen_provider
	 *
	 * @param string $screen      Screen ID.
	 * @param string $hook_suffix Admin page.
	 */
	public function test_the_script_is_not_loaded_elsewhere( string $screen, string $hook_suffix ) {
		set_current_screen( $screen );

		Feed_Editor::enqueue_assets( $hook_suffix );

		$this->assertFalse( wp_script_is( 'kkce-feed-editor', 'enqueued' ) );
	}

	/**
	 * Data for test_the_script_is_not_loaded_elsewhere().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function other_screen_provider(): array {
		return array(
			'post editor'   => array( 'post', 'post.php' ),
			'page creation' => array( 'page', 'post-new.php' ),
			'feed list'     => array( 'edit-kkce_feed', 'edit.php' ),
			'settings'      => array( 'kkce_feed_page_kkce-settings', 'kkce_feed_page_kkce-settings' ),
		);
	}

	/**
	 * A saved source post type that is no longer selectable (its plugin deactivated, or no longer public)
	 * stays checked and marked as not available, and is kept when the feed is saved again: it is removed
	 * only when the administrator unchecks it.
	 */
	public function test_a_saved_post_type_that_is_no_longer_selectable_is_kept() {
		register_post_type( 'kkce_gone', array( 'public' => true, 'label' => 'Gone' ) );
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post', 'kkce_gone' ) ) ) );
		unregister_post_type( 'kkce_gone' );

		$html = $this->box( $feed );
		$this->assertMatchesRegularExpression( '/name="kkce_feed\[post_types\]\[\]" value="kkce_gone"[^>]*checked/', $html );
		$this->assertStringContainsString( 'not available', $html );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post', 'kkce_gone' ), 'per_page' => '7' ) ) );
		$this->assertSame( array( 'post', 'kkce_gone' ), Feed_Meta::post_types( $feed ), 'Kept while it is checked.' );
		$this->assertSame( 7, Feed_Meta::sanitize_per_page( get_post_meta( $feed, '_kkce_per_page', true ) ) );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post' ) ) ) );
		$this->assertSame( array( 'post' ), Feed_Meta::post_types( $feed ), 'Removed when unchecked.' );
	}

	/**
	 * A post type that is not selectable and was not saved for the feed is still refused.
	 */
	public function test_an_unavailable_post_type_that_was_not_saved_is_refused() {
		$feed = $this->feed();
		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post' ) ) ) );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'post', 'kkce_never' ) ) ) );

		$this->assertSame( array( 'post' ), Feed_Meta::post_types( $feed ) );
		$this->assertSame( 'not_allowed', get_transient( 'kkce_feed_errors_' . $this->admin . '_' . $feed )['post_types'] );
	}

	/**
	 * Saving cleans the rows of the post types: empty rows of the first version and duplicates are
	 * rewritten even when the clean list does not change.
	 */
	public function test_saving_cleans_leftover_post_type_rows() {
		$feed = $this->feed();
		add_post_meta( $feed, '_kkce_post_type', '' );
		add_post_meta( $feed, '_kkce_post_type', 'page' );
		add_post_meta( $feed, '_kkce_post_type', 'page' );

		$this->submit( $feed, $this->fields( array( 'post_types' => array( 'page' ) ) ) );

		$this->assertSame( array( 'page' ), get_post_meta( $feed, '_kkce_post_type', false ) );
	}

	/**
	 * The script gets a template of the address, with a placeholder for the slug, so an address with a
	 * query string (added by a language plugin, for example) is built as the server builds it.
	 */
	public function test_the_address_template_keeps_a_query_string() {
		$this->set_permalink_structure( '/%postname%/' );
		$add_lang = static fn( $url ) => add_query_arg( 'lang', 'it', $url );
		add_filter( 'rest_url', $add_lang );

		$html = $this->box( $this->feed() );

		$this->assertStringContainsString( 'data-placeholder="__kkce_slug__"', $html );
		$this->assertStringContainsString( 'data-template="' . esc_attr( rest_url( 'kkce/v1/__kkce_slug__' ) ) . '"', $html );
		$this->assertSame( \KKCE\Plugin::feed_url( 'news' ), str_replace( '__kkce_slug__', 'news', rest_url( 'kkce/v1/__kkce_slug__' ) ) );
		$this->assertStringEndsWith( '/kkce/v1/news?lang=it', \KKCE\Plugin::feed_url( 'news' ) );

		remove_filter( 'rest_url', $add_lang );
	}
}

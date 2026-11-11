<?php
/**
 * Integration tests of the filter of the source post types in the feed settings box.
 *
 * @package KKCE
 */

use KKCE\Admin\Feed_Editor;
use KKCE\Feed_Meta;

/**
 * The filter is printed hidden (the script shows it) only when the list of post types is long.
 */
class KKCE_Post_Type_Filter_Integration_Test extends WP_UnitTestCase {

	/**
	 * Post types registered by a test.
	 *
	 * @var string[]
	 */
	private array $registered = array();

	/**
	 * Registers the meta again (the test case unregisters them) and signs in an administrator.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Unregisters the post types of the test.
	 */
	public function tear_down() {
		foreach ( $this->registered as $post_type ) {
			unregister_post_type( $post_type );
		}

		parent::tear_down();
	}

	/**
	 * HTML of the settings box of a new feed.
	 *
	 * @return string
	 */
	private function box(): string {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed', 'post_status' => 'draft' ) );

		ob_start();
		Feed_Editor::render_meta_box( get_post( $feed ) );

		return (string) ob_get_clean();
	}

	/**
	 * Registers public post types until the selectable ones are the given number.
	 *
	 * @param int $count Selectable post types wanted.
	 * @return void
	 */
	private function selectable( int $count ): void {
		for ( $i = count( Feed_Editor::selectable_post_types() ); $i < $count; $i++ ) {
			$name               = 'kkce_test_' . $i;
			$this->registered[] = $name;
			register_post_type( $name, array( 'public' => true, 'label' => 'Test type ' . $i ) );
		}
		$this->assertCount( $count, Feed_Editor::selectable_post_types() );
	}

	/**
	 * With up to ten post types there is no filter.
	 */
	public function test_no_filter_with_ten_post_types() {
		$this->selectable( 10 );

		$html = $this->box();

		$this->assertStringNotContainsString( 'kkce-post-type-filter', $html );
		$this->assertStringContainsString( 'id="kkce-feed-post-types"', $html );
	}

	/**
	 * With more than ten post types the filter is printed hidden, labelled and linked to the list.
	 */
	public function test_hidden_filter_with_more_than_ten_post_types() {
		$this->selectable( 11 );

		$html = $this->box();

		$this->assertMatchesRegularExpression( '/<p class="kkce-post-type-filter" hidden>/', $html );
		$this->assertMatchesRegularExpression( '/<label for="kkce-post-type-filter">[^<]+<\/label>/', $html );
		$this->assertMatchesRegularExpression( '/<input type="search" id="kkce-post-type-filter"[^>]* aria-controls="kkce-feed-post-types"/', $html );
		$this->assertStringContainsString( 'id="kkce-feed-post-types"', $html );
		$this->assertSame( 11, substr_count( $html, 'name="kkce_feed[post_types][]"' ), 'Every checkbox is still printed.' );
	}
}

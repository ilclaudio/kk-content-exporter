<?php
/**
 * Integration tests of the "Mapping" box of the feed editor.
 *
 * @package KKCE
 */

use KKCE\Admin\Feed_Editor;
use KKCE\Admin\Mapping_Box;
use KKCE\Feed_Meta;
use KKCE\Rest\Admin_Routes;

/**
 * The box gives the mapping editor its initial data, and its script and style are loaded only on the feed screens.
 */
class KKCE_Mapping_Box_Integration_Test extends WP_UnitTestCase {

	/**
	 * Registers the meta again (the test case unregisters them) and signs in an administrator.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Meta::register();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Cleans the assets, the meta boxes and the admin screen.
	 */
	public function tear_down() {
		foreach ( array( Mapping_Box::SCRIPT_HANDLE, Feed_Editor::SCRIPT_HANDLE ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
		wp_dequeue_style( Mapping_Box::STYLE_HANDLE );
		wp_deregister_style( Mapping_Box::STYLE_HANDLE );
		unset( $GLOBALS['wp_meta_boxes'] );
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * A feed with the given post types and mapping.
	 *
	 * @param string[]                         $post_types Post types.
	 * @param array<int, array<string, mixed>> $rows       Rows of the mapping.
	 * @return int
	 */
	private function feed( array $post_types = array(), array $rows = array() ): int {
		$feed = self::factory()->post->create( array( 'post_type' => 'kkce_feed', 'post_status' => 'draft' ) );
		foreach ( $post_types as $post_type ) {
			add_post_meta( $feed, Feed_Meta::META_POST_TYPE, $post_type );
		}
		if ( array() !== $rows ) {
			Feed_Meta::save_mapping( $feed, (string) wp_json_encode( array( 'version' => 1, 'rows' => $rows ) ) );
		}

		return $feed;
	}

	/**
	 * HTML of the box for a feed.
	 *
	 * @param int $feed Feed.
	 * @return string
	 */
	private function box( int $feed ): string {
		ob_start();
		Mapping_Box::render_meta_box( get_post( $feed ) );

		return (string) ob_get_clean();
	}

	/**
	 * Initial data of the editor, read back from the escaped attribute of the container.
	 *
	 * @param string $html HTML of the box.
	 * @return array<string, mixed>
	 */
	private function data( string $html ): array {
		$this->assertSame( 1, preg_match( '/<div id="kkce-feed-mapping"[^>]* data-kkce-mapping="([^"]*)"/', $html, $match ) );

		return json_decode( html_entity_decode( $match[1], ENT_QUOTES ), true );
	}

	/**
	 * The box is added to the editor of the feeds, after the settings box.
	 */
	public function test_the_box_is_added_to_the_feed_editor() {
		set_current_screen( 'kkce_feed' );

		Feed_Editor::add_meta_box();
		Mapping_Box::add_meta_box();

		$boxes = $GLOBALS['wp_meta_boxes']['kkce_feed']['normal'];
		$this->assertArrayHasKey( Mapping_Box::META_BOX_ID, $boxes['default'] );
		$this->assertSame( 'Mapping', $boxes['default'][ Mapping_Box::META_BOX_ID ]['title'] );
		$this->assertArrayHasKey( Feed_Editor::META_BOX_ID, $boxes['high'] );
	}

	/**
	 * The box carries the saved mapping and the catalog of the saved post types, in the form of the catalog route.
	 */
	public function test_the_box_carries_the_saved_mapping_and_the_catalog() {
		$rows = array(
			array( 'key' => 'title', 'kind' => 'field', 'sources' => array( '*' => array( 'core.title' ) ) ),
		);
		$feed = $this->feed( array( 'post', 'page', 'gone' ), $rows );

		$data = $this->data( $this->box( $feed ) );

		$this->assertSame( Feed_Meta::mapping( $feed ), $data['mapping'] );
		$this->assertSame( Admin_Routes::describe_catalog( array( 'post', 'page', 'gone' ) ), $data['catalog'] );
		$this->assertSame( array( 'gone' ), $data['catalog']['unavailable'] );
		$this->assertSame( array( 'post' => 'Posts', 'page' => 'Pages' ), $data['catalog']['labels'] );
		$this->assertSame( '/kkce-admin/v1/catalog', $data['catalogPath'] );
		$this->assertSame( '/kkce-admin/v1/preview', $data['previewPath'] );
		$this->assertSame( '/kkce-admin/v1/items', $data['itemsPath'] );
		$this->assertSame( 'kkce_feed[mapping]', $data['field'] );
		$this->assertSame( 100, $data['maxRows'] );
		$this->assertSame( 64, $data['keyMaxLength'] );
		$this->assertSame( KKCE\Output\Mapping_Validator::MAX_CHAIN, $data['maxChain'] );
		$this->assertSame( KKCE\Output\Mapping_Validator::VALUE_MAX_LENGTH, $data['valueMaxLength'] );
		$this->assertSame( KKCE\Output\Template::MAX_LENGTH, $data['templateMaxLength'] );
	}

	/**
	 * The rule of the output keys comes from the server, in a form that PCRE and JavaScript read the same
	 * way: the script copies no rule of the validator.
	 */
	public function test_the_key_rule_is_given_by_the_server() {
		$data    = $this->data( $this->box( $this->feed() ) );
		$pattern = '/' . $data['keyPattern'] . '/';

		foreach ( array( 'title', 'a.b-c_9', 'X' ) as $key ) {
			$this->assertSame( 1, preg_match( $pattern, $key ), $key );
			$this->assertSame( array(), KKCE\Output\Mapping_Validator::validate( array( 'version' => 1, 'rows' => array( array( 'key' => $key, 'kind' => 'fixed', 'values' => array( '*' => 'x' ) ) ) ) ), $key );
		}
		foreach ( array( 'a b', 'è', 'a/b', '' ) as $key ) {
			$this->assertSame( 0, preg_match( $pattern, $key ), $key );
		}
	}

	/**
	 * Until the mapping is saved by the feed editor, the box says so: rows built here are not stored.
	 */
	public function test_the_box_says_that_the_mapping_is_not_saved_yet() {
		$html = $this->box( $this->feed() );

		$this->assertMatchesRegularExpression( '#<div class="notice notice-warning inline kkce-mapping-unsaved"><p>[^<]+</p></div>#', $html );
	}

	/**
	 * A new feed gets an empty mapping and the common sources.
	 */
	public function test_a_new_feed_gets_an_empty_mapping() {
		$data = $this->data( $this->box( $this->feed() ) );

		$this->assertSame( array( 'version' => 1, 'rows' => array() ), $data['mapping'] );
		$this->assertSame( array(), $data['catalog']['post_types'] );
		$this->assertContains( 'core.title', array_column( $data['catalog']['sources'], 'id' ) );
	}

	/**
	 * The data attribute is escaped, the box explains what happens without JavaScript, and it prints no
	 * mapping field: without the script, the saved mapping is never sent, so it cannot be changed.
	 */
	public function test_the_box_is_escaped_and_sends_nothing_without_javascript() {
		$html = $this->box( $this->feed( array( 'post' ) ) );

		$this->assertStringContainsString( '&quot;', $html );
		$this->assertStringNotContainsString( '"version":', $html );
		$this->assertMatchesRegularExpression( '#<noscript>.*JavaScript.*</noscript>#s', $html );
		$this->assertStringNotContainsString( 'name="kkce_feed[mapping]"', $html );
	}

	/**
	 * Script and style are loaded on the screens that edit or create a feed, with their dependencies and
	 * translations.
	 *
	 * @dataProvider feed_screen_provider
	 *
	 * @param string $hook_suffix Admin page.
	 */
	public function test_the_assets_are_loaded_on_the_feed_screens( string $hook_suffix ) {
		set_current_screen( 'kkce_feed' );

		Mapping_Box::enqueue_assets( $hook_suffix );

		$script = wp_scripts()->registered[ Mapping_Box::SCRIPT_HANDLE ] ?? null;
		$style  = wp_styles()->registered[ Mapping_Box::STYLE_HANDLE ] ?? null;
		$this->assertTrue( wp_script_is( Mapping_Box::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Mapping_Box::STYLE_HANDLE, 'enqueued' ) );
		$this->assertSame( plugins_url( 'assets/js/feed-mapping.js', KKCE_PLUGIN_FILE ), $script->src );
		$this->assertSame( plugins_url( 'assets/css/feed-mapping.css', KKCE_PLUGIN_FILE ), $style->src );
		$this->assertSame( KKCE_VERSION, $script->ver );
		$this->assertSame( KKCE_VERSION, $style->ver );
		$this->assertSame( array( 'wp-api-fetch', 'wp-i18n' ), $script->deps );
		$this->assertSame( 1, wp_scripts()->get_data( Mapping_Box::SCRIPT_HANDLE, 'group' ), 'Loaded in the footer.' );
		$this->assertSame( 'kk-content-exporter', $script->textdomain );
		$this->assertSame( KKCE_PLUGIN_DIR . 'languages', $script->translations_path );
		$this->assertFileExists( KKCE_PLUGIN_DIR . 'assets/js/feed-mapping.js' );
		$this->assertFileExists( KKCE_PLUGIN_DIR . 'assets/css/feed-mapping.css' );
	}

	/**
	 * Data for test_the_assets_are_loaded_on_the_feed_screens().
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
	 * Script and style are not loaded on other screens.
	 *
	 * @dataProvider other_screen_provider
	 *
	 * @param string $screen      Screen.
	 * @param string $hook_suffix Admin page.
	 */
	public function test_the_assets_are_not_loaded_elsewhere( string $screen, string $hook_suffix ) {
		set_current_screen( $screen );

		Mapping_Box::enqueue_assets( $hook_suffix );

		$this->assertFalse( wp_script_is( Mapping_Box::SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Mapping_Box::STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Data for test_the_assets_are_not_loaded_elsewhere().
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function other_screen_provider(): array {
		return array(
			'post editor'   => array( 'post', 'post.php' ),
			'list of feeds' => array( 'edit-kkce_feed', 'edit.php' ),
			'settings page' => array( 'settings_page_kkce', 'kkce_feed_page_kkce-settings' ),
		);
	}

	/**
	 * The Italian translations of the script are shipped as a JSON file that WordPress finds.
	 */
	public function test_the_italian_script_translations_are_found() {
		$force_italian = static fn() => 'it_IT';
		add_filter( 'determine_locale', $force_italian );
		add_filter( 'locale', $force_italian );
		set_current_screen( 'kkce_feed' );
		Mapping_Box::enqueue_assets( 'post.php' );

		try {
			$json = load_script_textdomain( Mapping_Box::SCRIPT_HANDLE, 'kk-content-exporter', KKCE_PLUGIN_DIR . 'languages' );
		} finally {
			remove_filter( 'determine_locale', $force_italian );
			remove_filter( 'locale', $force_italian );
		}

		$this->assertIsString( $json );
		$messages = json_decode( $json, true )['locale_data']['messages'];
		$this->assertArrayHasKey( 'Add row', $messages );
		$this->assertNotSame( array( 'Add row' ), $messages['Add row'] );
	}


	/**
	 * The mapping box explains what the mapping is, and how the rows work in a section that can be opened.
	 */
	public function test_the_box_explains_the_mapping() {
		$html = $this->box( $this->feed() );

		$this->assertMatchesRegularExpression( '#<p class="description kkce-box-intro">[^<]+</p>#', $html );
		$this->assertMatchesRegularExpression( '#<details class="kkce-mapping-help">\s*<summary>[^<]+</summary>\s*<ul>(\s*<li>.+?</li>){6}\s*</ul>\s*</details>#s', $html );
		$this->assertLessThan( strpos( $html, 'id="kkce-feed-mapping"' ), strpos( $html, 'kkce-mapping-help' ), 'The explanation comes before the rows.' );
	}
}

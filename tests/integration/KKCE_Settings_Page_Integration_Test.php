<?php
/**
 * Integration tests of the settings page: menu, saving, markup and diagnostics.
 *
 * @package KKCE
 */

use KKCE\Admin\Settings_Page;
use KKCE\Feed_Post_Type;
use KKCE\Settings;

/**
 * The settings page is for administrators, saves sanitized values and reports what blocks the feeds.
 */
class KKCE_Settings_Page_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator who edits the settings.
	 *
	 * @var int
	 */
	private int $admin;

	/**
	 * Registers the meta and the setting again (the test case resets them) and signs in an administrator.
	 */
	public function set_up() {
		parent::set_up();

		Feed_Post_Type::register_meta();
		Settings_Page::register_settings();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	/**
	 * Cleans the globals touched by the tests.
	 */
	public function tear_down() {
		global $submenu;

		unset( $submenu['edit.php?post_type=kkce_feed'] );
		unregister_setting( Settings_Page::GROUP, Settings::OPTION );
		delete_option( Settings::OPTION );
		$GLOBALS['current_screen'] = null;

		parent::tear_down();
	}

	/**
	 * Creates a feed with the given access.
	 *
	 * @param string $access Access value stored in the meta.
	 * @param bool   $active Whether the feed is enabled.
	 * @return int
	 */
	private function feed( string $access = 'public', bool $active = true ): int {
		$id = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		update_post_meta( $id, '_kkce_access', $access );
		update_post_meta( $id, '_kkce_active', $active );

		return $id;
	}

	/**
	 * Prints the page and returns the output.
	 *
	 * @return string
	 */
	private function page(): string {
		ob_start();
		Settings_Page::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * The page is a submenu of the feeds menu and requires the administration capability.
	 */
	public function test_the_page_is_a_submenu_for_administrators_only() {
		global $submenu;

		Settings_Page::add_menu();
		$items = array_column( $submenu['edit.php?post_type=kkce_feed'] ?? array(), 2 );
		$this->assertContains( 'kkce-settings', $items );

		$entry = array_values( array_filter( $submenu['edit.php?post_type=kkce_feed'], static fn( $item ) => 'kkce-settings' === $item[2] ) );
		$this->assertSame( 'manage_options', $entry[0][1] );

		unset( $submenu['edit.php?post_type=kkce_feed'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		Settings_Page::add_menu();

		$this->assertArrayNotHasKey( 'edit.php?post_type=kkce_feed', $submenu, 'An editor gets no menu item.' );
	}

	/**
	 * Printing the page without the capability stops with an error, whatever the menu says.
	 */
	public function test_the_page_refuses_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Not through page(): the exception would leave its output buffer open.
		$this->expectException( WPDieException::class );
		Settings_Page::render_page();
	}

	/**
	 * The setting is registered with the sanitizer of the plugin and is not exposed in REST.
	 */
	public function test_the_setting_is_registered_without_rest() {
		$registered = get_registered_settings();

		$this->assertArrayHasKey( 'kkce_settings', $registered );
		$this->assertFalse( $registered['kkce_settings']['show_in_rest'] );
		$this->assertSame( array( Settings::class, 'sanitize_option' ), $registered['kkce_settings']['sanitize_callback'] );
	}

	/**
	 * Saving goes through the sanitizer: unchecked boxes are off, the maximum is a whole number.
	 */
	public function test_saving_sanitizes_the_settings() {
		update_option(
			Settings::OPTION,
			array(
				'enabled'      => '1',
				'max_per_page' => '250',
				'remove_data'  => 'on',
				'surprise'     => 'x',
			)
		);

		$this->assertSame(
			array(
				'enabled'      => true,
				'require_auth' => false,
				'max_per_page' => 250,
				'remove_data'  => false,
			),
			get_option( Settings::OPTION )
		);
	}

	/**
	 * A maximum that is not valid keeps the previous one and shows an error.
	 */
	public function test_an_invalid_maximum_is_refused_with_an_error() {
		update_option( Settings::OPTION, array( 'enabled' => '1', 'max_per_page' => '150' ) );

		update_option( Settings::OPTION, array( 'enabled' => '1', 'max_per_page' => '-4' ) );

		$this->assertSame( 150, Settings::get()['max_per_page'] );
		$errors = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message' );
		$this->assertNotEmpty( $errors );
		$this->assertStringContainsString( 'whole number', (string) $errors[0] );
	}

	/**
	 * The page prints the form of the Settings API with the current values.
	 */
	public function test_the_page_prints_the_form_with_the_current_values() {
		update_option( Settings::OPTION, array( 'require_auth' => '1', 'max_per_page' => '250' ) );

		$html = $this->page();

		$this->assertStringContainsString( "name='option_page' value='kkce_settings'", $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( 'name="kkce_settings[max_per_page]"', $html );
		$this->assertStringContainsString( 'value="250"', $html );
		$this->assertMatchesRegularExpression( '/name="kkce_settings\[require_auth\]"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_settings\[enabled\]"[^>]*checked/', $html, 'The switch was saved off.' );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_settings\[remove_data\]"[^>]*checked/', $html );
	}

	/**
	 * Before any save the page shows the defaults: endpoints on, authentication not required, 100, no removal.
	 */
	public function test_the_page_shows_the_defaults_before_any_save() {
		$html = $this->page();

		$this->assertMatchesRegularExpression( '/name="kkce_settings\[enabled\]"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_settings\[require_auth\]"[^>]*checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="kkce_settings\[remove_data\]"[^>]*checked/', $html );
		$this->assertStringContainsString( 'value="100"', $html );
	}

	/**
	 * A blocked REST API for anonymous requests is reported, and the current user is restored.
	 */
	public function test_diagnostics_report_a_rest_api_blocked_for_anonymous_requests() {
		$this->assertSame( array(), $this->only_rest( Settings_Page::diagnostics() ) );

		$block = static function ( $result ) {
			return is_user_logged_in() ? $result : new WP_Error( 'blocked', 'Blocked.', array( 'status' => 401 ) );
		};
		add_filter( 'rest_authentication_errors', $block );

		$messages = Settings_Page::diagnostics();

		$this->assertNotEmpty( $this->only_rest( $messages ) );
		$this->assertSame( $this->admin, get_current_user_id(), 'The signed-in user is restored.' );
	}

	/**
	 * Keeps only the messages about the REST API.
	 *
	 * @param list<string> $messages Diagnostic messages.
	 * @return list<string>
	 */
	private function only_rest( array $messages ): array {
		return array_values( array_filter( $messages, static fn( string $message ): bool => false !== stripos( $message, 'REST API' ) ) );
	}

	/**
	 * An authenticated feed on a site without Application Passwords is reported.
	 */
	public function test_diagnostics_report_authenticated_feeds_without_application_passwords() {
		$this->feed( 'authenticated' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$messages = Settings_Page::diagnostics();

		$this->assertNotEmpty( array_filter( $messages, static fn( string $message ): bool => false !== stripos( $message, 'Application Passwords' ) ) );
	}

	/**
	 * No warning when Application Passwords are available, or when no feed is authenticated.
	 */
	public function test_diagnostics_stay_quiet_when_nothing_is_wrong() {
		$this->feed( 'authenticated' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );
		$this->assertSame( array(), Settings_Page::diagnostics() );

		remove_filter( 'wp_is_application_passwords_available', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
		wp_delete_post( (int) get_posts( array( 'post_type' => 'kkce_feed', 'fields' => 'ids' ) )[0], true );
		$this->feed( 'public' );

		$this->assertSame( array(), Settings_Page::diagnostics(), 'A public feed needs no Application Passwords.' );
	}

	/**
	 * A disabled authenticated feed is not served, so it needs no Application Passwords and no warning.
	 */
	public function test_diagnostics_ignore_disabled_feeds() {
		$this->feed( 'authenticated', false );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertSame( array(), Settings_Page::diagnostics() );
	}

	/**
	 * The authentication required for all feeds counts as authenticated feeds.
	 */
	public function test_diagnostics_count_the_global_authentication_requirement() {
		$this->feed( 'public' );
		update_option( Settings::OPTION, array( 'enabled' => '1', 'require_auth' => '1', 'max_per_page' => '100' ) );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertNotEmpty( Settings_Page::diagnostics() );
	}

	/**
	 * A damaged access in the database counts as authenticated, like everywhere else.
	 */
	public function test_diagnostics_treat_a_damaged_access_as_authenticated() {
		global $wpdb;

		$id = self::factory()->post->create( array( 'post_type' => 'kkce_feed' ) );
		// Simulates a value written outside the plugin: the sanitizer is bypassed on purpose.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $id,
				'meta_key'   => '_kkce_access', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'Everyone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		update_post_meta( $id, '_kkce_active', true );
		wp_cache_delete( $id, 'post_meta' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertNotEmpty( Settings_Page::diagnostics() );
	}

	/**
	 * The page shows the diagnostic messages, escaped, as warnings.
	 */
	public function test_the_page_shows_the_diagnostics_as_warnings() {
		$this->feed( 'authenticated' );
		add_filter( 'wp_is_application_passwords_available', '__return_false' );

		$this->assertStringContainsString( 'notice-warning', $this->page() );
	}
}

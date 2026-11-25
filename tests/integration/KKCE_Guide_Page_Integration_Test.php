<?php
/**
 * Integration tests of the guide page: menu, permission, tabs and their content.
 *
 * @package KKCE
 */

use KKCE\Admin\Guide_Page;
use KKCE\Admin\Guide_Tabs;
use KKCE\Admin\Settings_Page;

/**
 * The guide is a page for administrators after the settings, with one tab per topic.
 */
class KKCE_Guide_Page_Integration_Test extends WP_UnitTestCase {

	/**
	 * Administrator who reads the guide.
	 *
	 * @var int
	 */
	private static int $admin;

	/**
	 * Creates the shared administrator once.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		self::$admin = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Signs in an administrator.
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( self::$admin );
	}

	/**
	 * Cleans the globals touched by the tests.
	 */
	public function tear_down() {
		global $submenu;

		unset( $submenu['edit.php?post_type=kkce_feed'], $_GET['tab'] );

		parent::tear_down();
	}

	/**
	 * Prints the page for a requested tab and returns the output.
	 *
	 * @param mixed $tab Value of the `tab` parameter; null for none.
	 * @return string
	 */
	private function page( mixed $tab = null ): string {
		if ( null !== $tab ) {
			$_GET['tab'] = $tab;
		}
		ob_start();
		Guide_Page::render_page();

		return (string) ob_get_clean();
	}

	/**
	 * The guide is a submenu of the feeds menu, right after the settings, for administrators only.
	 */
	public function test_the_page_follows_the_settings_for_administrators_only() {
		global $submenu;

		Settings_Page::add_menu();
		Guide_Page::add_menu();
		$items    = array_column( $submenu['edit.php?post_type=kkce_feed'] ?? array(), 2 );
		$settings = array_search( 'kkce-settings', $items, true );

		$this->assertNotFalse( $settings );
		$this->assertSame( 'kkce-guide', $items[ $settings + 1 ] ?? null );
		$entry = array_values( array_filter( $submenu['edit.php?post_type=kkce_feed'], static fn( $item ) => 'kkce-guide' === $item[2] ) );
		$this->assertSame( 'manage_options', $entry[0][1] );
		$this->assertSame( 'Guide', $entry[0][0] );

		unset( $submenu['edit.php?post_type=kkce_feed'] );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		Guide_Page::add_menu();

		$this->assertArrayNotHasKey( 'edit.php?post_type=kkce_feed', $submenu, 'An editor gets no menu item.' );
	}

	/**
	 * Printing the page without the capability stops with an error, whatever the menu says.
	 */
	public function test_the_page_refuses_users_without_the_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		// Not through page(): the exception would leave its output buffer open.
		$this->expectException( WPDieException::class );
		Guide_Page::render_page();
	}

	/**
	 * The address of a tab is the guide page with the tab.
	 */
	public function test_the_address_of_a_tab() {
		$url = Guide_Page::url( 'lookup' );

		$this->assertStringStartsWith( admin_url( 'edit.php' ), $url );
		$this->assertStringContainsString( 'post_type=kkce_feed', $url );
		$this->assertStringContainsString( 'page=kkce-guide', $url );
		$this->assertStringContainsString( 'tab=lookup', $url );
	}

	/**
	 * Every tab is a link to its own address; the tab shown is marked as the current page and shows its content.
	 */
	public function test_every_tab_shows_its_content() {
		$expected = array(
			'overview'  => 'Every item has all the keys',
			'field'     => 'Add fallback',
			'fixed'     => 'Every item gets the same text',
			'template'  => 'Insert field',
			'lookup'    => 'When no line matches',
			'condition' => 'is one of',
			'output'    => 'Maximum length',
			'preview'   => 'Copy JSON',
		);
		$this->assertSame( Guide_Tabs::TABS, array_keys( $expected ) );

		foreach ( $expected as $tab => $text ) {
			$html = $this->page( $tab );

			foreach ( Guide_Tabs::TABS as $other ) {
				$this->assertStringContainsString( 'href="' . esc_url( Guide_Page::url( $other ) ) . '"', $html, $tab . ': link to ' . $other );
			}
			$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ), $tab );
			$this->assertMatchesRegularExpression( '#<a [^>]*class="nav-tab nav-tab-active"[^>]*aria-current="page"[^>]*>#', $html, $tab );
			$this->assertStringContainsString( esc_url( Guide_Page::url( $tab ) ) . '" class="nav-tab nav-tab-active"', $html, $tab );
			$this->assertStringContainsString( $text, $html, $tab );
		}
	}

	/**
	 * The overview has a section on multilingual sites, with its own anchor, that the Mapping box links to.
	 */
	public function test_the_overview_explains_multilingual_sites() {
		$html = $this->page( 'overview' );

		$this->assertMatchesRegularExpression( '#<h3 id="kkce-guide-multilingual">[^<]+</h3>#', $html );
		$this->assertStringContainsString( '<code>lang</code>', $html );
		$this->assertStringContainsString( 'Translation group', $html );
	}

	/**
	 * A missing or unknown tab shows the overview; a value from the address is never printed.
	 */
	public function test_an_unknown_tab_shows_the_overview() {
		$this->assertStringContainsString( 'Every item has all the keys', $this->page() );

		$html = $this->page( '<script>alert(1)</script>' );
		$this->assertStringContainsString( 'Every item has all the keys', $html );
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringContainsString( 'Every item has all the keys', $this->page( array( 'lookup' ) ) );
	}
}

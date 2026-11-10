<?php
/**
 * Integration tests of the address of a feed.
 *
 * @package KKCE
 */

use KKCE\Plugin;

/**
 * `Plugin::feed_url()` is the only place that computes the address of a feed.
 */
class KKCE_Feed_Url_Integration_Test extends WP_UnitTestCase {

	/**
	 * A usable slug gives the address computed by WordPress, with pretty and with plain permalinks.
	 */
	public function test_a_usable_slug_gives_the_rest_address() {
		$this->set_permalink_structure( '/%postname%/' );
		$this->assertSame( rest_url( 'kkce/v1/news' ), Plugin::feed_url( 'news' ) );
		$this->assertStringContainsString( '/wp-json/kkce/v1/news', Plugin::feed_url( 'news' ) );

		$this->set_permalink_structure( '' );
		$this->assertStringContainsString( 'rest_route=/kkce/v1/news', Plugin::feed_url( 'news' ) );
	}

	/**
	 * A slug that cannot serve a feed gives no address.
	 *
	 * @dataProvider unusable_slug_provider
	 *
	 * @param string $slug Slug.
	 */
	public function test_an_unusable_slug_gives_no_address( string $slug ) {
		$this->assertSame( '', Plugin::feed_url( $slug ) );
	}

	/**
	 * Data for test_an_unusable_slug_gives_no_address().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function unusable_slug_provider(): array {
		return array(
			'empty'    => array( '' ),
			'reserved' => array( 'feeds' ),
			'format'   => array( 'Bad Slug' ),
			'markup'   => array( '<b>x</b>' ),
			'too long' => array( str_repeat( 'a', 65 ) ),
		);
	}
}

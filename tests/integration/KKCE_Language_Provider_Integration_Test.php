<?php
/**
 * Integration tests of the language sources without a multilingual plugin.
 *
 * @package KKCE
 */

use KKCE\Sources\Catalog_Factory;
use KKCE\Sources\Language_Provider;

/**
 * Without Polylang only the language of the site is offered; the sources of the language of an item give null, so a
 * mapping saved while Polylang was active keeps working after it is turned off. With Polylang: the `polylang` suite.
 */
class KKCE_Language_Provider_Integration_Test extends WP_UnitTestCase {

	/**
	 * Only the site language is offered, as a common text source.
	 */
	public function test_without_polylang_only_the_site_language_is_offered() {
		$this->assertFalse( function_exists( 'pll_get_post_language' ), 'This suite runs without Polylang.' );

		$ids = array_map(
			static fn( $source ) => $source->id,
			array_filter( Catalog_Factory::for_post_types( array( 'post', 'page' ) )->sources(), static fn( $source ) => 'language' === $source->group() )
		);

		$this->assertSame( array( 'language.site' ), array_values( $ids ) );
		$this->assertSame( 'string', Catalog_Factory::for_post_types( array( 'post' ) )->find( 'language.site', '*' )->type );
	}

	/**
	 * The site language is the one of the General settings in the W3C format, `en-US` when none is chosen. The setting
	 * is given through `pre_option_WPLANG`: WordPress saves only installed languages, and the test environment has
	 * none.
	 */
	public function test_the_site_language_is_the_setting_in_w3c_format() {
		$post   = get_post( self::factory()->post->create() );
		$locale = '';
		add_filter( 'pre_option_WPLANG', static function () use ( &$locale ) {
			return $locale;
		} );

		$this->assertSame( 'en-US', Language_Provider::value( $post, 'language.site' ) );

		$locale = 'it_IT';
		$this->assertSame( 'it-IT', Language_Provider::value( $post, 'language.site' ) );

		$locale = 'de_DE_formal';
		$this->assertSame( 'de-DE-formal', Language_Provider::value( $post, 'language.site' ) );
	}

	/**
	 * The sources of the language of an item, and unknown identifiers, give null without Polylang.
	 */
	public function test_item_sources_are_null_without_polylang() {
		$post = get_post( self::factory()->post->create() );

		foreach ( array( 'language.code', 'language.locale', 'language.w3c', 'language.translation_group', 'language.unknown' ) as $id ) {
			$this->assertNull( Language_Provider::value( $post, $id ), $id );
		}
	}
}

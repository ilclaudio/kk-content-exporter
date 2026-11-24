<?php
/**
 * Base class of the integration tests that need Polylang.
 *
 * @package KKCE
 */

/**
 * Runs in the `polylang` suite only (`KKCE_TEST_POLYLANG=1`, see tests/bootstrap-integration.php), where Polylang is
 * loaded as an active plugin and runs as in a REST request.
 *
 * The languages Italian (`it`, default) and English (`en`) are created once per class. On a real site they exist when
 * WordPress boots; here they are created after Polylang started, so its REST context is started again to see them (it
 * hooks the `lang` parameter only when the site has languages). Every test is skipped when Polylang did not start,
 * which happens on a version of WordPress older than its minimum (the CI also runs on WordPress 6.4).
 */
abstract class KKCE_Polylang_Test_Case extends WP_UnitTestCase {

	/**
	 * Languages of the tests: Polylang arguments, default first.
	 */
	protected const LANGUAGES = array(
		array(
			'locale' => 'it_IT',
			'slug'   => 'it',
		),
		array(
			'locale' => 'en_US',
			'slug'   => 'en',
		),
	);

	/**
	 * Creates the languages and starts the REST context of Polylang again.
	 *
	 * @param WP_UnitTest_Factory $factory Factory.
	 * @return void
	 */
	public static function wpSetUpBeforeClass( WP_UnitTest_Factory $factory ) {
		if ( ! function_exists( 'PLL' ) ) {
			return;
		}

		foreach ( self::LANGUAGES as $language ) {
			$result = PLL()->model->languages->add( $language + array( 'no_default_cat' => true ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( 'Polylang language not created: ' . $result->get_error_message() );
			}
		}
		PLL()->model->clean_languages_cache();

		$links_model        = PLL()->model->get_links_model();
		$GLOBALS['polylang'] = new PLL_REST_Request( $links_model );
		PLL()->init();
	}

	/**
	 * Forgets the languages kept in the memory of Polylang (their terms are deleted with the other data of the class).
	 *
	 * @return void
	 */
	public static function wpTearDownAfterClass() {
		if ( function_exists( 'PLL' ) ) {
			PLL()->model->clean_languages_cache();
		}
	}

	/**
	 * Skips the test when Polylang did not start.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		if ( ! function_exists( 'PLL' ) || ! function_exists( 'pll_set_post_language' ) ) {
			$this->markTestSkipped( 'Polylang did not start: it needs WordPress ' . ( defined( 'PLL_MIN_WP_VERSION' ) ? PLL_MIN_WP_VERSION : '6.8' ) . ' or later.' );
		}
	}

	/**
	 * A published post in a language.
	 *
	 * @param string               $language Language slug.
	 * @param array<string, mixed> $args     Changes to the post.
	 * @return int
	 */
	protected function translated_post( string $language, array $args = array() ): int {
		$post_id = self::factory()->post->create( array_merge( array( 'post_status' => 'publish' ), $args ) );
		pll_set_post_language( $post_id, $language );

		return $post_id;
	}
}

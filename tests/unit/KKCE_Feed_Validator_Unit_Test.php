<?php
/**
 * Unit tests of the pure validation of the feed settings (no WordPress, no mocks).
 *
 * @package KKCE
 */

use KKCE\Feed_Validator;

/**
 * Slug, items per page and source post type validation.
 */
class KKCE_Feed_Validator_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * A well-formed, unused, non-reserved slug is valid.
	 *
	 * @dataProvider valid_slug_provider
	 *
	 * @param string $slug Slug.
	 */
	public function test_valid_slugs( string $slug ) {
		$this->assertSame( Feed_Validator::VALID, Feed_Validator::validate_slug( $slug, array( 'other-feed' ) ) );
	}

	/**
	 * Data for test_valid_slugs().
	 *
	 * @return array<string, array{0: string}>
	 */
	public function valid_slug_provider(): array {
		return array(
			'letters'        => array( 'news' ),
			'with dash'      => array( 'latest-news' ),
			'with digits'    => array( 'news-2024' ),
			'only digits'    => array( '2024' ),
			'single char'    => array( 'a' ),
			'longest'        => array( str_repeat( 'a', 64 ) ),
			'reserved inside' => array( 'my-feeds' ),
		);
	}

	/**
	 * Invalid slugs give the matching error code.
	 *
	 * @dataProvider invalid_slug_provider
	 *
	 * @param mixed  $slug     Slug.
	 * @param string $expected Error code.
	 */
	public function test_invalid_slugs( $slug, string $expected ) {
		$this->assertSame( $expected, Feed_Validator::validate_slug( $slug, array() ) );
	}

	/**
	 * Data for test_invalid_slugs().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function invalid_slug_provider(): array {
		return array(
			'empty'            => array( '', Feed_Validator::ERROR_EMPTY ),
			'uppercase'        => array( 'News', Feed_Validator::ERROR_FORMAT ),
			'space'            => array( 'my feed', Feed_Validator::ERROR_FORMAT ),
			'underscore'       => array( 'my_feed', Feed_Validator::ERROR_FORMAT ),
			'slash'            => array( 'a/b', Feed_Validator::ERROR_FORMAT ),
			'dot'              => array( 'feed.json', Feed_Validator::ERROR_FORMAT ),
			'accent'           => array( 'notizie-è', Feed_Validator::ERROR_FORMAT ),
			'leading dash'     => array( '-news', Feed_Validator::ERROR_FORMAT ),
			'trailing dash'    => array( 'news-', Feed_Validator::ERROR_FORMAT ),
			'double dash'      => array( 'my--feed', Feed_Validator::ERROR_FORMAT ),
			'trailing newline' => array( "news\n", Feed_Validator::ERROR_FORMAT ),
			'markup'           => array( '<b>', Feed_Validator::ERROR_FORMAT ),
			'array'            => array( array( 'news' ), Feed_Validator::ERROR_FORMAT ),
			'null'             => array( null, Feed_Validator::ERROR_FORMAT ),
			'too long'         => array( str_repeat( 'a', 65 ), Feed_Validator::ERROR_TOO_LONG ),
		);
	}

	/**
	 * Every reserved word is refused as a slug.
	 */
	public function test_reserved_slugs_are_refused() {
		$reserved = Feed_Validator::RESERVED_SLUGS;

		$this->assertContains( 'feeds', $reserved );
		$this->assertContains( 'settings', $reserved );
		$this->assertContains( 'preview', $reserved );
		// WordPress silently renames these flat slugs (adds "-2"), so they must be refused here.
		foreach ( array( 'feed', 'rss', 'rss2', 'rdf', 'atom', 'embed' ) as $wordpress_slug ) {
			$this->assertContains( $wordpress_slug, $reserved );
		}
		foreach ( $reserved as $slug ) {
			$this->assertSame( Feed_Validator::ERROR_RESERVED, Feed_Validator::validate_slug( $slug, array() ), $slug );
		}
	}

	/**
	 * A slug already used by another feed is refused; an unused one is accepted.
	 */
	public function test_taken_slug_is_refused() {
		$existing = array( 'news', 'events' );

		$this->assertSame( Feed_Validator::ERROR_TAKEN, Feed_Validator::validate_slug( 'news', $existing ) );
		$this->assertSame( Feed_Validator::VALID, Feed_Validator::validate_slug( 'products', $existing ) );
	}

	/**
	 * The format is checked before the reserved words and the uniqueness.
	 */
	public function test_format_is_checked_before_reserved_words_and_uniqueness() {
		$this->assertSame( Feed_Validator::ERROR_FORMAT, Feed_Validator::validate_slug( 'Feeds', array( 'Feeds' ) ) );
		$this->assertSame( Feed_Validator::ERROR_RESERVED, Feed_Validator::validate_slug( 'feeds', array( 'feeds' ) ) );
	}

	/**
	 * The items per page is an integer of at least 1.
	 *
	 * @dataProvider per_page_provider
	 *
	 * @param mixed  $value    Value.
	 * @param string $expected Error code.
	 */
	public function test_validate_per_page( $value, string $expected ) {
		$this->assertSame( $expected, Feed_Validator::validate_per_page( $value ) );
	}

	/**
	 * Data for test_validate_per_page().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function per_page_provider(): array {
		return array(
			'one'            => array( 1, Feed_Validator::VALID ),
			'integer'        => array( 50, Feed_Validator::VALID ),
			'numeric string' => array( '50', Feed_Validator::VALID ),
			'large'          => array( 100000, Feed_Validator::VALID ),
			'zero'           => array( 0, Feed_Validator::ERROR_TOO_SMALL ),
			'zero string'    => array( '0', Feed_Validator::ERROR_TOO_SMALL ),
			'negative'       => array( -5, Feed_Validator::ERROR_TOO_SMALL ),
			'negative string' => array( '-5', Feed_Validator::ERROR_TOO_SMALL ),
			'float'          => array( 2.5, Feed_Validator::ERROR_NOT_INTEGER ),
			'float string'   => array( '2.5', Feed_Validator::ERROR_NOT_INTEGER ),
			'text'           => array( 'many', Feed_Validator::ERROR_NOT_INTEGER ),
			'empty'          => array( '', Feed_Validator::ERROR_NOT_INTEGER ),
			'null'           => array( null, Feed_Validator::ERROR_NOT_INTEGER ),
			'boolean'        => array( true, Feed_Validator::ERROR_NOT_INTEGER ),
			'array'          => array( array( 10 ), Feed_Validator::ERROR_NOT_INTEGER ),
			'overflow'       => array( '99999999999999999999', Feed_Validator::ERROR_NOT_INTEGER ),
		);
	}

	/**
	 * The access is `public` or `authenticated`; any other value is refused, never guessed.
	 *
	 * @dataProvider access_provider
	 *
	 * @param mixed  $value    Value.
	 * @param string $expected Error code.
	 */
	public function test_validate_access( $value, string $expected ) {
		$this->assertSame( $expected, Feed_Validator::validate_access( $value ) );
	}

	/**
	 * Data for test_validate_access().
	 *
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public function access_provider(): array {
		return array(
			'public'        => array( 'public', Feed_Validator::VALID ),
			'authenticated' => array( 'authenticated', Feed_Validator::VALID ),
			'unknown'       => array( 'everyone', Feed_Validator::ERROR_NOT_ALLOWED ),
			'wrong case'    => array( 'Public', Feed_Validator::ERROR_NOT_ALLOWED ),
			'empty'         => array( '', Feed_Validator::ERROR_NOT_ALLOWED ),
			'null'          => array( null, Feed_Validator::ERROR_NOT_ALLOWED ),
			'array'         => array( array( 'public' ), Feed_Validator::ERROR_NOT_ALLOWED ),
		);
	}

	/**
	 * The source post type must be in the list given by the caller.
	 */
	public function test_validate_post_type_uses_the_allow_list() {
		$allowed = array( 'post', 'page', 'product' );

		$this->assertSame( Feed_Validator::VALID, Feed_Validator::validate_post_type( 'product', $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( 'attachment', $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( 'Product', $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( 'post ', $allowed ) );
	}

	/**
	 * An empty or non-string post type is refused; an empty allow-list refuses everything.
	 */
	public function test_validate_post_type_rejects_empty_and_non_strings() {
		$allowed = array( 'post' );

		$this->assertSame( Feed_Validator::ERROR_EMPTY, Feed_Validator::validate_post_type( '', $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( null, $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( array( 'post' ), $allowed ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( 'post', array() ) );
	}

	/**
	 * A numeric-looking post type is compared as a string, never loosely.
	 */
	public function test_validate_post_type_compares_strictly() {
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( '0', array( 'post' ) ) );
		$this->assertSame( Feed_Validator::ERROR_NOT_ALLOWED, Feed_Validator::validate_post_type( 'post', array( 0 ) ) );
	}
}

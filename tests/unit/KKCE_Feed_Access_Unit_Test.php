<?php
/**
 * Unit tests of the access rule of a feed (pure logic, no mocks).
 *
 * @package KKCE
 */

use KKCE\Rest\Feed_Access;

/**
 * Who can read a feed: anybody when it is public, otherwise a request authenticated with an Application
 * Password of a user with the capability of the feeds.
 */
class KKCE_Feed_Access_Unit_Test extends KKCE_Unit_Test_Case {

	/**
	 * Every combination of the four conditions.
	 *
	 * @dataProvider decision_provider
	 *
	 * @param bool   $public_feed  Whether the access of the feed is public.
	 * @param bool   $require_auth Whether the settings require authentication for every feed.
	 * @param bool   $app_password Whether the request is authenticated with an Application Password.
	 * @param bool   $capability   Whether the user has the capability of the feeds.
	 * @param string $expected     Decision.
	 */
	public function test_decide( bool $public_feed, bool $require_auth, bool $app_password, bool $capability, string $expected ) {
		$this->assertSame( $expected, Feed_Access::decide( $public_feed, $require_auth, $app_password, $capability ) );
	}

	/**
	 * Data for test_decide().
	 *
	 * @return array<string, array{0: bool, 1: bool, 2: bool, 3: bool, 4: string}>
	 */
	public function decision_provider(): array {
		return array(
			'public feed, anonymous'                       => array( true, false, false, false, Feed_Access::ALLOWED ),
			'public feed, user with the capability'        => array( true, false, true, true, Feed_Access::ALLOWED ),
			'public feed, required by settings, anonymous' => array( true, true, false, false, Feed_Access::UNAUTHENTICATED ),
			'public feed, required by settings, allowed'   => array( true, true, true, true, Feed_Access::ALLOWED ),
			'authenticated feed, anonymous'                => array( false, false, false, false, Feed_Access::UNAUTHENTICATED ),
			'authenticated feed, signed in another way'    => array( false, false, false, true, Feed_Access::UNAUTHENTICATED ),
			'authenticated feed, without the capability'   => array( false, false, true, false, Feed_Access::FORBIDDEN ),
			'authenticated feed, with the capability'      => array( false, false, true, true, Feed_Access::ALLOWED ),
			'both required, without the capability'        => array( false, true, true, false, Feed_Access::FORBIDDEN ),
		);
	}
}

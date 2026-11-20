<?php
/**
 * Access rule of a feed.
 *
 * @package KKCE
 */

namespace KKCE\Rest;

defined( 'ABSPATH' ) || exit;

/**
 * Who can read a feed.
 *
 * Pure logic: no WordPress function is called; `Feed_Routes` reads the conditions and turns the decision
 * into a response. A feed is open to anybody when its access is public and the settings do not require
 * authentication for every feed. Otherwise the request must be authenticated with an Application Password
 * (a session cookie or another authentication method is not enough, also for an administrator), and its
 * user must have the capability of the feeds. Authentication decides only who reads: the data are the same.
 */
final class Feed_Access {

	/**
	 * The request can read the feed.
	 */
	public const ALLOWED = 'allowed';

	/**
	 * The request is not authenticated with an Application Password: 401.
	 */
	public const UNAUTHENTICATED = 'unauthenticated';

	/**
	 * The user of the Application Password does not have the capability of the feeds: 403.
	 */
	public const FORBIDDEN = 'forbidden';

	/**
	 * Decision for a request.
	 *
	 * @param bool $public_feed  Whether the access of the feed is public.
	 * @param bool $require_auth Whether the settings require authentication for every feed.
	 * @param bool $app_password Whether the request is authenticated with an Application Password.
	 * @param bool $capability   Whether the user of the request has the capability of the feeds.
	 * @return string One of `ALLOWED`, `UNAUTHENTICATED`, `FORBIDDEN`.
	 */
	public static function decide( bool $public_feed, bool $require_auth, bool $app_password, bool $capability ): string {
		if ( $public_feed && ! $require_auth ) {
			return self::ALLOWED;
		}

		if ( ! $app_password ) {
			return self::UNAUTHENTICATED;
		}

		return $capability ? self::ALLOWED : self::FORBIDDEN;
	}
}

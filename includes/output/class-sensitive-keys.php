<?php
/**
 * Recognition of the names that look like secrets or personal data.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Tells whether the name of a field looks sensitive: a password, a token, a key of a service, an
 * e-mail address, a login.
 *
 * The check reads the **name**, never the value: it is a guard against configuration mistakes (picking
 * `_ticket_api_key` instead of `_ticket_price`), not a guarantee, and a secret stored in a field with an
 * innocent name is not recognized. The name is split into words (on `_`, `-`, `.`, `:` and other
 * separators, and where a lowercase letter or a digit is followed by an uppercase one), so `api_key`,
 * `apiKey` and `APIKey` are sensitive while `keyword` and `passage` are not: only whole words count.
 *
 * A sensitive source is not refused: the mapping must confirm it explicitly, source by source (decision
 * of 2026-11-10). Borderline names such as `token_count` or `email_subject` are sensitive on purpose:
 * they only ask for a confirmation. Pure logic: no WordPress function is called.
 */
final class Sensitive_Keys {

	/**
	 * Words that make a name sensitive on their own.
	 */
	public const WORDS = array(
		'password',
		'passwd',
		'pass',
		'pwd',
		'apikey',
		'secret',
		'token',
		'tokens',
		'nonce',
		'salt',
		'hash',
		'session',
		'sessions',
		'capabilities',
		'credential',
		'credentials',
		'email',
		'login',
	);

	/**
	 * Pairs of adjacent words that make a name sensitive. `key` and `level` alone do not
	 * (`sort_key`, `difficulty_level`).
	 */
	public const PAIRS = array(
		array( 'api', 'key' ),
		array( 'private', 'key' ),
		array( 'secret', 'key' ),
		array( 'access', 'key' ),
		array( 'activation', 'key' ),
		array( 'user', 'level' ),
	);

	/**
	 * Whether a name looks sensitive.
	 *
	 * @param string $name Name of a field (a meta key, the name of an ACF or CMB2 field).
	 * @return bool
	 */
	public static function is_sensitive( string $name ): bool {
		$words = self::words( $name );

		foreach ( $words as $index => $word ) {
			if ( in_array( $word, self::WORDS, true ) ) {
				return true;
			}

			$next = $words[ $index + 1 ] ?? null;
			if ( null !== $next && in_array( array( $word, $next ), self::PAIRS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a source identifier (`group.name`) has a sensitive name. The group is not judged.
	 *
	 * @param string $source Source identifier.
	 * @return bool False for an identifier without a group.
	 */
	public static function is_sensitive_source( string $source ): bool {
		$parts = explode( '.', $source, 2 );

		return 2 === count( $parts ) && self::is_sensitive( $parts[1] );
	}

	/**
	 * Lowercase words of a name.
	 *
	 * @param string $name Name.
	 * @return list<string>
	 */
	private static function words( string $name ): array {
		// "apiKey" → "api Key", "APIKey" → "API Key".
		$spaced = (string) preg_replace( '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $name );
		$words  = preg_split( '/[^A-Za-z0-9]+/', strtolower( $spaced ), -1, PREG_SPLIT_NO_EMPTY );

		return is_array( $words ) ? $words : array();
	}
}

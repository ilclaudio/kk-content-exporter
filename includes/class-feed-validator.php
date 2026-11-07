<?php
/**
 * Pure validation of the settings of a feed.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the values entered for a feed.
 *
 * Pure logic: it calls no WordPress function and receives everything it needs as arguments, so it
 * is tested without WordPress. Every method returns an error code, or `self::VALID` (an empty
 * string) when the value is acceptable. The messages for the administrator are written by the
 * editor, which is the only place that shows them.
 *
 * `Feed_Post_Type` sanitizes values on write (it normalizes, never refuses); this class decides
 * whether a value may be saved at all.
 */
final class Feed_Validator {

	/**
	 * Result of a valid value.
	 */
	public const VALID = '';

	/**
	 * Error: the value is missing.
	 */
	public const ERROR_EMPTY = 'empty';

	/**
	 * Error: the slug contains characters or a layout that is not allowed.
	 */
	public const ERROR_FORMAT = 'format';

	/**
	 * Error: the slug is longer than the maximum length.
	 */
	public const ERROR_TOO_LONG = 'too_long';

	/**
	 * Error: the slug is reserved for the plugin.
	 */
	public const ERROR_RESERVED = 'reserved';

	/**
	 * Error: the slug is already used by another feed.
	 */
	public const ERROR_TAKEN = 'taken';

	/**
	 * Error: the value is not an integer.
	 */
	public const ERROR_NOT_INTEGER = 'not_integer';

	/**
	 * Error: the integer is smaller than the minimum.
	 */
	public const ERROR_TOO_SMALL = 'too_small';

	/**
	 * Error: the value is not in the list of allowed values.
	 */
	public const ERROR_NOT_ALLOWED = 'not_allowed';

	/**
	 * Maximum length of a slug.
	 */
	public const SLUG_MAX_LENGTH = 64;

	/**
	 * Slugs that a feed cannot use.
	 *
	 * The first group keeps room for fixed routes under `kkce/v1` that the plugin may add later without
	 * clashing with existing feeds. The second group are the flat slugs that WordPress renames on its
	 * own (`wp_unique_post_slug()` adds "-2"): refusing them keeps the rule that a slug is never
	 * changed silently.
	 */
	public const RESERVED_SLUGS = array(
		'feeds',
		'settings',
		'preview',
		'log',
		'logs',
		'schema',
		'status',
		'health',
		'index',
		'feed',
		'rss',
		'rss2',
		'rdf',
		'atom',
		'embed',
	);

	/**
	 * Validates the slug of a feed, which is the last segment of its endpoint URL.
	 *
	 * Checks, in this order: not empty, format (lowercase letters and digits, single dashes between
	 * them), length, reserved words, uniqueness.
	 *
	 * @param mixed        $slug           Slug entered by the administrator.
	 * @param array<mixed> $existing_slugs Slugs of the other feeds (the feed being edited excluded).
	 * @return string `self::VALID` or an error code.
	 */
	public static function validate_slug( $slug, array $existing_slugs ): string {
		if ( '' === $slug ) {
			return self::ERROR_EMPTY;
		}

		if ( ! is_string( $slug ) || 1 !== preg_match( '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug ) ) {
			return self::ERROR_FORMAT;
		}

		if ( strlen( $slug ) > self::SLUG_MAX_LENGTH ) {
			return self::ERROR_TOO_LONG;
		}

		if ( in_array( $slug, self::RESERVED_SLUGS, true ) ) {
			return self::ERROR_RESERVED;
		}

		if ( in_array( $slug, $existing_slugs, true ) ) {
			return self::ERROR_TAKEN;
		}

		return self::VALID;
	}

	/**
	 * Validates the number of items per page: an integer of at least 1.
	 *
	 * The upper limit is a global setting and is applied where the setting is known.
	 *
	 * @param mixed $value Value entered by the administrator.
	 * @return string `self::VALID` or an error code.
	 */
	public static function validate_per_page( $value ): string {
		if ( is_string( $value ) ) {
			$value = filter_var( $value, FILTER_VALIDATE_INT );
		}

		if ( ! is_int( $value ) ) {
			return self::ERROR_NOT_INTEGER;
		}

		return $value < 1 ? self::ERROR_TOO_SMALL : self::VALID;
	}

	/**
	 * Validates the access: exactly `public` or `authenticated`.
	 *
	 * Anything else is refused and never guessed, so that a damaged or tampered value cannot open a
	 * feed that was restricted.
	 *
	 * @param mixed $value Value entered by the administrator.
	 * @return string `self::VALID` or an error code.
	 */
	public static function validate_access( $value ): string {
		if ( Feed_Post_Type::ACCESS_PUBLIC === $value || Feed_Post_Type::ACCESS_AUTHENTICATED === $value ) {
			return self::VALID;
		}

		return self::ERROR_NOT_ALLOWED;
	}

	/**
	 * Validates the source post type against the list of selectable post types.
	 *
	 * The list (public post types, `attachment` excluded) is built by the caller, because reading it
	 * needs WordPress.
	 *
	 * @param mixed        $value   Post type entered by the administrator.
	 * @param array<mixed> $allowed Post types that can be selected.
	 * @return string `self::VALID` or an error code.
	 */
	public static function validate_post_type( $value, array $allowed ): string {
		if ( '' === $value ) {
			return self::ERROR_EMPTY;
		}

		if ( ! is_string( $value ) || ! in_array( $value, $allowed, true ) ) {
			return self::ERROR_NOT_ALLOWED;
		}

		return self::VALID;
	}
}

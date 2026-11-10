<?php
/**
 * Configuration meta of a feed: keys, registration, sanitizers and readers.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Keys and sanitizers of the post meta that store the configuration of a `kkce_feed`.
 *
 * Separate from `Feed_Post_Type` (the registration of the post type itself) because the meta of a feed
 * are many and grow with the mapping. The meta are registered on `init` by `register()`.
 */
final class Feed_Meta {

	/**
	 * Meta key: post types the feed reads its contents from. Multiple meta: one row per post type.
	 */
	public const META_POST_TYPE = '_kkce_post_type';

	/**
	 * Meta key: mapping of the feed, as JSON text (see `Output\Mapping`). Empty when the feed has none.
	 */
	public const META_MAPPING = '_kkce_mapping';

	/**
	 * Meta key: who can read the feed (`public` or `authenticated`).
	 */
	public const META_ACCESS = '_kkce_access';

	/**
	 * Meta key: whether the endpoint of the feed answers.
	 */
	public const META_ACTIVE = '_kkce_active';

	/**
	 * Meta key: items per page.
	 */
	public const META_PER_PAGE = '_kkce_per_page';

	/**
	 * Access value: anyone can read the feed.
	 */
	public const ACCESS_PUBLIC = 'public';

	/**
	 * Access value: only an authenticated user with the export capability can read the feed.
	 */
	public const ACCESS_AUTHENTICATED = 'authenticated';

	/**
	 * Default number of items per page.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Maximum length of a post type key in WordPress.
	 */
	private const POST_TYPE_MAX_LENGTH = 20;

	/**
	 * Registers the configuration meta of a feed. Hooked on `init` by `Plugin::boot()`.
	 *
	 * The meta are never exposed in REST, are sanitized on every write and can be edited only by
	 * users who can edit the feed.
	 *
	 * The source post types are a multiple meta (one row per post type, sanitized row by row, no
	 * serialization), so feeds can be searched by post type with a meta query. It has no default: for a
	 * multiple meta WordPress would return the default as a list of one value. Read it with `post_types()`.
	 *
	 * @return void
	 */
	public static function register(): void {
		$common = array(
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => array( self::class, 'can_edit_meta' ),
		);

		register_post_meta(
			Feed_Post_Type::POST_TYPE,
			self::META_POST_TYPE,
			array(
				'single'            => false,
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_post_type' ),
			) + $common
		);
		register_post_meta(
			Feed_Post_Type::POST_TYPE,
			self::META_MAPPING,
			$common + array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_mapping' ),
			)
		);
		register_post_meta(
			Feed_Post_Type::POST_TYPE,
			self::META_ACCESS,
			$common + array(
				'type'              => 'string',
				'default'           => self::ACCESS_PUBLIC,
				'sanitize_callback' => array( self::class, 'sanitize_access' ),
			)
		);
		register_post_meta(
			Feed_Post_Type::POST_TYPE,
			self::META_ACTIVE,
			$common + array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_active' ),
			)
		);
		register_post_meta(
			Feed_Post_Type::POST_TYPE,
			self::META_PER_PAGE,
			$common + array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'sanitize_callback' => array( self::class, 'sanitize_per_page' ),
			)
		);
	}

	/**
	 * Source post types of a feed, as a clean list in the saved order.
	 *
	 * A feed saved with one row (also an empty one, as the first version did) gives a list of one post
	 * type, or an empty list.
	 *
	 * @param int $feed_id ID of the feed.
	 * @return list<string>
	 */
	public static function post_types( int $feed_id ): array {
		return self::sanitize_post_types( get_post_meta( $feed_id, self::META_POST_TYPE, false ) );
	}

	/**
	 * Mapping of a feed, read back and checked again.
	 *
	 * The text is checked on every read, not only on write: a value stored outside the sanitizer (damaged
	 * database, direct write) gives an empty mapping, never a half-read one.
	 *
	 * @param int $feed_id ID of the feed.
	 * @return array{version: int, rows: list<array<string, mixed>>}
	 */
	public static function mapping( int $feed_id ): array {
		return Output\Mapping_Validator::parse( get_post_meta( $feed_id, self::META_MAPPING, true ) ) ?? Output\Mapping::blank();
	}

	/**
	 * Saves the mapping of a feed from its JSON text.
	 *
	 * WordPress removes the backslashes of a meta value, and a JSON text has many (`\"`, `è`): the text
	 * goes through `wp_slash()` so that it is stored as it is. The sanitizer still checks it, and stores an
	 * empty string when the mapping is not valid: callers that must report the errors validate first.
	 *
	 * @param int    $feed_id ID of the feed.
	 * @param string $json    JSON text of the mapping.
	 * @return void
	 */
	public static function save_mapping( int $feed_id, string $json ): void {
		update_post_meta( $feed_id, self::META_MAPPING, wp_slash( $json ) );
	}

	/**
	 * Whether a user can edit the configuration meta of a feed.
	 *
	 * Used as `auth_callback` of the protected meta: it follows the capabilities of the post type.
	 *
	 * @param mixed $allowed  Result of the default check (ignored).
	 * @param mixed $meta_key Meta key (ignored).
	 * @param mixed $post_id  ID of the feed.
	 * @param mixed $user_id  ID of the user.
	 * @return bool
	 */
	public static function can_edit_meta( $allowed, $meta_key, $post_id, $user_id ): bool {
		unset( $allowed, $meta_key );

		return user_can( (int) $user_id, 'edit_post', (int) $post_id );
	}

	/**
	 * Normalizes the key of the source post type: lowercase letters, digits, dash and underscore.
	 *
	 * Only the format is normalized here; checking that the post type exists and is public belongs
	 * to the validation of the feed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_type( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$key = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );

		return substr( (string) $key, 0, self::POST_TYPE_MAX_LENGTH );
	}

	/**
	 * Normalizes a list of source post types: every value with `sanitize_post_type()`, then without
	 * empty values and duplicates, in the original order. Anything that is not an array gives an
	 * empty list.
	 *
	 * @param mixed $values Raw values.
	 * @return list<string>
	 */
	public static function sanitize_post_types( $values ): array {
		if ( ! is_array( $values ) ) {
			return array();
		}

		$keys = array_map( array( self::class, 'sanitize_post_type' ), $values );

		return array_values( array_unique( array_filter( $keys, static fn( string $key ): bool => '' !== $key ) ) );
	}

	/**
	 * Checks a mapping before it is stored and gives the canonical JSON text of a valid one.
	 *
	 * A sanitizer cannot refuse a value, so a text that is not a valid mapping (damaged JSON, unknown
	 * version or kind, a rule that breaks the format) gives an empty string: no mapping, which is always
	 * safe because nothing is exported without a mapping. The editor reports the errors before saving.
	 * The text is encoded as WordPress does (ASCII only, `\uXXXX` for accents), so it does not depend on the
	 * character set of the database. A canonical text larger than `Mapping::MAX_BYTES` is not stored either.
	 *
	 * @param mixed $value Raw value.
	 * @return string Canonical JSON text, or an empty string.
	 */
	public static function sanitize_mapping( $value ): string {
		$mapping = Output\Mapping_Validator::parse( $value );
		if ( null === $mapping ) {
			return '';
		}

		// The canonical text can be larger than the submitted one (accents become `\uXXXX`): a text above
		// the limit would be refused when read, and the feed would lose its mapping without notice.
		$json = wp_json_encode( $mapping );

		return is_string( $json ) && strlen( $json ) <= Output\Mapping::MAX_BYTES ? $json : '';
	}

	/**
	 * Restricts the access to the two known values, failing closed: only the exact value `public`
	 * opens a feed, anything else (unknown, empty, wrong case, damaged) gives `authenticated`.
	 *
	 * A feed without the meta is still public: WordPress returns the registered default `public`
	 * when no value is stored, so this function only judges values that are actually present.
	 *
	 * @param mixed $value Raw value.
	 * @return string `public` or `authenticated`.
	 */
	public static function sanitize_access( $value ): string {
		return self::ACCESS_PUBLIC === $value ? self::ACCESS_PUBLIC : self::ACCESS_AUTHENTICATED;
	}

	/**
	 * Converts the active flag to a strict boolean: only `true`, `1` and `'1'` enable the feed.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_active( $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Converts the items per page to an integer of at least 1; anything else gives the default.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_per_page( $value ): int {
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$value = (int) $value;
		}

		if ( ! is_int( $value ) || $value < 1 ) {
			return self::DEFAULT_PER_PAGE;
		}

		return $value;
	}
}

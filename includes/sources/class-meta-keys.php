<?php
/**
 * Rules on the post meta keys a mapping can read.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * Which post meta keys can be a source, and the declared type of a registered key.
 *
 * Pure logic: no WordPress function is called. A key is selectable when it has the form a source
 * identifier allows and is not an internal key of WordPress or of this plugin. The catalog lists the keys
 * found in the database and the registered ones; any selectable key typed by hand is also valid (decision
 * of 2026-11-17), because the list is only a sample: a key without a value in an item gives `null`.
 * Whether the name looks sensitive is judged by `Output\Sensitive_Keys`, as for every source.
 */
final class Meta_Keys {

	/**
	 * Group of the source identifiers of post meta (`meta.<key>`).
	 */
	public const GROUP = 'meta';

	/**
	 * Declared type of a key that is not registered: text, like a meta saved by hand.
	 */
	public const UNREGISTERED_TYPE = 'string';

	/**
	 * Longest key accepted, so the identifier stays within the limit of a source identifier.
	 */
	public const MAX_LENGTH = 191;

	/**
	 * Prefixes of the internal keys: edit locks and authors (`_edit_*`), the data of WordPress (`_wp_*`),
	 * the cache of oEmbed (`_oembed_*`) and the configuration of the feeds of this plugin (`_kkce_*`).
	 */
	public const INTERNAL_PREFIXES = array( '_edit_', '_wp_', '_oembed_', '_kkce_' );

	/**
	 * Internal keys with no common prefix.
	 */
	public const INTERNAL_KEYS = array( '_thumbnail_id' );

	/**
	 * Whether a key has the form a source identifier allows: 1 to `MAX_LENGTH` characters among letters,
	 * digits and `_ . : -` (the same characters as `Mapping::SOURCE_PATTERN`).
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_valid( string $key ): bool {
		return strlen( $key ) <= self::MAX_LENGTH && 1 === preg_match( '/\A[A-Za-z0-9_.:-]+\z/', $key );
	}

	/**
	 * Whether a key is internal to WordPress or to this plugin.
	 *
	 * ACF also stores a hidden reference next to every field (`_name`, holding `field_xxxx`): that is told by
	 * the value, so it is left out of the list found in the database, not here.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_internal( string $key ): bool {
		if ( in_array( $key, self::INTERNAL_KEYS, true ) ) {
			return true;
		}

		foreach ( self::INTERNAL_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a key starts with an underscore: WordPress hides these from the Custom Fields box.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_hidden( string $key ): bool {
		return str_starts_with( $key, '_' );
	}

	/**
	 * Whether a key can be a source: well formed and not internal.
	 *
	 * @param string $key Meta key.
	 * @return bool
	 */
	public static function is_selectable( string $key ): bool {
		return self::is_valid( $key ) && ! self::is_internal( $key );
	}

	/**
	 * Key of a source identifier of the meta group.
	 *
	 * @param string $source Source identifier (`group.name`).
	 * @return string|null The key, or null for another group or a key that is not selectable.
	 */
	public static function key_from_source( string $source ): ?string {
		$prefix = self::GROUP . '.';
		if ( ! str_starts_with( $source, $prefix ) ) {
			return null;
		}

		$key = substr( $source, strlen( $prefix ) );

		return self::is_selectable( $key ) ? $key : null;
	}

	/**
	 * Declared type of a key from the type it was registered with.
	 *
	 * @param mixed $registered Type given to `register_meta()` (`string`, `integer`, `number`, `boolean`,
	 *                          `array`, `object`), or anything else when unknown.
	 * @return string|null The declared type, or null for a structure (`array`, `object`), which is not exported.
	 */
	public static function declared_type( $registered ): ?string {
		return match ( $registered ) {
			'integer', 'number' => 'number',
			'boolean'           => 'boolean',
			'array', 'object'   => null,
			default             => self::UNREGISTERED_TYPE,
		};
	}
}

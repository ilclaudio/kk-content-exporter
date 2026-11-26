<?php
/**
 * Provider of the post meta of the items.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the post meta a mapping can read for a post type, and reads their values.
 *
 * The catalog lists, for every post type of the feed, the keys registered with `register_meta()` (type
 * and structure known) and, only when the administrator opens it, a sample of the keys found on the
 * published items (`SAMPLE_LIMIT`, one limited query). The internal keys, the references that ACF stores
 * next to its fields and the keys that are not well formed are left out (`Meta_Keys`). Any other key
 * can also be typed by hand (`Catalog::types_for()`): this class reads whatever the mapping names.
 *
 * Only simple values are exported: a text, a number or a boolean. A structure (a serialized array, an
 * object) and a key that does not exist in the item give no value. When a key has several values the first
 * one is read.
 */
final class Meta_Provider {

	/**
	 * Most distinct keys read from the database for a post type.
	 */
	public const SAMPLE_LIMIT = 200;

	/**
	 * Group of the cache of the sampled keys.
	 */
	private const CACHE_GROUP = 'kkce_meta_keys';

	/**
	 * Sources of the post meta of a post type: the registered keys, then the sampled ones.
	 *
	 * @param string   $post_type Post type.
	 * @param bool     $discover  Whether to also read the keys in use from the database. Only the screen that lists
	 *                            the fields needs it: the check of a mapping accepts any selectable key anyway.
	 * @param string[] $extra   Keys to list as well, typed by the administrator or used by the saved mapping;
	 *                          the ones that are not selectable are ignored.
	 * @return list<Source>
	 *
	 * @phpstan-param list<string> $extra
	 */
	public static function sources( string $post_type, bool $discover, array $extra = array() ): array {
		$sources = array();

		$registered = self::registrations( $post_type );
		foreach ( self::registered( $registered ) as $key => $type ) {
			$sources[ $key ] = new Source( Meta_Keys::GROUP . '.' . $key, $key, $post_type, $type );
		}

		if ( $discover ) {
			foreach ( self::sampled_keys( $post_type ) as $key ) {
				// A registered key is already listed, or left out as a structure: never as text.
				if ( ! isset( $registered[ $key ] ) ) {
					$sources[ $key ] ??= new Source( Meta_Keys::GROUP . '.' . $key, $key, $post_type, Meta_Keys::UNREGISTERED_TYPE );
				}
			}
		}

		foreach ( $extra as $key ) {
			$type = Meta_Keys::declared_type( $registered[ $key ]['type'] ?? null );
			if ( null !== $type && Meta_Keys::is_selectable( $key ) ) {
				$sources[ $key ] ??= new Source( Meta_Keys::GROUP . '.' . $key, $key, $post_type, $type );
			}
		}

		return array_values( $sources );
	}

	/**
	 * Value of a meta source for a post.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $id   Source identifier (`meta.<key>`).
	 * @return string|int|float|bool|null The value as stored, or null when the key is not selectable, the post
	 *                                    has no such meta, or the value is a structure.
	 */
	public static function value( WP_Post $post, string $id ) {
		$key = Meta_Keys::key_from_source( $id );
		if ( null === $key || ! metadata_exists( 'post', $post->ID, $key ) ) {
			return null;
		}

		$value = get_post_meta( $post->ID, $key, true );

		return is_scalar( $value ) ? $value : null;
	}

	/**
	 * Declared types of the meta sources of a post type: the registered one, text for the others.
	 *
	 * The registrations are read once, when the function is made, and never kept beyond it: a key registered
	 * later counts for the next function.
	 *
	 * @param string $post_type Post type.
	 * @return callable(string): ?string Declared type of a source identifier (`meta.<key>`); null when the
	 *                                   identifier is not a selectable meta key, or the key is a structure.
	 */
	public static function declared_types( string $post_type ): callable {
		$registered = self::registrations( $post_type );

		return static function ( string $id ) use ( $registered ): ?string {
			$key = Meta_Keys::key_from_source( $id );

			return null === $key ? null : Meta_Keys::declared_type( $registered[ $key ]['type'] ?? null );
		};
	}

	/**
	 * Meta registrations that apply to a post type, as WordPress reads them: those for every post type
	 * (`register_meta( 'post', ... )` without a subtype, as many plugins do) and those of the post type, which come
	 * first for the same key.
	 *
	 * @param string $post_type Post type.
	 * @return array<mixed> Arguments of the registration by key.
	 */
	private static function registrations( string $post_type ): array {
		// `+` keeps the keys (`array_merge()` would renumber a numeric meta key) and the left side wins.
		return get_registered_meta_keys( 'post', $post_type ) + get_registered_meta_keys( 'post', '' );
	}

	/**
	 * Selectable registered keys, with their declared types. Structures are left out.
	 *
	 * @param array<mixed> $registrations Registrations that apply to the post type (`registrations()`).
	 * @return array<string, string> Declared type by key.
	 */
	private static function registered( array $registrations ): array {
		$keys = array();

		foreach ( $registrations as $key => $args ) {
			$key  = (string) $key;
			$type = Meta_Keys::declared_type( $args['type'] ?? null );
			if ( null !== $type && Meta_Keys::is_selectable( $key ) ) {
				$keys[ $key ] = $type;
			}
		}

		return $keys;
	}

	/**
	 * Distinct keys found on the published items of a post type, at most `SAMPLE_LIMIT`, in alphabetical order.
	 *
	 * The internal keys are left out by the query itself (the cache of oEmbed alone can hold hundreds of keys,
	 * which would fill the sample), together with the hidden references of ACF: a key that starts with an
	 * underscore and whose value is `field_...`. The result is checked again with `Meta_Keys`.
	 *
	 * @param string $post_type Post type.
	 * @return list<string>
	 */
	private static function sampled_keys( string $post_type ): array {
		global $wpdb;

		$cache_key = $post_type;
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return array_values( array_map( 'strval', $cached ) );
		}

		// One placeholder for each of the four prefixes of `Meta_Keys::INTERNAL_PREFIXES`; a unit test keeps them equal.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- No WordPress function lists the distinct meta keys of a post type; the query is prepared, limited and cached.
		$found = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s AND pm.meta_key NOT LIKE %s
				AND pm.meta_key <> %s
				AND NOT ( pm.meta_key LIKE %s AND pm.meta_value LIKE %s )
				GROUP BY pm.meta_key ORDER BY pm.meta_key ASC LIMIT %d",
				$post_type,
				$wpdb->esc_like( Meta_Keys::INTERNAL_PREFIXES[0] ) . '%',
				$wpdb->esc_like( Meta_Keys::INTERNAL_PREFIXES[1] ) . '%',
				$wpdb->esc_like( Meta_Keys::INTERNAL_PREFIXES[2] ) . '%',
				$wpdb->esc_like( Meta_Keys::INTERNAL_PREFIXES[3] ) . '%',
				Meta_Keys::INTERNAL_KEYS[0],
				$wpdb->esc_like( '_' ) . '%',
				$wpdb->esc_like( 'field_' ) . '%',
				self::SAMPLE_LIMIT
			)
		);

		$keys = array();
		foreach ( is_array( $found ) ? $found : array() as $key ) {
			if ( is_string( $key ) && Meta_Keys::is_selectable( $key ) ) {
				$keys[] = $key;
			}
		}

		wp_cache_set( $cache_key, $keys, self::CACHE_GROUP, MINUTE_IN_SECONDS );

		return $keys;
	}
}

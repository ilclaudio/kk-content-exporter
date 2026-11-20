<?php
/**
 * Post types a feed can export.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The one rule on the post types a feed can read: the public ones, `attachment` excluded.
 *
 * `selectable()` is the only place that asks WordPress for them; `split()` is pure and divides a request
 * against a list already read, so a caller reads the list once. Used by the feed editor and the admin
 * routes of the mapping editor.
 */
final class Exportable_Post_Types {

	/**
	 * Post types a feed can read from: the public ones, `attachment` excluded.
	 *
	 * @return array<string, string> Post type key => label, sorted by label.
	 */
	public static function selectable(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' !== $name ) {
				$types[ $name ] = $type->label;
			}
		}

		asort( $types, SORT_NATURAL | SORT_FLAG_CASE );

		return $types;
	}

	/**
	 * Splits the requested post types into the exportable ones and the others, without duplicates.
	 *
	 * @param mixed                 $requested  Requested post types: a list of keys, or a single key.
	 * @param array<string, string> $selectable Exportable post types, as `selectable()` gives them.
	 * @return array{0: list<string>, 1: list<string>} Exportable post types and the others, in the order of the request.
	 */
	public static function split( $requested, array $selectable ): array {
		$available   = array();
		$unavailable = array();

		foreach ( array_unique( array_map( 'strval', (array) $requested ) ) as $post_type ) {
			if ( isset( $selectable[ $post_type ] ) ) {
				$available[] = $post_type;
			} else {
				$unavailable[] = $post_type;
			}
		}

		return array( $available, $unavailable );
	}
}

<?php
/**
 * Export of one item with the mapping of a feed.
 *
 * @package KKCE
 */

namespace KKCE\Output;

use KKCE\Sources\Core_Provider;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Computes the exported object of one item: the link between the rules (`Rule_Evaluator`) and WordPress.
 *
 * The values are computed as an anonymous visitor: the current user becomes nobody for the whole item
 * and is always restored, also when the computation fails. So dynamic blocks, shortcodes and filters
 * that depend on the user give what a visitor sees, and an authenticated feed or the preview of an
 * administrator export the same data as a public feed. Every source is read at most once per item, and
 * the values are normalized to the types of the rows (declared types of the sources for `auto`).
 */
final class Item_Exporter {

	/**
	 * Object exported for an item.
	 *
	 * @param WP_Post      $post    Item.
	 * @param array<mixed> $mapping Mapping of the feed (canonical form).
	 * @return array<string, mixed>
	 */
	public static function export( WP_Post $post, array $mapping ): array {
		$previous = get_current_user_id();
		$values   = array();
		$read     = static function ( string $source ) use ( $post, &$values ) {
			if ( ! array_key_exists( $source, $values ) ) {
				$values[ $source ] = self::read( $post, $source );
			}

			return $values[ $source ];
		};

		wp_set_current_user( 0 );

		try {
			return Rule_Evaluator::evaluate_mapping( $mapping, $post->post_type, $read, self::declared_types(), self::origin() );
		} finally {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Declared type of the known sources, for the rows with the type `auto`.
	 *
	 * @return callable(string): ?string Gives null for a source of unknown type (it counts as text).
	 */
	private static function declared_types(): callable {
		$types = array();
		foreach ( Core_Provider::sources() as $source ) {
			$types[ $source->id ] = $source->type;
		}

		return static fn( string $source ): ?string => $types[ $source ] ?? null;
	}

	/**
	 * Origin of the site (scheme, host and port of the home URL), to make root-relative URLs absolute.
	 *
	 * @return string Empty when the home URL cannot be read.
	 */
	private static function origin(): string {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}

		return $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}

	/**
	 * Value of a source for an item, from the provider of its group.
	 *
	 * @param WP_Post $post   Item.
	 * @param string  $source Source identifier.
	 * @return mixed The value, or null when the source does not exist (its provider is not available yet,
	 *               or it has no value for this item).
	 */
	private static function read( WP_Post $post, string $source ) {
		return match ( explode( '.', $source, 2 )[0] ) {
			'core'  => Core_Provider::value( $post, $source ),
			default => null,
		};
	}
}

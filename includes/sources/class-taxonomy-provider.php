<?php
/**
 * Provider of the taxonomies of the items.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

use WP_Post;
use WP_Taxonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the taxonomy sources of a post type and reads their values.
 *
 * For every public taxonomy of the post type (`public => true`; the internal taxonomies of Polylang are left out, the
 * language has its own sources in `Language_Provider`) two sources:
 * - `taxonomy.<name>`: the names of the terms assigned to the item, as a JSON list (`["Events", "Music"]`); in a
 *   hierarchical taxonomy the flat list of the assigned terms, without their parents; `[]` without terms.
 * - `taxonomy.<name>.first`: the name of the first of those terms, as a text, null without terms; it can be the input
 *   of a lookup table or of a condition, which a list cannot be.
 *
 * Terms are in alphabetical order of their names, the order WordPress gives them (also from the term cache, which the
 * query of a page fills for every item at once). Names are plain text: the HTML entities WordPress stores (`&amp;`)
 * are decoded. Taxonomy names cannot contain a dot, so `.first` is never part of a name.
 */
final class Taxonomy_Provider {

	/**
	 * Group of the taxonomy sources.
	 */
	public const GROUP = 'taxonomy';

	/**
	 * Suffix of the source of the first term.
	 */
	public const FIRST = '.first';

	/**
	 * Taxonomies never offered: the internal ones of Polylang.
	 */
	private const EXCLUDED = array( 'language', 'post_translations', 'term_language', 'term_translations' );

	/**
	 * Taxonomy sources of a post type: the terms and the first term of every public taxonomy, in the order WordPress
	 * registered them.
	 *
	 * @param string $post_type Post type.
	 * @return list<Source>
	 */
	public static function sources( string $post_type ): array {
		$sources = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( ! self::is_exported( $taxonomy ) ) {
				continue;
			}

			$id = self::GROUP . '.' . $taxonomy->name;
			/* translators: %s: plural name of a taxonomy, for example Categories. */
			$sources[] = new Source( $id, sprintf( __( '%s (all terms)', 'kk-content-exporter' ), $taxonomy->labels->name ), $post_type, 'list' );
			/* translators: %s: singular name of a taxonomy, for example Category. */
			$sources[] = new Source( $id . self::FIRST, sprintf( __( '%s (first term, alphabetical)', 'kk-content-exporter' ), $taxonomy->labels->singular_name ), $post_type, 'string' );
		}

		return $sources;
	}

	/**
	 * Declared type of a taxonomy source, from its identifier.
	 *
	 * @param string $id Source identifier.
	 * @return string|null `list`, `string` for the first term, null when the identifier is not a taxonomy source.
	 */
	public static function declared_type( string $id ): ?string {
		$parsed = self::parse( $id );
		if ( null === $parsed ) {
			return null;
		}

		return $parsed['first'] ? 'string' : 'list';
	}

	/**
	 * Value of a taxonomy source for a post.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $id   Source identifier.
	 * @return list<string>|string|null The names of the terms (`[]` without terms), the first name, or null when the
	 *                                  taxonomy is not public, does not belong to the post type or the item has no
	 *                                  term for the first-term source.
	 */
	public static function value( WP_Post $post, string $id ) {
		$parsed   = self::parse( $id );
		$taxonomy = null === $parsed ? null : get_taxonomy( $parsed['taxonomy'] );
		if ( null === $parsed || ! $taxonomy instanceof WP_Taxonomy || ! self::is_exported( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy->name ) ) {
			return null;
		}

		$names = array();
		$terms = get_the_terms( $post, $taxonomy->name );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$names[] = html_entity_decode( $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $parsed['first'] ? ( $names[0] ?? null ) : $names;
	}

	/**
	 * Taxonomy and kind of a source identifier.
	 *
	 * @param string $id Source identifier.
	 * @return array{taxonomy: string, first: bool}|null Null when the identifier is not of the taxonomy group.
	 */
	private static function parse( string $id ): ?array {
		$prefix = self::GROUP . '.';
		if ( ! str_starts_with( $id, $prefix ) ) {
			return null;
		}

		$name  = substr( $id, strlen( $prefix ) );
		$first = str_ends_with( $name, self::FIRST );
		if ( $first ) {
			$name = substr( $name, 0, -strlen( self::FIRST ) );
		}

		return '' === $name || str_contains( $name, '.' ) ? null : array(
			'taxonomy' => $name,
			'first'    => $first,
		);
	}

	/**
	 * Whether a taxonomy is offered: public and not an internal one of Polylang.
	 *
	 * @param WP_Taxonomy $taxonomy Taxonomy.
	 * @return bool
	 */
	private static function is_exported( WP_Taxonomy $taxonomy ): bool {
		return $taxonomy->public && ! in_array( $taxonomy->name, self::EXCLUDED, true );
	}
}

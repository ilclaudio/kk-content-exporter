<?php
/**
 * Builds the catalog of the sources from the providers.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The link between the providers, which read WordPress, and the pure `Catalog`.
 *
 * The only place that knows which providers exist: the admin routes and the saving of the mapping
 * build the catalog of a feed through it: the core sources, the language (of the site, and of the items with
 * Polylang), and for every post type its taxonomies and its post meta. ACF and CMB2 (version 1.1.0) are added here
 * when their providers arrive.
 */
final class Catalog_Factory {

	/**
	 * Catalog of the sources for the given post types.
	 *
	 * @param string[] $post_types Post types of the feed, already checked by the caller.
	 * @param bool     $discover   Whether to read the keys in use from the database (the list shown to the
	 *                             administrator). The check of a mapping does not need it.
	 * @param string[] $meta_keys  Post meta keys to list as well (typed by the administrator, or used by the
	 *                             saved mapping), for every post type.
	 * @return Catalog
	 *
	 * @phpstan-param list<string> $post_types
	 * @phpstan-param list<string> $meta_keys
	 */
	public static function for_post_types( array $post_types, bool $discover = false, array $meta_keys = array() ): Catalog {
		$sources = array_merge( Core_Provider::sources(), Language_Provider::sources( $post_types ) );
		foreach ( $post_types as $post_type ) {
			$sources = array_merge( $sources, Taxonomy_Provider::sources( $post_type ), Meta_Provider::sources( $post_type, $discover, $meta_keys ) );
		}

		return new Catalog( $sources, $post_types );
	}
}

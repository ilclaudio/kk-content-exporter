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
 * build the catalog of a feed through it. Today only the core sources; meta, taxonomies, image,
 * language, ACF and CMB2 are added here when their providers arrive.
 */
final class Catalog_Factory {

	/**
	 * Catalog of the sources for the given post types.
	 *
	 * @param string[] $post_types Post types of the feed, already checked by the caller.
	 * @return Catalog
	 *
	 * @phpstan-param list<string> $post_types
	 */
	public static function for_post_types( array $post_types ): Catalog {
		return new Catalog( Core_Provider::sources(), $post_types );
	}
}

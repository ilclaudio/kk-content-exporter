<?php
/**
 * Catalog of the sources a feed can use.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

defined( 'ABSPATH' ) || exit;

/**
 * The sources that the mapping of a feed can use, for the post types of the feed.
 *
 * The sources common to every post type (the core fields) appear once; the sources of a post type
 * (meta, taxonomies, ACF, CMB2) appear for that post type only, and only for the post types of the
 * feed. Fields of different post types are never merged by name: the administrator decides when they
 * correspond. Pure logic: the sources come from the providers, which read WordPress.
 */
final class Catalog {

	/**
	 * Sources of the feed: common ones first, then those of each post type, in the order of the feed.
	 *
	 * @var list<Source>
	 */
	private array $sources = array();

	/**
	 * Post types of the feed.
	 *
	 * @var list<string>
	 */
	private array $post_types;

	/**
	 * Builds the catalog of a feed.
	 *
	 * @param Source[] $sources    Sources given by the providers; duplicates and other post types are left out.
	 * @param string[] $post_types Post types of the feed.
	 *
	 * @phpstan-param list<Source> $sources
	 * @phpstan-param list<string> $post_types
	 */
	public function __construct( array $sources, array $post_types ) {
		$this->post_types = $post_types;

		$common  = array();
		$by_type = array_fill_keys( $post_types, array() );
		foreach ( $sources as $source ) {
			if ( $source->is_common() ) {
				$common[ $source->id ] ??= $source;
			} elseif ( isset( $by_type[ $source->post_type ] ) ) {
				$by_type[ $source->post_type ][ $source->id ] ??= $source;
			}
		}

		$this->sources = array_values( $common );
		foreach ( $by_type as $type_sources ) {
			foreach ( $type_sources as $source ) {
				$this->sources[] = $source;
			}
		}
	}

	/**
	 * Sources of the feed.
	 *
	 * @return list<Source>
	 */
	public function sources(): array {
		return $this->sources;
	}

	/**
	 * Source that the mapping can use for a post type of the feed.
	 *
	 * @param string $id        Source identifier.
	 * @param string $post_type A post type of the feed, or `*` for every post type (common sources only).
	 * @return Source|null Null when the source does not exist for that post type.
	 */
	public function find( string $id, string $post_type ): ?Source {
		foreach ( $this->sources as $source ) {
			if ( $source->id !== $id ) {
				continue;
			}

			if ( $source->is_common() || ( '*' !== $post_type && $source->post_type === $post_type ) ) {
				return $source;
			}
		}

		return null;
	}

	/**
	 * Rows that the mapping editor proposes for a new mapping.
	 *
	 * In a feed with two or more post types, the row `type` (slug of the post type of every item) tells
	 * the importer what each item is; it can be renamed or removed. A feed with one post type gets no
	 * proposal, although the source exists.
	 *
	 * @return list<array<string, mixed>> Rows in the canonical form of the mapping.
	 */
	public function suggested_rows(): array {
		if ( count( $this->post_types ) < 2 || null === $this->find( 'core.type', '*' ) ) {
			return array();
		}

		return array(
			array(
				'key'     => 'type',
				'kind'    => 'field',
				'type'    => 'string',
				'sources' => array( '*' => array( 'core.type' ) ),
			),
		);
	}
}

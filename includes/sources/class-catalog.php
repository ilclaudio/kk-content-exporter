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
	 * Post types of the feed, in its order.
	 *
	 * @return list<string>
	 */
	public function post_types(): array {
		return $this->post_types;
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
	 * Declared types of a source for a scope: the one place that answers whether a mapping can use a source.
	 *
	 * A source of the catalog counts for the post types it belongs to (a common one for all). A post meta key
	 * that is selectable (`Meta_Keys`) also counts for every post type of the feed that does not list it, as
	 * text: the list of the catalog is only a sample of the keys in use, so a key typed by hand is valid.
	 *
	 * @param string $id    Source identifier.
	 * @param string $scope A post type of the feed, or `*` for every post type of the feed.
	 * @return list<string>|null The types, without repetitions (empty: the source does not exist for the
	 *                           scope), or null when the post type is not read by the feed.
	 */
	public function types_for( string $id, string $scope ): ?array {
		if ( '*' !== $scope && ! in_array( $scope, $this->post_types, true ) ) {
			return null;
		}

		$types = array();
		foreach ( $this->sources as $source ) {
			if ( $source->id === $id && ( '*' === $scope || $source->is_common() || $source->post_type === $scope ) ) {
				$types[] = $source->type;
			}
		}

		if ( null !== Meta_Keys::key_from_source( $id ) ) {
			foreach ( '*' === $scope ? $this->post_types : array( $scope ) as $post_type ) {
				if ( null === $this->find( $id, $post_type ) ) {
					$types[] = Meta_Keys::UNREGISTERED_TYPE;
				}
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Options a source accepts, in any post type of the feed.
	 *
	 * @param string $id Source identifier.
	 * @return list<string> Option names, without repetitions; empty when the source has none or does not exist.
	 */
	public function options_for( string $id ): array {
		$options = array();
		foreach ( $this->sources as $source ) {
			if ( $source->id === $id ) {
				$options = array_merge( $options, $source->options );
			}
		}

		return array_values( array_unique( $options ) );
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

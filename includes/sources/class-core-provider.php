<?php
/**
 * Provider of the core fields of WordPress.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

use KKCE\Output\Content_Renderer;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the core fields of a post, common to every post type, and reads their values.
 *
 * Only public attributes of the author are offered: ID, display name and, as a separate source that
 * needs a confirmation, the URL of the author archive. The e-mail and the login are never offered.
 * Values are read as stored, without the filters of other plugins: the title is `post_title`, the
 * excerpt is the one written by hand. Content and excerpt go through the controlled rendering of
 * `Content_Renderer` (blocks, optional shortcodes, absolute URLs; `raw` gives the stored text). Dates are the GMT ones, in the database format
 * (`Y-m-d H:i:s`); the normalizer writes them in ISO 8601.
 */
final class Core_Provider {

	/**
	 * Options of the content and of the excerpt: run the shortcodes, or give the raw text.
	 */
	private const TEXT_OPTIONS = array( 'shortcodes', 'raw' );

	/**
	 * The core sources, in the order shown to the administrator.
	 *
	 * @return list<Source>
	 */
	public static function sources(): array {
		return array(
			new Source( 'core.id', __( 'ID', 'kk-content-exporter' ), '', 'number' ),
			new Source( 'core.slug', __( 'Slug', 'kk-content-exporter' ), '', 'string' ),
			new Source( 'core.title', __( 'Title', 'kk-content-exporter' ), '', 'string' ),
			new Source( 'core.content', __( 'Content', 'kk-content-exporter' ), '', 'html', self::TEXT_OPTIONS ),
			new Source( 'core.excerpt', __( 'Excerpt', 'kk-content-exporter' ), '', 'html', self::TEXT_OPTIONS ),
			new Source( 'core.permalink', __( 'Permalink', 'kk-content-exporter' ), '', 'url' ),
			new Source( 'core.shortlink', __( 'Short link (does not change with the slug)', 'kk-content-exporter' ), '', 'url' ),
			new Source( 'core.date', __( 'Publish date (UTC)', 'kk-content-exporter' ), '', 'datetime' ),
			new Source( 'core.modified', __( 'Last modified date (UTC)', 'kk-content-exporter' ), '', 'datetime' ),
			new Source( 'core.author.id', __( 'Author ID', 'kk-content-exporter' ), '', 'number' ),
			new Source( 'core.author.name', __( 'Author display name', 'kk-content-exporter' ), '', 'string' ),
			new Source( 'core.author.url', __( 'Author archive URL', 'kk-content-exporter' ), '', 'url' ),
			new Source( 'core.type', __( 'Post type (slug)', 'kk-content-exporter' ), '', 'string' ),
			new Source( 'core.type_label', __( 'Post type (label)', 'kk-content-exporter' ), '', 'string' ),
		);
	}

	/**
	 * Value of a core source for a post.
	 *
	 * @param WP_Post      $post    Post.
	 * @param string       $id      Source identifier.
	 * @param array<mixed> $options Options of the mapping row (`shortcodes`, `raw` for content and excerpt).
	 * @return mixed The value, or null when the post has none (no author, no permalink) or the identifier
	 *               is not a core source. An empty excerpt is an empty string: a value, not a missing one.
	 */
	public static function value( WP_Post $post, string $id, array $options = array() ) {
		return match ( $id ) {
			'core.id'          => $post->ID,
			'core.slug'        => $post->post_name,
			'core.title'       => $post->post_title,
			'core.content'     => Content_Renderer::render( $post->post_content, $options, false, self::base_url( $post ) ),
			'core.excerpt'     => Content_Renderer::render( $post->post_excerpt, $options, true, self::base_url( $post ) ),
			'core.permalink'   => self::text_or_null( get_permalink( $post ) ),
			'core.shortlink'   => self::text_or_null( wp_get_shortlink( $post->ID ) ),
			'core.date'        => self::gmt_date( $post->post_date_gmt ),
			'core.modified'    => self::gmt_date( $post->post_modified_gmt ),
			'core.author.id'   => self::author_id( $post ),
			'core.author.name' => self::author_name( $post ),
			'core.author.url'  => self::author_url( $post ),
			'core.type'        => $post->post_type,
			'core.type_label'  => self::type_label( $post ),
			default            => null,
		};
	}

	/**
	 * Base of the relative URLs of the content: its permalink, or the home page.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function base_url( WP_Post $post ): string {
		return self::text_or_null( get_permalink( $post ) ) ?? home_url( '/' );
	}

	/**
	 * A non-empty string, or null.
	 *
	 * @param mixed $value Value given by WordPress (a string, or false).
	 * @return string|null
	 */
	private static function text_or_null( $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * A GMT date of the database, or null for the empty date WordPress stores for unpublished content.
	 *
	 * @param string $date Date in the database format.
	 * @return string|null
	 */
	private static function gmt_date( string $date ): ?string {
		return '' === $date || '0000-00-00 00:00:00' === $date ? null : $date;
	}

	/**
	 * ID of the author, or null when the post has none or the user no longer exists.
	 *
	 * @param WP_Post $post Post.
	 * @return int|null
	 */
	private static function author_id( WP_Post $post ): ?int {
		$author = (int) $post->post_author;

		return $author > 0 && false !== get_userdata( $author ) ? $author : null;
	}

	/**
	 * Display name of the author.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	private static function author_name( WP_Post $post ): ?string {
		$author = self::author_id( $post );

		return null === $author ? null : self::text_or_null( get_the_author_meta( 'display_name', $author ) );
	}

	/**
	 * URL of the archive of the author. It contains the `user_nicename`, often equal to the login, so the
	 * mapping must confirm this source.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	private static function author_url( WP_Post $post ): ?string {
		$author = self::author_id( $post );

		return null === $author ? null : self::text_or_null( get_author_posts_url( $author ) );
	}

	/**
	 * Singular label of the post type of the post. It changes with the language of the site and when the
	 * post type is renamed: the slug (`core.type`) is the stable one.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null
	 */
	private static function type_label( WP_Post $post ): ?string {
		$type = get_post_type_object( $post->post_type );

		return null === $type ? null : self::text_or_null( $type->labels->singular_name );
	}
}

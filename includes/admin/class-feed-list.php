<?php
/**
 * Columns of the list of the feeds in the administration.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Plugin;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the configuration of every feed in the `kkce_feed` list: endpoint address, source post types,
 * access and status.
 *
 * The filters used here fire only on the list screen of the post type, so the hooks cost nothing
 * elsewhere. Values are read through the sanitizers and readers of `Feed_Meta`, so a corrupted stored
 * value shows the safe default, and everything printed is escaped.
 */
final class Feed_List {

	/**
	 * Column key: relative address of the endpoint of the feed.
	 */
	public const COLUMN_ENDPOINT = 'kkce_endpoint';

	/**
	 * Column key: post types the feed reads from.
	 */
	public const COLUMN_SOURCE = 'kkce_source';

	/**
	 * Column key: who can read the feed.
	 */
	public const COLUMN_ACCESS = 'kkce_access';

	/**
	 * Column key: whether the endpoint of the feed answers.
	 */
	public const COLUMN_STATUS = 'kkce_status';

	/**
	 * Builds the columns of the list: checkbox, title and the four feed columns.
	 *
	 * @param mixed $columns Columns given by WordPress.
	 * @return mixed The columns, or the value unchanged when it is not an array.
	 */
	public static function columns( $columns ) {
		if ( ! is_array( $columns ) ) {
			return $columns;
		}

		$result = array();
		foreach ( array( 'cb', 'title' ) as $key ) {
			if ( isset( $columns[ $key ] ) ) {
				$result[ $key ] = $columns[ $key ];
			}
		}

		$result[ self::COLUMN_ENDPOINT ] = __( 'Endpoint', 'kk-content-exporter' );
		$result[ self::COLUMN_SOURCE ]   = __( 'Source', 'kk-content-exporter' );
		$result[ self::COLUMN_ACCESS ]   = __( 'Access', 'kk-content-exporter' );
		$result[ self::COLUMN_STATUS ]   = __( 'Status', 'kk-content-exporter' );

		return $result;
	}

	/**
	 * Removes "Quick Edit" from the row actions of the feeds.
	 *
	 * Quick edit can change the slug of a feed outside the editor, skipping the validation of the
	 * slug (format, reserved words, uniqueness).
	 *
	 * @param mixed $actions Row actions of the post.
	 * @param mixed $post    The post of the row.
	 * @return mixed The actions, without quick edit when the post is a feed.
	 */
	public static function row_actions( $actions, $post ) {
		if ( ! is_array( $actions ) || ! $post instanceof WP_Post || Feed_Post_Type::POST_TYPE !== $post->post_type ) {
			return $actions;
		}

		unset( $actions['inline hide-if-no-js'] );

		return $actions;
	}

	/**
	 * Prints the cell of a feed column. Columns of other plugins and of core are ignored.
	 *
	 * @param mixed $column  Column key.
	 * @param mixed $post_id ID of the feed.
	 * @return void
	 */
	public static function render_column( $column, $post_id ): void {
		if ( ! is_string( $column ) || ! is_numeric( $post_id ) ) {
			return;
		}

		$post_id = (int) $post_id;

		switch ( $column ) {
			case self::COLUMN_ENDPOINT:
				self::print_endpoint( $post_id );
				break;
			case self::COLUMN_SOURCE:
				self::print_text( self::source_label( Feed_Meta::post_types( $post_id ) ) );
				break;
			case self::COLUMN_ACCESS:
				self::print_text( self::access_label( get_post_meta( $post_id, Feed_Meta::META_ACCESS, true ) ) );
				break;
			case self::COLUMN_STATUS:
				self::print_text( self::status_label( get_post_meta( $post_id, Feed_Meta::META_ACTIVE, true ) ) );
				break;
		}
	}

	/**
	 * Labels of the source post types, joined by the list separator of the site language (a comma in
	 * English): the name of each post type when it exists, otherwise its key.
	 *
	 * @param string[] $keys Sanitized post type keys.
	 * @return string Empty when no source is set.
	 *
	 * @phpstan-param list<string> $keys
	 */
	private static function source_label( array $keys ): string {
		$labels = array();

		foreach ( $keys as $key ) {
			$type     = get_post_type_object( $key );
			$labels[] = null === $type ? $key : $type->label;
		}

		return implode( wp_get_list_item_separator(), $labels );
	}

	/**
	 * Prints the relative address of the endpoint of a feed, with the absolute one in the title attribute.
	 *
	 * The address comes from `Plugin::feed_url()`, so it follows the permalink settings and the prefix of
	 * the REST API of the site (`/wp-json/kkce/v1/news` or `/?rest_route=/kkce/v1/news`). A feed without a
	 * slug, or with one that cannot serve a feed (WordPress can assign one outside the editor), shows the
	 * empty marker instead of an address that would not work.
	 *
	 * @param int $post_id ID of the feed.
	 * @return void
	 */
	private static function print_endpoint( int $post_id ): void {
		$slug = get_post_field( 'post_name', $post_id );
		$url  = is_string( $slug ) ? Plugin::feed_url( $slug ) : '';

		if ( '' === $url ) {
			self::print_text( '' );
			return;
		}

		$parts = wp_parse_url( $url );
		$path  = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
		$query = is_array( $parts ) && isset( $parts['query'] ) ? '?' . $parts['query'] : '';

		printf(
			'<span title="%1$s"><code>%2$s</code></span>',
			esc_attr( $url ),
			esc_html( $path . $query )
		);
	}

	/**
	 * Label of the access.
	 *
	 * @param mixed $value Stored access.
	 * @return string
	 */
	private static function access_label( $value ): string {
		return Feed_Meta::ACCESS_AUTHENTICATED === Feed_Meta::sanitize_access( $value )
			? __( 'Authenticated', 'kk-content-exporter' )
			: __( 'Public', 'kk-content-exporter' );
	}

	/**
	 * Label of the status.
	 *
	 * @param mixed $value Stored active flag.
	 * @return string
	 */
	private static function status_label( $value ): string {
		return Feed_Meta::sanitize_active( $value )
			? __( 'Active', 'kk-content-exporter' )
			: __( 'Disabled', 'kk-content-exporter' );
	}

	/**
	 * Prints an escaped text, or the marker of an empty value (with a text for screen readers).
	 *
	 * @param string $text Text to print.
	 * @return void
	 */
	private static function print_text( string $text ): void {
		if ( '' === $text ) {
			echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' .
				esc_html__( 'Not set', 'kk-content-exporter' ) . '</span>';
			return;
		}

		echo esc_html( $text );
	}
}

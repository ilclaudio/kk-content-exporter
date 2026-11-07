<?php
/**
 * Columns of the list of the feeds in the administration.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * Shows the configuration of every feed in the `kkce_feed` list: slug, source post type, access
 * and status.
 *
 * The filters used here fire only on the list screen of the post type, so the hooks cost nothing
 * elsewhere. Values are read through the sanitizers of `Feed_Post_Type`, so a corrupted stored value
 * shows the safe default, and everything printed is escaped.
 */
final class Feed_List {

	/**
	 * Column key: slug of the feed (last segment of its endpoint URL).
	 */
	public const COLUMN_SLUG = 'kkce_slug';

	/**
	 * Column key: post type the feed reads from.
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

		$result[ self::COLUMN_SLUG ]   = __( 'Slug', 'kk-content-exporter' );
		$result[ self::COLUMN_SOURCE ] = __( 'Source', 'kk-content-exporter' );
		$result[ self::COLUMN_ACCESS ] = __( 'Access', 'kk-content-exporter' );
		$result[ self::COLUMN_STATUS ] = __( 'Status', 'kk-content-exporter' );

		return $result;
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
			case self::COLUMN_SLUG:
				$slug = get_post_field( 'post_name', $post_id );
				self::print_text( is_string( $slug ) ? $slug : '' );
				break;
			case self::COLUMN_SOURCE:
				self::print_text( self::source_label( get_post_meta( $post_id, Feed_Post_Type::META_POST_TYPE, true ) ) );
				break;
			case self::COLUMN_ACCESS:
				self::print_text( self::access_label( get_post_meta( $post_id, Feed_Post_Type::META_ACCESS, true ) ) );
				break;
			case self::COLUMN_STATUS:
				self::print_text( self::status_label( get_post_meta( $post_id, Feed_Post_Type::META_ACTIVE, true ) ) );
				break;
		}
	}

	/**
	 * Label of the source post type: its name when it exists, otherwise its key.
	 *
	 * @param mixed $value Stored post type key.
	 * @return string Empty when no source is set.
	 */
	private static function source_label( $value ): string {
		$key = Feed_Post_Type::sanitize_post_type( $value );

		if ( '' === $key ) {
			return '';
		}

		$type = get_post_type_object( $key );

		return null === $type ? $key : $type->label;
	}

	/**
	 * Label of the access.
	 *
	 * @param mixed $value Stored access.
	 * @return string
	 */
	private static function access_label( $value ): string {
		return Feed_Post_Type::ACCESS_AUTHENTICATED === Feed_Post_Type::sanitize_access( $value )
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
		return Feed_Post_Type::sanitize_active( $value )
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

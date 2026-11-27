<?php
/**
 * Private post type that stores the feed configurations.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Registers `kkce_feed`, the administrative post type of the feeds.
 *
 * The post type is private (no front end, no search, no REST routes) and only administrators can
 * manage it. The configuration of a feed lives in its post meta, registered by `Feed_Meta`.
 */
final class Feed_Post_Type {

	/**
	 * Name of the post type.
	 */
	public const POST_TYPE = 'kkce_feed';

	/**
	 * Capability required to manage feeds.
	 */
	private const MANAGE_CAPABILITY = 'manage_options';

	/**
	 * Registers the post type. Hooked on `init` by `Plugin::boot()`.
	 *
	 * @return void
	 */
	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => self::labels(),
				'description'         => __( 'Configurations of the JSON feeds.', 'kk-content-exporter' ),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'can_export'          => false,
				'delete_with_user'    => false,
				'menu_icon'           => 'dashicons-rss',
				'menu_position'       => 80,
				'supports'            => array( 'title' ),
				'capability_type'     => array( 'kkce_feed', 'kkce_feeds' ),
				'capabilities'        => self::capabilities(),
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Keeps the slug of a feed, its public address, when the feed is written outside its editor (a crafted
	 * Quick Edit request, code, another plugin). Hooked on `wp_insert_post_data` before the editor
	 * (`Feed_Editor::filter_post_data()`), which sets the slug again for an authorized save.
	 *
	 * Moving to the trash and restoring keep working (rule in `Feed_Form::kept_slug()`); a feed restored when
	 * another feed uses its slug gets no slug, and the slug it could not get back is kept for the editor
	 * (`Feed_Meta::META_TAKEN_SLUG`). Other post types and new posts are returned unchanged.
	 *
	 * @param mixed $data    Post data about to be saved.
	 * @param mixed $postarr Data given to `wp_insert_post()`.
	 * @return mixed The data, with the slug to keep.
	 */
	public static function protect_slug( $data, $postarr ) {
		if ( ! is_array( $data ) || ! is_array( $postarr ) || self::POST_TYPE !== ( $data['post_type'] ?? null ) ) {
			return $data;
		}

		$post_id  = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$previous = $post_id > 0 ? get_post_status( $post_id ) : false;
		if ( false === $previous ) {
			return $data;
		}

		$post     = get_post( $post_id );
		$stored   = $post instanceof \WP_Post ? $post->post_name : '';
		$proposed = (string) ( $data['post_name'] ?? '' );
		$status   = (string) ( $data['post_status'] ?? $previous );
		$taken    = static fn( string $slug ): bool => self::slug_is_taken( $slug, $post_id );
		$kept     = Feed_Form::kept_slug( $stored, $proposed, $previous, $status, $taken );

		// The slug it would get back if no other feed used it.
		$wanted = Feed_Form::kept_slug( $stored, $proposed, $previous, $status, '__return_false' );
		if ( '' === $kept && '' !== $wanted ) {
			update_post_meta( $post_id, Feed_Meta::META_TAKEN_SLUG, $wanted );
		}

		$data['post_name'] = $kept;

		return $data;
	}

	/**
	 * Whether another feed (in any status but the trash) uses a slug.
	 *
	 * @param string $slug    Slug.
	 * @param int    $post_id Feed to leave out.
	 * @return bool
	 */
	public static function slug_is_taken( string $slug, int $post_id ): bool {
		$found = get_posts(
			array(
				'post_type'     => self::POST_TYPE,
				'name'          => $slug,
				'post_status'   => 'any',
				'post__not_in'  => array( $post_id ),
				'numberposts'   => 1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		return array() !== $found;
	}

	/**
	 * Disables a feed restored from the trash. Hooked on `untrashed_post` by `Plugin::boot()`.
	 *
	 * A restored feed starts disabled, also when it got its slug back (`protect_slug()`): the editor enables it
	 * again only with a slug that is still valid and free.
	 *
	 * @param mixed $post_id ID of the restored post.
	 * @return void
	 */
	public static function disable_restored_feed( $post_id ): void {
		$post_id = (int) $post_id;

		if ( $post_id < 1 || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, Feed_Meta::META_ACTIVE, false );
	}

	/**
	 * Whether a feed is enabled but not published (draft, pending, scheduled, private), so its address
	 * answers 404 although the administrator enabled it. The editor and the list of the feeds say so.
	 *
	 * @param int $post_id ID of the feed.
	 * @return bool
	 */
	public static function is_enabled_but_unpublished( int $post_id ): bool {
		return 'publish' !== get_post_status( $post_id )
			&& Feed_Meta::sanitize_active( get_post_meta( $post_id, Feed_Meta::META_ACTIVE, true ) );
	}

	/**
	 * Primitive capabilities of the post type: all of them require the administration capability.
	 *
	 * @return array<string, string> Post type capability => required capability.
	 */
	public static function capabilities(): array {
		$names = array(
			'edit_posts',
			'edit_others_posts',
			'edit_private_posts',
			'edit_published_posts',
			'publish_posts',
			'read_private_posts',
			'delete_posts',
			'delete_private_posts',
			'delete_published_posts',
			'delete_others_posts',
			'create_posts',
		);

		return array_fill_keys( $names, self::MANAGE_CAPABILITY );
	}

	/**
	 * Labels of the post type.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'name'               => __( 'Feeds', 'kk-content-exporter' ),
			'singular_name'      => __( 'Feed', 'kk-content-exporter' ),
			'menu_name'          => __( 'Content Exporter', 'kk-content-exporter' ),
			'add_new'            => __( 'Add New', 'kk-content-exporter' ),
			'add_new_item'       => __( 'Add New Feed', 'kk-content-exporter' ),
			'edit_item'          => __( 'Edit Feed', 'kk-content-exporter' ),
			'new_item'           => __( 'New Feed', 'kk-content-exporter' ),
			'view_item'          => __( 'View Feed', 'kk-content-exporter' ),
			'search_items'       => __( 'Search Feeds', 'kk-content-exporter' ),
			'not_found'          => __( 'No feeds found.', 'kk-content-exporter' ),
			'not_found_in_trash' => __( 'No feeds found in Trash.', 'kk-content-exporter' ),
			'all_items'          => __( 'All Feeds', 'kk-content-exporter' ),
		);
	}
}

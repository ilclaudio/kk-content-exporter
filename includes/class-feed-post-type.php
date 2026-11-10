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
	 * Disables a feed restored from the trash. Hooked on `untrashed_post` by `Plugin::boot()`.
	 *
	 * While a feed is in the trash another feed can take its slug, and WordPress gives the old slug back
	 * on restore without checking drafts for duplicates. A restored feed therefore starts disabled; the
	 * editor enables it again only with a slug that is still valid and free.
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

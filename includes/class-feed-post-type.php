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
 * manage it. The configuration of a feed lives in its post meta, registered by `register_meta()`.
 */
final class Feed_Post_Type {

	/**
	 * Name of the post type.
	 */
	public const POST_TYPE = 'kkce_feed';

	/**
	 * Meta key: post type the feed reads its contents from.
	 */
	public const META_POST_TYPE = '_kkce_post_type';

	/**
	 * Meta key: who can read the feed (`public` or `authenticated`).
	 */
	public const META_ACCESS = '_kkce_access';

	/**
	 * Meta key: whether the endpoint of the feed answers.
	 */
	public const META_ACTIVE = '_kkce_active';

	/**
	 * Meta key: items per page.
	 */
	public const META_PER_PAGE = '_kkce_per_page';

	/**
	 * Access value: anyone can read the feed.
	 */
	public const ACCESS_PUBLIC = 'public';

	/**
	 * Access value: only an authenticated user with the export capability can read the feed.
	 */
	public const ACCESS_AUTHENTICATED = 'authenticated';

	/**
	 * Default number of items per page.
	 */
	public const DEFAULT_PER_PAGE = 50;

	/**
	 * Maximum length of a post type key in WordPress.
	 */
	private const POST_TYPE_MAX_LENGTH = 20;

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
	 * Registers the configuration meta of a feed. Hooked on `init` by `Plugin::boot()`.
	 *
	 * The meta are never exposed in REST, are sanitized on every write and can be edited only by
	 * users who can edit the feed.
	 *
	 * @return void
	 */
	public static function register_meta(): void {
		$common = array(
			'single'        => true,
			'show_in_rest'  => false,
			'auth_callback' => array( self::class, 'can_edit_meta' ),
		);

		register_post_meta(
			self::POST_TYPE,
			self::META_POST_TYPE,
			$common + array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => array( self::class, 'sanitize_post_type' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_ACCESS,
			$common + array(
				'type'              => 'string',
				'default'           => self::ACCESS_PUBLIC,
				'sanitize_callback' => array( self::class, 'sanitize_access' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_ACTIVE,
			$common + array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( self::class, 'sanitize_active' ),
			)
		);
		register_post_meta(
			self::POST_TYPE,
			self::META_PER_PAGE,
			$common + array(
				'type'              => 'integer',
				'default'           => self::DEFAULT_PER_PAGE,
				'sanitize_callback' => array( self::class, 'sanitize_per_page' ),
			)
		);
	}

	/**
	 * Whether a user can edit the configuration meta of a feed.
	 *
	 * Used as `auth_callback` of the protected meta: it follows the capabilities of the post type.
	 *
	 * @param mixed $allowed  Result of the default check (ignored).
	 * @param mixed $meta_key Meta key (ignored).
	 * @param mixed $post_id  ID of the feed.
	 * @param mixed $user_id  ID of the user.
	 * @return bool
	 */
	public static function can_edit_meta( $allowed, $meta_key, $post_id, $user_id ): bool {
		unset( $allowed, $meta_key );

		return user_can( (int) $user_id, 'edit_post', (int) $post_id );
	}

	/**
	 * Normalizes the key of the source post type: lowercase letters, digits, dash and underscore.
	 *
	 * Only the format is normalized here; checking that the post type exists and is public belongs
	 * to the validation of the feed.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_post_type( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		$key = preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) );

		return substr( (string) $key, 0, self::POST_TYPE_MAX_LENGTH );
	}

	/**
	 * Restricts the access to the two known values; anything else falls back to `public`.
	 *
	 * @param mixed $value Raw value.
	 * @return string `public` or `authenticated`.
	 */
	public static function sanitize_access( $value ): string {
		return self::ACCESS_AUTHENTICATED === $value ? self::ACCESS_AUTHENTICATED : self::ACCESS_PUBLIC;
	}

	/**
	 * Converts the active flag to a strict boolean: only `true`, `1` and `'1'` enable the feed.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public static function sanitize_active( $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}

	/**
	 * Converts the items per page to an integer of at least 1; anything else gives the default.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function sanitize_per_page( $value ): int {
		if ( is_string( $value ) && ctype_digit( $value ) ) {
			$value = (int) $value;
		}

		if ( ! is_int( $value ) || $value < 1 ) {
			return self::DEFAULT_PER_PAGE;
		}

		return $value;
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

<?php
/**
 * Main plugin class.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into WordPress.
 *
 * `boot()` runs on `plugins_loaded` and only registers hooks: no translatable string is
 * evaluated before `init` (WordPress 6.7+ reports translations loaded too early).
 */
final class Plugin {

	/**
	 * Text domain of the plugin, equal to its slug: WordPress loads the translations of translate.wordpress.org
	 * (language packs) by itself, so the plugin ships and loads no translation files.
	 */
	public const TEXT_DOMAIN = 'kk-content-exporter';

	/**
	 * Capability that allows reading the authenticated feeds. Granted to administrators on activation.
	 */
	public const READ_EXPORT_CAPABILITY = 'kkce_read_export';

	/**
	 * REST namespace of the feeds: a feed answers at `<rest url>/kkce/v1/<slug>`.
	 */
	public const REST_NAMESPACE = 'kkce/v1';

	/**
	 * Address of the endpoint of a feed, computed by WordPress.
	 *
	 * The only place that builds the address of a feed: the editor, the list of the feeds and the
	 * endpoints use it. `rest_url()` follows the permalink settings (`/wp-json/...` or
	 * `/index.php?rest_route=...`), the REST prefix and the filters of other plugins.
	 *
	 * @param string $slug Slug of the feed.
	 * @return string The address, or an empty string when the slug cannot serve a feed (empty, malformed,
	 *                too long or reserved). Whether another feed uses it is not checked here.
	 */
	public static function feed_url( string $slug ): string {
		if ( Feed_Validator::VALID !== Feed_Validator::validate_slug( $slug, array() ) ) {
			return '';
		}

		return rest_url( self::REST_NAMESPACE . '/' . $slug );
	}

	/**
	 * Whether the hooks have already been registered.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Registers the hooks of the plugin. Safe to call more than once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', array( Feed_Post_Type::class, 'register' ) );
		add_action( 'init', array( Feed_Meta::class, 'register' ) );
		add_action( 'untrashed_post', array( Feed_Post_Type::class, 'disable_restored_feed' ) );
		add_action( 'admin_menu', array( Admin\Settings_Page::class, 'add_menu' ) );
		add_action( 'admin_init', array( Admin\Settings_Page::class, 'register_settings' ) );
		// After the settings page, so the guide follows it in the menu.
		add_action( 'admin_menu', array( Admin\Guide_Page::class, 'add_menu' ) );
		add_filter( 'manage_' . Feed_Post_Type::POST_TYPE . '_posts_columns', array( Admin\Feed_List::class, 'columns' ) );
		add_action( 'manage_' . Feed_Post_Type::POST_TYPE . '_posts_custom_column', array( Admin\Feed_List::class, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( Admin\Feed_List::class, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . Feed_Post_Type::POST_TYPE, array( Admin\Feed_Editor::class, 'add_meta_box' ) );
		add_action( 'add_meta_boxes_' . Feed_Post_Type::POST_TYPE, array( Admin\Mapping_Box::class, 'add_meta_box' ) );
		// Before the editor: the slug of a feed stays unless the editor sets it (priority 10).
		add_filter( 'wp_insert_post_data', array( Feed_Post_Type::class, 'protect_slug' ), 9, 2 );
		add_filter( 'wp_insert_post_data', array( Admin\Feed_Editor::class, 'filter_post_data' ), 10, 2 );
		add_action( 'save_post_' . Feed_Post_Type::POST_TYPE, array( Admin\Feed_Editor::class, 'save' ) );
		add_action( 'admin_notices', array( Admin\Feed_Editor::class, 'render_notices' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Feed_Editor::class, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( Admin\Mapping_Box::class, 'enqueue_assets' ) );
		add_action( 'rest_api_init', array( Rest\Admin_Routes::class, 'register' ) );
		add_action( 'rest_api_init', array( Rest\Feed_Routes::class, 'register' ) );
		add_filter( 'rest_post_dispatch', array( Rest\Feed_Routes::class, 'authentication_challenge' ), 10, 3 );
	}

	/**
	 * Activation callback.
	 *
	 * Grants the export capability to the administrator role. It is idempotent (granting twice
	 * changes nothing), creates no content and needs no rewrite flush, because the feed post type
	 * has no rewrite rules.
	 *
	 * @return void
	 */
	public static function activate(): void {
		$role = get_role( 'administrator' );

		if ( null === $role ) {
			return;
		}

		$role->add_cap( self::READ_EXPORT_CAPABILITY );
	}

	/**
	 * Deactivation callback.
	 *
	 * Deactivation never deletes the feed configuration or the capability; data removal belongs to
	 * `uninstall.php`.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
	}
}

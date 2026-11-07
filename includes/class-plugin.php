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
	 * Text domain of the plugin.
	 */
	public const TEXT_DOMAIN = 'kk-content-exporter';

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

		add_action( 'init', array( self::class, 'load_textdomain' ) );
		add_action( 'init', array( Feed_Post_Type::class, 'register' ) );
		add_action( 'init', array( Feed_Post_Type::class, 'register_meta' ) );
		add_filter( 'plugin_row_meta', array( self::class, 'filter_plugin_row_meta' ), 10, 2 );
	}

	/**
	 * Loads the translations shipped in `languages/`.
	 *
	 * Language packs from translate.wordpress.org, when installed, take precedence over the
	 * bundled files. The call is kept on purpose: without it the bundled translations are not
	 * loaded (see "Internationalization" in AGENTS/CODING_STANDARDS.md).
	 *
	 * @return void
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( plugin_basename( KKCE_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Adds a status note to the row of the plugin in the Plugins screen.
	 *
	 * @param mixed $meta        Row meta links and texts.
	 * @param mixed $plugin_file Plugin file, relative to the plugins directory.
	 * @return mixed The row meta, with the note for this plugin.
	 */
	public static function filter_plugin_row_meta( $meta, $plugin_file ) {
		if ( ! is_array( $meta ) || plugin_basename( KKCE_PLUGIN_FILE ) !== $plugin_file ) {
			return $meta;
		}

		$meta[] = esc_html( self::development_notice() );

		return $meta;
	}

	/**
	 * Note shown while the plugin has no features yet.
	 *
	 * @return string
	 */
	public static function development_notice(): string {
		return __( 'Development version: no feeds can be configured yet.', 'kk-content-exporter' );
	}

	/**
	 * Activation callback.
	 *
	 * Nothing to set up yet: capabilities and the feed post type arrive in Phase 1.
	 *
	 * @return void
	 */
	public static function activate(): void {
	}

	/**
	 * Deactivation callback.
	 *
	 * Deactivation never deletes the feed configuration; data removal belongs to `uninstall.php`.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
	}
}

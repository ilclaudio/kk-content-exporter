<?php
/**
 * The settings page of the plugin.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Post_Type;
use KKCE\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * "Settings" submenu of the Content Exporter menu, built with the Settings API.
 *
 * The values are stored in the option `kkce_settings` and sanitized by `Settings::sanitize_option()`.
 * The page also lists what could keep the feeds from working (diagnostics); it never changes a setting
 * by itself and shows its warnings on this page only, not on every screen of the administration.
 */
final class Settings_Page {

	/**
	 * Slug of the page.
	 */
	public const MENU_SLUG = 'kkce-settings';

	/**
	 * Option group of the Settings API.
	 */
	public const GROUP = 'kkce_settings';

	/**
	 * Identifier of the only section of the page.
	 */
	private const SECTION = 'kkce_settings_general';

	/**
	 * Capability required to see and change the settings.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Most feeds that the diagnostics look at. A site has a handful of feeds; the bound keeps the page
	 * cheap whatever the number of feeds.
	 */
	private const DIAGNOSTIC_FEEDS = 100;

	/**
	 * Adds the page under the Content Exporter menu. Hooked on `admin_menu`.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Feed_Post_Type::POST_TYPE,
			__( 'Content Exporter settings', 'kk-content-exporter' ),
			__( 'Settings', 'kk-content-exporter' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Registers the option, the section and the fields. Hooked on `admin_init`.
	 *
	 * @return void
	 */
	public static function register_settings(): void {
		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'object',
				'default'           => Settings::defaults(),
				'sanitize_callback' => array( Settings::class, 'sanitize_option' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( self::SECTION, '', '__return_empty_string', self::MENU_SLUG );

		$fields = array(
			'enabled'      => array(
				'title'       => __( 'Endpoints', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Serve the feeds', 'kk-content-exporter' ),
				'description' => __( 'Turn it off to stop every feed from answering. The feeds and their settings are kept.', 'kk-content-exporter' ),
			),
			'require_auth' => array(
				'title'       => __( 'Authentication', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Require authentication for all feeds', 'kk-content-exporter' ),
				'description' => __( 'It takes precedence over the access chosen in each feed.', 'kk-content-exporter' ),
			),
			'max_per_page' => array(
				'title'       => __( 'Maximum items per page', 'kk-content-exporter' ),
				'type'        => 'number',
				'label'       => '',
				'description' => __( 'The largest number of items that a feed can serve in one page. There is no upper limit: very high values make every response heavy and are your responsibility.', 'kk-content-exporter' ),
			),
			'remove_data'  => array(
				'title'       => __( 'Uninstall', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Delete all the data of the plugin when the plugin is deleted', 'kk-content-exporter' ),
				'description' => __( 'The feeds, their settings, these settings and the capability are removed permanently. Leave it off to keep them.', 'kk-content-exporter' ),
			),
		);

		foreach ( $fields as $key => $field ) {
			add_settings_field(
				'kkce_setting_' . $key,
				$field['title'],
				array( self::class, 'render_field' ),
				self::MENU_SLUG,
				self::SECTION,
				array(
					'key'         => $key,
					'type'        => $field['type'],
					'label'       => $field['label'],
					'description' => $field['description'],
					'label_for'   => 'number' === $field['type'] ? self::field_id( $key ) : '',
				)
			);
		}
	}

	/**
	 * Prints the page.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'kk-content-exporter' ), 403 );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Exporter settings', 'kk-content-exporter' ); ?></h1>
			<?php
			settings_errors( Settings::OPTION );

			foreach ( self::diagnostics() as $message ) {
				printf( '<div class="notice notice-warning inline"><p>%s</p></div>', esc_html( $message ) );
			}
			?>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints one field of the page.
	 *
	 * @param mixed $args Arguments given to `add_settings_field()`.
	 * @return void
	 */
	public static function render_field( $args ): void {
		if ( ! is_array( $args ) ) {
			return;
		}

		$key      = isset( $args['key'] ) && is_string( $args['key'] ) ? $args['key'] : '';
		$settings = Settings::get();

		if ( ! array_key_exists( $key, $settings ) ) {
			return;
		}

		$id          = self::field_id( $key );
		$name        = Settings::OPTION . '[' . $key . ']';
		$label       = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : '';
		$description = isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '';

		if ( 'number' === ( $args['type'] ?? '' ) ) {
			?>
			<input type="number" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" min="1" step="1" class="small-text" aria-describedby="<?php echo esc_attr( $id ); ?>-help">
			<?php
		} else {
			?>
			<label for="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1"<?php checked( true === $settings[ $key ] ); ?> aria-describedby="<?php echo esc_attr( $id ); ?>-help">
				<?php echo esc_html( $label ); ?>
			</label>
			<?php
		}
		?>
		<p class="description" id="<?php echo esc_attr( $id ); ?>-help"><?php echo esc_html( $description ); ?></p>
		<?php
	}

	/**
	 * What could keep the feeds from working, as messages for the administrator.
	 *
	 * Nothing is changed and no request is made: the REST API is checked by running its own filter as an
	 * anonymous user, the Application Passwords by asking WordPress whether they are available.
	 *
	 * @return list<string>
	 */
	public static function diagnostics(): array {
		$messages = array();

		if ( self::rest_is_blocked_for_anonymous_requests() ) {
			$messages[] = __( 'The REST API seems to be blocked for anonymous requests, probably by a security plugin or a setting of the site. Public feeds cannot be read until anonymous requests to the REST API are allowed.', 'kk-content-exporter' );
		}

		if ( self::has_active_authenticated_feed() && ! wp_is_application_passwords_available() ) {
			$messages[] = __( 'Some feeds require authentication, but Application Passwords are not available on this site: WordPress offers them only over HTTPS or in a local environment. Authenticated feeds cannot be read until then.', 'kk-content-exporter' );
		}

		return $messages;
	}

	/**
	 * Whether a plugin or a setting rejects anonymous requests to the REST API.
	 *
	 * Runs the filter `rest_authentication_errors` as an anonymous user, then restores the signed-in user.
	 * An invalid nonce error is ignored: it depends on this request, not on the site.
	 *
	 * @return bool
	 */
	private static function rest_is_blocked_for_anonymous_requests(): bool {
		$user_id = get_current_user_id();

		wp_set_current_user( 0 );
		try {
			$result = apply_filters( 'rest_authentication_errors', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Runs the core filter on purpose, to see whether something rejects anonymous requests.
		} finally {
			wp_set_current_user( $user_id );
		}

		return $result instanceof WP_Error && 'rest_cookie_invalid_nonce' !== $result->get_error_code();
	}

	/**
	 * Whether an enabled feed needs authentication, by its access or by the global requirement.
	 *
	 * An access that is not exactly `public` counts as authenticated, as everywhere in the plugin.
	 *
	 * @return bool
	 */
	private static function has_active_authenticated_feed(): bool {
		$require_auth = Settings::get()['require_auth'];

		$feeds = get_posts(
			array(
				'post_type'     => Feed_Post_Type::POST_TYPE,
				'post_status'   => 'any',
				'numberposts'   => self::DIAGNOSTIC_FEEDS,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);

		foreach ( $feeds as $feed_id ) {
			if ( ! Feed_Post_Type::sanitize_active( get_post_meta( $feed_id, Feed_Post_Type::META_ACTIVE, true ) ) ) {
				continue;
			}

			if ( $require_auth || Feed_Post_Type::ACCESS_AUTHENTICATED === Feed_Post_Type::sanitize_access( get_post_meta( $feed_id, Feed_Post_Type::META_ACCESS, true ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * HTML id of the control of a setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	private static function field_id( string $key ): string {
		return 'kkce-setting-' . str_replace( '_', '-', $key );
	}
}

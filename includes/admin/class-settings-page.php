<?php
/**
 * The settings page of the plugin.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Output\Sensitive_Keys;
use KKCE\Settings;
use WP_Error;
use WP_REST_Server;

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
			'enabled'       => array(
				'title'       => __( 'Endpoints', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Serve the feeds', 'kk-content-exporter' ),
				'description' => __( 'Turn it off to stop every feed from answering. The feeds and their settings are kept.', 'kk-content-exporter' ),
			),
			'show_in_index' => array(
				'title'       => __( 'REST API index', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Show the feed endpoint in the REST API index', 'kk-content-exporter' ),
				'description' => __( 'The index of the REST API (/wp-json/) describes the address and the parameters of the feeds, which helps whoever connects an importer. Turn it off to leave the endpoint out of the index: the feeds keep answering, so this does not protect them.', 'kk-content-exporter' ),
			),
			'require_auth'  => array(
				'title'       => __( 'Authentication', 'kk-content-exporter' ),
				'type'        => 'checkbox',
				'label'       => __( 'Require authentication for all feeds', 'kk-content-exporter' ),
				'description' => __( 'It takes precedence over the access chosen in each feed.', 'kk-content-exporter' ),
			),
			'max_per_page'  => array(
				'title'       => __( 'Maximum items per page', 'kk-content-exporter' ),
				'type'        => 'number',
				'label'       => '',
				'description' => __( 'The largest number of items that a feed can serve in one page. There is no upper limit: very high values make every response heavy and are your responsibility.', 'kk-content-exporter' ),
			),
			'date_filters'  => array(
				'title'       => __( 'Date filters', 'kk-content-exporter' ),
				'type'        => 'group',
				'label'       => '',
				'description' => __( 'An importer uses them to read only what changed since its last reading. A filter turned off is refused with an error, never ignored, so an importer never reads more than it asked for.', 'kk-content-exporter' ),
				'options'     => array(
					/* translators: %s: name of a parameter of the feed endpoint, kept in English. */
					'filter_modified_after'  => sprintf( __( '%s: items modified at or after the date', 'kk-content-exporter' ), 'modified_after' ),
					/* translators: %s: name of a parameter of the feed endpoint, kept in English. */
					'filter_modified_before' => sprintf( __( '%s: items modified before the date', 'kk-content-exporter' ), 'modified_before' ),
					/* translators: %s: name of a parameter of the feed endpoint, kept in English. */
					'filter_published_after' => sprintf( __( '%s: items published at or after the date', 'kk-content-exporter' ), 'published_after' ),
				),
			),
			'remove_data'   => array(
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
					'title'       => $field['title'],
					'type'        => $field['type'],
					'label'       => $field['label'],
					'description' => $field['description'],
					'options'     => $field['options'] ?? array(),
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

		if ( 'group' === ( $args['type'] ?? '' ) ) {
			self::render_group( $key, $args, $settings );
			return;
		}

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
	 * Prints a group of boxes of one row, each bound to its own setting, in a fieldset named by the title of the row.
	 *
	 * @param string               $key      Name of the row.
	 * @param array<mixed>         $args     Arguments given to `add_settings_field()`, with `options` (setting => label).
	 * @param array<string, mixed> $settings Current settings.
	 * @return void
	 */
	private static function render_group( string $key, array $args, array $settings ): void {
		$id          = self::field_id( $key );
		$title       = isset( $args['title'] ) && is_string( $args['title'] ) ? $args['title'] : '';
		$description = isset( $args['description'] ) && is_string( $args['description'] ) ? $args['description'] : '';
		$options     = isset( $args['options'] ) && is_array( $args['options'] ) ? $args['options'] : array();
		?>
		<fieldset aria-describedby="<?php echo esc_attr( $id ); ?>-help">
			<legend class="screen-reader-text"><?php echo esc_html( $title ); ?></legend>
			<?php
			foreach ( $options as $setting => $label ) {
				if ( ! is_string( $setting ) || ! is_string( $label ) || ! array_key_exists( $setting, $settings ) ) {
					continue;
				}
				$box_id = self::field_id( $setting );
				?>
				<label for="<?php echo esc_attr( $box_id ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $box_id ); ?>" name="<?php echo esc_attr( Settings::OPTION . '[' . $setting . ']' ); ?>" value="1"<?php checked( true === $settings[ $setting ] ); ?>>
					<?php echo esc_html( $label ); ?>
				</label><br>
				<?php
			}
			?>
		</fieldset>
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

		$feeds = self::enabled_feed_findings();

		if ( $feeds['authenticated'] && ! wp_is_application_passwords_available() ) {
			$messages[] = __( 'Some feeds require authentication, but Application Passwords are not available on this site: WordPress offers them only over HTTPS or in a local environment. Authenticated feeds cannot be read until then.', 'kk-content-exporter' );
		}

		$sensitive = $feeds['sensitive'];
		if ( array() !== $sensitive ) {
			$messages[] = sprintf(
				/* translators: %s: titles of the feeds, separated by commas. */
				__( 'These enabled feeds export fields confirmed as sensitive or personal: %s. Check that this is intended, above all for public feeds.', 'kk-content-exporter' ),
				implode( wp_get_list_item_separator(), $sensitive )
			);
		}

		return $messages;
	}

	/**
	 * What the diagnostics need to know about the enabled feeds, read in one pass over the feeds.
	 *
	 * - `authenticated`: whether an enabled feed needs authentication, by its access or by the global
	 *   requirement; an access that is not exactly `public` counts as authenticated, as everywhere in the plugin.
	 * - `sensitive`: the titles of the enabled feeds whose mapping confirms sensitive sources (the step 11
	 *   decision of 2026-11-10: the administrator sees which feeds export them), as plain text.
	 *
	 * @return array{authenticated: bool, sensitive: list<string>}
	 */
	private static function enabled_feed_findings(): array {
		$require_auth  = Settings::get()['require_auth'];
		$authenticated = false;
		$sensitive     = array();

		foreach ( self::diagnostic_feeds() as $feed_id ) {
			if ( ! Feed_Meta::sanitize_active( get_post_meta( $feed_id, Feed_Meta::META_ACTIVE, true ) ) ) {
				continue;
			}

			if ( $require_auth || Feed_Meta::ACCESS_AUTHENTICATED === Feed_Meta::sanitize_access( get_post_meta( $feed_id, Feed_Meta::META_ACCESS, true ) ) ) {
				$authenticated = true;
			}

			if ( array() !== Sensitive_Keys::confirmed_sources( Feed_Meta::mapping( $feed_id ) ) ) {
				$sensitive[] = wp_strip_all_tags( get_the_title( $feed_id ) );
			}
		}

		return array(
			'authenticated' => $authenticated,
			'sensitive'     => $sensitive,
		);
	}

	/**
	 * IDs of the feeds that the diagnostics look at, at most `DIAGNOSTIC_FEEDS`.
	 *
	 * @return list<int>
	 */
	private static function diagnostic_feeds(): array {
		return array_values(
			array_map(
				'intval',
				get_posts(
					array(
						'post_type'     => Feed_Post_Type::POST_TYPE,
						'post_status'   => 'any',
						'numberposts'   => self::DIAGNOSTIC_FEEDS,
						'fields'        => 'ids',
						'no_found_rows' => true,
					)
				)
			)
		);
	}

	/**
	 * Whether a plugin or a setting rejects anonymous requests to the REST API.
	 *
	 * Asks the REST server of WordPress to check the authentication (`WP_REST_Server::check_authentication()`,
	 * which runs the filter `rest_authentication_errors`) as an anonymous user, then restores the signed-in
	 * user. A new server object is used, so no `rest_api_init` runs and no request is made. An invalid nonce
	 * error is ignored: it depends on this request, not on the site.
	 *
	 * @return bool
	 */
	private static function rest_is_blocked_for_anonymous_requests(): bool {
		$user_id = get_current_user_id();

		wp_set_current_user( 0 );
		try {
			$result = ( new WP_REST_Server() )->check_authentication();
		} finally {
			wp_set_current_user( $user_id );
		}

		return $result instanceof WP_Error && 'rest_cookie_invalid_nonce' !== $result->get_error_code();
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

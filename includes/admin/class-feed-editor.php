<?php
/**
 * Editor of a feed: the settings box and the saving of the form.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Form;
use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Feed_Validator;
use KKCE\Plugin;
use KKCE\Settings;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Feed settings" box to the editor of a `kkce_feed` and saves it.
 *
 * The name of the feed is the native title. Everything else is in one native meta box: slug, source
 * post types (one checkbox each), access, active flag and items per page. The post types are saved
 * as a multiple meta: when the list changes, its rows are deleted and written again.
 *
 * Saving works in two steps of the same request. `wp_insert_post_data` verifies the nonce and the
 * capability, processes the form with the pure `Feed_Form` and sets the slug (`post_name`) of the
 * feed; `save_post_kkce_feed` then writes the meta. Fields that are refused keep their previous value
 * and are listed in a notice shown once, after the redirect. The slug is never renamed silently: a
 * slug that is invalid, reserved or used by another feed is refused.
 *
 * @phpstan-import-type FormValues from Feed_Form
 */
final class Feed_Editor {

	/**
	 * Name of the array that holds the fields of the form.
	 */
	public const FIELD = 'kkce_feed';

	/**
	 * Name of the nonce field.
	 */
	public const NONCE_FIELD = 'kkce_feed_nonce';

	/**
	 * Identifier of the meta box.
	 */
	public const META_BOX_ID = 'kkce_feed_settings';

	/**
	 * Handle of the editor script.
	 */
	public const SCRIPT_HANDLE = 'kkce-feed-editor';

	/**
	 * Placeholder of the slug in the address template given to the editor script.
	 */
	public const SLUG_PLACEHOLDER = '__kkce_slug__';

	/**
	 * Prefix of the nonce action; the ID of the feed is appended, so a nonce is valid for one feed.
	 */
	private const NONCE_ACTION = 'kkce_save_feed_';

	/**
	 * Seconds for which the errors of a save are kept to be shown after the redirect.
	 */
	private const NOTICE_LIFETIME = 60;

	/**
	 * ID of the feed processed by the current request, 0 when none.
	 *
	 * @var int
	 */
	private static int $pending_id = 0;

	/**
	 * Values to save for the feed processed by the current request.
	 *
	 * @var FormValues|null
	 */
	private static ?array $pending_values = null;

	/**
	 * Errors found in the current request, field => error code.
	 *
	 * @var array<string, string>
	 */
	private static array $pending_errors = array();

	/**
	 * Registers the settings box. Hooked on `add_meta_boxes_kkce_feed`.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Feed settings', 'kk-content-exporter' ),
			array( self::class, 'render_meta_box' ),
			Feed_Post_Type::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Loads the editor script on the screens that edit or create a feed, and nowhere else.
	 * Hooked on `admin_enqueue_scripts`.
	 *
	 * The script updates the address of the feed while the slug is typed and asks for confirmation
	 * before leaving the page with unsaved changes in the settings box. The box works without it.
	 *
	 * @param mixed $hook_suffix Admin page being loaded.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ): void {
		if ( 'post.php' !== $hook_suffix && 'post-new.php' !== $hook_suffix ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || Feed_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/feed-editor.js', KKCE_PLUGIN_FILE ),
			array(),
			KKCE_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Post types a feed can read from: the public ones, `attachment` excluded.
	 *
	 * @return array<string, string> Post type key => label, sorted by label.
	 */
	public static function selectable_post_types(): array {
		$types = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $type ) {
			if ( 'attachment' !== $name ) {
				$types[ $name ] = $type->label;
			}
		}

		asort( $types, SORT_NATURAL | SORT_FLAG_CASE );

		return $types;
	}

	/**
	 * Post types shown as checkboxes: the selectable ones and, after them, the saved ones that are no
	 * longer selectable (their plugin deactivated, or no longer public).
	 *
	 * A saved post type that is not selectable stays checked and is kept on save until the administrator
	 * unchecks it, so a feed never loses a source without notice. A post type that is neither selectable
	 * nor saved is refused.
	 *
	 * @param string[] $saved Post types saved for the feed.
	 * @return array<string, string> Post type key => label.
	 *
	 * @phpstan-param list<string> $saved
	 */
	private static function source_choices( array $saved ): array {
		$choices = self::selectable_post_types();

		foreach ( $saved as $name ) {
			if ( ! isset( $choices[ $name ] ) ) {
				/* translators: %s: key of a post type saved for the feed that no longer exists or is no longer public. */
				$choices[ $name ] = sprintf( __( '%s (not available)', 'kk-content-exporter' ), $name );
			}
		}

		return $choices;
	}

	/**
	 * Prints the settings box.
	 *
	 * @param mixed $post The feed being edited.
	 * @return void
	 */
	public static function render_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$values = self::current_values( $post->ID );

		wp_nonce_field( self::NONCE_ACTION . $post->ID, self::NONCE_FIELD );
		?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="kkce-feed-slug"><?php esc_html_e( 'Slug', 'kk-content-exporter' ); ?> <?php self::print_required_marker(); ?></label></th>
					<td>
						<input type="text" id="kkce-feed-slug" name="<?php echo esc_attr( self::FIELD ); ?>[slug]" value="<?php echo esc_attr( $values['slug'] ); ?>" class="regular-text" maxlength="<?php echo esc_attr( (string) Feed_Validator::SLUG_MAX_LENGTH ); ?>" autocomplete="off" aria-required="true" aria-describedby="kkce-feed-slug-help kkce-feed-url-help">
						<p class="description" id="kkce-feed-slug-help"><?php esc_html_e( 'Last part of the address of the feed. Lowercase letters, digits and dashes.', 'kk-content-exporter' ); ?></p>
						<?php self::render_address( $values['slug'] ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Source', 'kk-content-exporter' ); ?> <?php self::print_required_marker(); ?></th>
					<td>
						<fieldset aria-describedby="kkce-feed-post-types-help">
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Source post types', 'kk-content-exporter' ); ?></span></legend>
							<?php foreach ( self::source_choices( $values['post_types'] ) as $name => $label ) : ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::FIELD ); ?>[post_types][]" value="<?php echo esc_attr( $name ); ?>"<?php checked( in_array( $name, $values['post_types'], true ) ); ?>>
									<?php echo esc_html( $label ); ?>
								</label><br>
							<?php endforeach; ?>
						</fieldset>
						<p class="description" id="kkce-feed-post-types-help"><?php esc_html_e( 'The post types whose published content the feed exports. Items of different types share the same response, order and pages.', 'kk-content-exporter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access', 'kk-content-exporter' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><span><?php esc_html_e( 'Access', 'kk-content-exporter' ); ?></span></legend>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::FIELD ); ?>[access]" value="<?php echo esc_attr( Feed_Meta::ACCESS_PUBLIC ); ?>"<?php checked( $values['access'], Feed_Meta::ACCESS_PUBLIC ); ?>>
								<?php esc_html_e( 'Public: anyone can read the feed', 'kk-content-exporter' ); ?>
							</label><br>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::FIELD ); ?>[access]" value="<?php echo esc_attr( Feed_Meta::ACCESS_AUTHENTICATED ); ?>"<?php checked( $values['access'], Feed_Meta::ACCESS_AUTHENTICATED ); ?>>
								<?php esc_html_e( 'Authenticated: only an authorized user can read the feed', 'kk-content-exporter' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kkce-feed-per-page"><?php esc_html_e( 'Items per page', 'kk-content-exporter' ); ?></label></th>
					<td>
						<input type="number" id="kkce-feed-per-page" name="<?php echo esc_attr( self::FIELD ); ?>[per_page]" value="<?php echo esc_attr( (string) $values['per_page'] ); ?>" min="1" step="1" class="small-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'kk-content-exporter' ); ?></th>
					<td>
						<label for="kkce-feed-active">
							<input type="checkbox" id="kkce-feed-active" name="<?php echo esc_attr( self::FIELD ); ?>[active]" value="1"<?php checked( $values['active'] ); ?> aria-describedby="kkce-feed-active-help">
							<?php esc_html_e( 'Enable the feed', 'kk-content-exporter' ); ?>
						</label>
						<p class="description" id="kkce-feed-active-help"><?php esc_html_e( 'A feed needs a slug and at least one source post type to be enabled.', 'kk-content-exporter' ); ?></p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Processes the form while the feed is being saved and sets its slug.
	 *
	 * Hooked on `wp_insert_post_data`, which runs for every post type: the function leaves everything
	 * else untouched, and also saves from other places (quick edit, trash, autosave, code) that do
	 * not carry a valid nonce of the feed editor.
	 *
	 * @param mixed $data    Slashed post data about to be saved.
	 * @param mixed $postarr Post data given to `wp_insert_post()`.
	 * @return mixed The post data, with the slug of the feed when the form is valid for this request.
	 */
	public static function filter_post_data( $data, $postarr ) {
		self::reset_pending();

		if ( ! is_array( $data ) || ! is_array( $postarr ) || Feed_Post_Type::POST_TYPE !== ( $data['post_type'] ?? null ) ) {
			return $data;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return $data;
		}

		$post_id = isset( $postarr['ID'] ) ? (int) $postarr['ID'] : 0;
		$nonce   = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		if ( $post_id < 1 || '' === $nonce || false === wp_verify_nonce( $nonce, self::NONCE_ACTION . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return $data;
		}

		// No checkbox checked sends nothing: an empty list. A value that is not a list (a tampered request)
		// becomes null, which the validation refuses, keeping the saved list.
		$post_types = array();
		if ( isset( $_POST[ self::FIELD ]['post_types'] ) ) {
			$post_types = is_array( $_POST[ self::FIELD ]['post_types'] )
				? array_values( array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::FIELD ]['post_types'] ) ) )
				: null;
		}

		$input = array(
			'slug'       => isset( $_POST[ self::FIELD ]['slug'] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ]['slug'] ) ) : '',
			'post_types' => $post_types,
			'access'     => isset( $_POST[ self::FIELD ]['access'] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ]['access'] ) ) : '',
			'active'     => isset( $_POST[ self::FIELD ]['active'] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ self::FIELD ]['active'] ) ),
			'per_page'   => isset( $_POST[ self::FIELD ]['per_page'] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD ]['per_page'] ) ) : '',
		);

		$current = self::current_values( $post_id );
		$result  = Feed_Form::process(
			$input,
			array(
				'slug_is_taken'      => static fn( string $slug ): bool => self::slug_is_taken( $slug, $post_id ),
				'allowed_post_types' => array_keys( self::source_choices( $current['post_types'] ) ),
				'max_per_page'       => Settings::get()['max_per_page'],
				'current'            => $current,
			)
		);

		self::$pending_id     = $post_id;
		self::$pending_values = $result['values'];
		self::$pending_errors = $result['errors'];

		$data['post_name'] = $result['values']['slug'];

		return $data;
	}

	/**
	 * Writes the configuration meta of the feed processed by this request.
	 *
	 * Hooked on `save_post_kkce_feed`. It does nothing when `filter_post_data()` did not accept the
	 * request, so a save without a valid nonce or capability never touches the meta.
	 *
	 * @param mixed $post_id ID of the feed.
	 * @return void
	 */
	public static function save( $post_id ): void {
		$post_id = (int) $post_id;
		$values  = self::$pending_values;

		if ( $post_id < 1 || self::$pending_id !== $post_id || null === $values ) {
			return;
		}

		// Compares the stored rows, not the clean list: empty or duplicate rows are rewritten too.
		if ( get_post_meta( $post_id, Feed_Meta::META_POST_TYPE, false ) !== $values['post_types'] ) {
			delete_post_meta( $post_id, Feed_Meta::META_POST_TYPE );
			foreach ( $values['post_types'] as $post_type ) {
				add_post_meta( $post_id, Feed_Meta::META_POST_TYPE, $post_type );
			}
		}
		update_post_meta( $post_id, Feed_Meta::META_ACCESS, $values['access'] );
		update_post_meta( $post_id, Feed_Meta::META_ACTIVE, $values['active'] );
		update_post_meta( $post_id, Feed_Meta::META_PER_PAGE, $values['per_page'] );

		if ( array() === self::$pending_errors ) {
			delete_transient( self::notice_key( $post_id ) );
		} else {
			set_transient( self::notice_key( $post_id ), self::$pending_errors, self::NOTICE_LIFETIME );
		}

		self::reset_pending();
	}

	/**
	 * Shows, once, the errors of the last save of the feed on the screen of the editor.
	 *
	 * Hooked on `admin_notices`.
	 *
	 * @return void
	 */
	public static function render_notices(): void {
		$screen = get_current_screen();

		if ( null === $screen || 'post' !== $screen->base || Feed_Post_Type::POST_TYPE !== $screen->post_type ) {
			return;
		}

		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$key    = self::notice_key( $post->ID );
		$errors = get_transient( $key );
		if ( ! is_array( $errors ) ) {
			return;
		}

		delete_transient( $key );

		foreach ( $errors as $field => $code ) {
			$message = self::error_message( (string) $field, is_string( $code ) ? $code : '' );

			if ( '' !== $message ) {
				printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			}
		}
	}

	/**
	 * Values currently stored for a feed, read through the sanitizers so that a damaged value gives
	 * the safe default.
	 *
	 * @param int $post_id ID of the feed.
	 * @return FormValues
	 */
	private static function current_values( int $post_id ): array {
		$slug = get_post_field( 'post_name', $post_id );

		return array(
			'slug'       => is_string( $slug ) ? $slug : '',
			'post_types' => Feed_Meta::post_types( $post_id ),
			'access'     => Feed_Meta::sanitize_access( get_post_meta( $post_id, Feed_Meta::META_ACCESS, true ) ),
			'active'     => Feed_Meta::sanitize_active( get_post_meta( $post_id, Feed_Meta::META_ACTIVE, true ) ),
			'per_page'   => Feed_Meta::sanitize_per_page( get_post_meta( $post_id, Feed_Meta::META_PER_PAGE, true ) ),
		);
	}

	/**
	 * Prints the address of the feed under the slug field, or the reason why there is none.
	 *
	 * The address comes from `Plugin::feed_url()`, so it follows the permalink settings of the site
	 * (`/wp-json/…` or `?rest_route=…`). The `<output>` element carries what the editor script needs to
	 * update it while the slug is typed: a template of the address with a placeholder for the slug (built
	 * by `rest_url()` too, so a query string added by another plugin stays at the end) and the slug rules
	 * of the validator. The
	 * messages are printed here, already translated, and the script only shows or hides them. The
	 * uniqueness of the slug is checked only when the feed is saved.
	 *
	 * @param string $slug Saved slug.
	 * @return void
	 */
	private static function render_address( string $slug ): void {
		$state = Feed_Validator::validate_slug( $slug, array() );
		$url   = Plugin::feed_url( $slug );
		?>
		<p class="description" id="kkce-feed-url-help">
			<?php esc_html_e( 'Address of the feed:', 'kk-content-exporter' ); ?>
			<output id="kkce-feed-url" for="kkce-feed-slug" data-template="<?php echo esc_attr( rest_url( Plugin::REST_NAMESPACE . '/' . self::SLUG_PLACEHOLDER ) ); ?>" data-placeholder="<?php echo esc_attr( self::SLUG_PLACEHOLDER ); ?>" data-pattern="<?php echo esc_attr( Feed_Validator::SLUG_PATTERN ); ?>" data-max-length="<?php echo esc_attr( (string) Feed_Validator::SLUG_MAX_LENGTH ); ?>" data-reserved="<?php echo esc_attr( implode( ',', Feed_Validator::RESERVED_SLUGS ) ); ?>">
				<code<?php echo '' === $url ? ' hidden' : ''; ?>><?php echo esc_html( $url ); ?></code>
				<?php foreach ( array( Feed_Validator::ERROR_EMPTY, Feed_Validator::ERROR_FORMAT, Feed_Validator::ERROR_TOO_LONG, Feed_Validator::ERROR_RESERVED ) as $code ) : ?>
					<span data-kkce-message="<?php echo esc_attr( $code ); ?>"<?php echo $code === $state ? '' : ' hidden'; ?>><?php echo esc_html( self::error_message( 'slug', $code ) ); ?></span>
				<?php endforeach; ?>
			</output>
		</p>
		<p class="description"><?php esc_html_e( 'The address answers only while the feed is enabled.', 'kk-content-exporter' ); ?></p>
		<?php
	}

	/**
	 * Prints the marker of a required field: visible text, so it does not depend on color or symbols.
	 *
	 * @return void
	 */
	private static function print_required_marker(): void {
		echo '<span class="kkce-required">' . esc_html__( '(required)', 'kk-content-exporter' ) . '</span>';
	}

	/**
	 * Whether another feed already uses a slug. Feeds in the trash are not counted: WordPress renames
	 * their slug.
	 *
	 * @param string $slug    Slug to look for.
	 * @param int    $post_id ID of the feed being saved, excluded from the search.
	 * @return bool
	 */
	private static function slug_is_taken( string $slug, int $post_id ): bool {
		$found = get_posts(
			array(
				'post_type'     => Feed_Post_Type::POST_TYPE,
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
	 * Clears what was prepared for the previous request.
	 *
	 * @return void
	 */
	private static function reset_pending(): void {
		self::$pending_id     = 0;
		self::$pending_values = null;
		self::$pending_errors = array();
	}

	/**
	 * Name of the transient that carries the errors of a save to the next screen of the same user.
	 *
	 * @param int $post_id ID of the feed.
	 * @return string
	 */
	private static function notice_key( int $post_id ): string {
		return 'kkce_feed_errors_' . get_current_user_id() . '_' . $post_id;
	}

	/**
	 * Message for the error of a field.
	 *
	 * @param string $field Field name.
	 * @param string $code  Error code.
	 * @return string Empty for an unknown combination.
	 */
	private static function error_message( string $field, string $code ): string {
		return match ( $field . '.' . $code ) {
			'slug.empty'          => __( 'Enter a slug for the feed.', 'kk-content-exporter' ),
			'slug.format'         => __( 'The slug can contain only lowercase letters, digits and single dashes between them.', 'kk-content-exporter' ),
			'slug.too_long'       => sprintf(
				/* translators: %d: maximum number of characters of a slug. */
				__( 'The slug can have at most %d characters.', 'kk-content-exporter' ),
				Feed_Validator::SLUG_MAX_LENGTH
			),
			'slug.reserved'       => __( 'This slug is reserved. Choose another one.', 'kk-content-exporter' ),
			'slug.taken'          => __( 'Another feed already uses this slug.', 'kk-content-exporter' ),
			'post_types.empty'    => __( 'Choose at least one source post type.', 'kk-content-exporter' ),
			'post_types.not_allowed' =>__( 'One of the source post types is not available. The previous sources were kept.', 'kk-content-exporter' ),
			'access.not_allowed'  => __( 'The access value is not valid. The previous access was kept.', 'kk-content-exporter' ),
			'per_page.too_large'  => sprintf(
				/* translators: %d: maximum number of items per page set in the plugin settings. */
				__( 'Items per page cannot be higher than the maximum set in the plugin settings (%d). The previous value was kept.', 'kk-content-exporter' ),
				Settings::get()['max_per_page']
			),
			'per_page.not_integer', 'per_page.too_small' => __( 'Items per page must be a whole number of at least 1. The previous value was kept.', 'kk-content-exporter' ),
			'active.incomplete'   => __( 'The feed was saved disabled: set a valid slug and at least one source post type first.', 'kk-content-exporter' ),
			default               => '',
		};
	}
}

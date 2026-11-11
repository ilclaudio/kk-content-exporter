<?php
/**
 * The "Mapping" box of the feed editor.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Output\Mapping;
use KKCE\Output\Mapping_Validator;
use KKCE\Rest\Admin_Routes;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Adds the "Mapping" box to the editor of a `kkce_feed` and loads its script and style.
 *
 * The box prints only a container with the initial data of the editor, in an escaped `data-` attribute:
 * the saved mapping (canonical form) and the catalog of the saved post types, in the same form as the
 * catalog route (`Admin_Routes::describe_catalog()`). The script `assets/js/feed-mapping.js` builds the
 * interface, asks the route for a new catalog when the post types change, and writes the mapping in a
 * hidden field that it creates itself: without JavaScript nothing is sent, so the saved mapping cannot be
 * changed by mistake.
 */
final class Mapping_Box {

	/**
	 * Identifier of the meta box.
	 */
	public const META_BOX_ID = 'kkce_feed_mapping';

	/**
	 * Handle of the script of the mapping editor.
	 */
	public const SCRIPT_HANDLE = 'kkce-feed-mapping';

	/**
	 * Handle of the style of the mapping editor.
	 */
	public const STYLE_HANDLE = 'kkce-feed-mapping';

	/**
	 * Registers the box, below the settings box. Hooked on `add_meta_boxes_kkce_feed`.
	 *
	 * @return void
	 */
	public static function add_meta_box(): void {
		add_meta_box(
			self::META_BOX_ID,
			__( 'Mapping', 'kk-content-exporter' ),
			array( self::class, 'render_meta_box' ),
			Feed_Post_Type::POST_TYPE,
			'normal',
			'default'
		);
	}

	/**
	 * Prints the container of the mapping editor with its initial data.
	 *
	 * @param mixed $post The feed being edited.
	 * @return void
	 */
	public static function render_meta_box( $post ): void {
		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$data = array(
			'mapping'        => Feed_Meta::mapping( $post->ID ),
			'catalog'        => Admin_Routes::describe_catalog( Feed_Meta::post_types( $post->ID ) ),
			'catalogPath'    => '/' . Admin_Routes::NAMESPACE . '/catalog',
			'field'          => Feed_Editor::FIELD . '[mapping]',
			'maxRows'        => Mapping::MAX_ROWS,
			'keyMaxLength'   => Mapping::KEY_MAX_LENGTH,
			'keyPattern'     => Mapping_Validator::KEY_FORMAT,
			'maxChain'       => Mapping_Validator::MAX_CHAIN,
			'valueMaxLength' => Mapping_Validator::VALUE_MAX_LENGTH,
		);
		?>
		<?php // Removed when the feed editor saves the mapping (step 11 of the plan). ?>
		<div class="notice notice-warning inline kkce-mapping-unsaved"><p><?php esc_html_e( 'The mapping is not saved yet: in this development version the rows built here are lost when the feed is updated or the page is left.', 'kk-content-exporter' ); ?></p></div>
		<p class="description"><?php esc_html_e( 'Each row writes one key in every exported item: choose the key, the field it reads and how the value is written. A key without a value is written with null, so every item has the same keys.', 'kk-content-exporter' ); ?></p>
		<div id="kkce-feed-mapping" class="kkce-mapping" data-kkce-mapping="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>"></div>
		<noscript>
			<p><?php esc_html_e( 'The mapping editor needs JavaScript. Without it, saving the feed keeps the saved mapping unchanged.', 'kk-content-exporter' ); ?></p>
		</noscript>
		<?php
	}

	/**
	 * Loads the script and the style of the mapping editor on the screens that edit or create a feed.
	 * Hooked on `admin_enqueue_scripts`.
	 *
	 * @param mixed $hook_suffix Admin page being loaded.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ): void {
		if ( ! Feed_Editor::is_feed_screen( $hook_suffix ) ) {
			return;
		}

		wp_enqueue_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/feed-mapping.css', KKCE_PLUGIN_FILE ),
			array(),
			KKCE_VERSION
		);

		wp_enqueue_script(
			self::SCRIPT_HANDLE,
			plugins_url( 'assets/js/feed-mapping.js', KKCE_PLUGIN_FILE ),
			array( 'wp-api-fetch', 'wp-i18n' ),
			KKCE_VERSION,
			array( 'in_footer' => true )
		);

		wp_set_script_translations( self::SCRIPT_HANDLE, 'kk-content-exporter', KKCE_PLUGIN_DIR . 'languages' );
	}
}

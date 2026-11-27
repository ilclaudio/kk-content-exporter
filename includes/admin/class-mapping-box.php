<?php
/**
 * The "Mapping" box of the feed editor.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Arrays;
use KKCE\Feed_Meta;
use KKCE\Feed_Post_Type;
use KKCE\Output\Mapping;
use KKCE\Output\Mapping_Validator;
use KKCE\Output\Template;
use KKCE\Rest\Admin_Routes;
use KKCE\Sources\Meta_Keys;
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
	 * Help of the rules the editor offers: one short sentence shown under the rule, and the address of the tab of
	 * the guide that explains it (every rule has a tab of the same name).
	 *
	 * @return array<string, array{help: string, guide: string}> Kind of rule => help and address.
	 */
	private static function rules(): array {
		$help = array(
			'field'     => __( 'Reads a field of the content; a fallback is used when the first field is empty.', 'kk-content-exporter' ),
			'fixed'     => __( 'Writes the same text in every item.', 'kk-content-exporter' ),
			'template'  => __( 'Joins fields and words in one text.', 'kk-content-exporter' ),
			'lookup'    => __( 'Translates the value of a field with a table, like a switch; the comparison is exact.', 'kk-content-exporter' ),
			'condition' => __( 'Checks one field and writes one output when the check is true, another when it is false.', 'kk-content-exporter' ),
		);

		$rules = array();
		foreach ( $help as $kind => $text ) {
			$rules[ $kind ] = array(
				'help'  => $text,
				'guide' => Guide_Page::url( $kind ),
			);
		}

		return $rules;
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
			'mapping'           => Feed_Meta::mapping( $post->ID ),
			'catalog'           => Admin_Routes::describe_catalog( Feed_Meta::post_types( $post->ID ) ),
			'catalogPath'       => '/' . Admin_Routes::NAMESPACE . '/catalog',
			'previewPath'       => '/' . Admin_Routes::NAMESPACE . '/preview',
			'itemsPath'         => '/' . Admin_Routes::NAMESPACE . '/items',
			'field'             => Feed_Editor::FIELD . '[mapping]',
			'maxRows'           => Mapping::MAX_ROWS,
			'keyMaxLength'      => Mapping::KEY_MAX_LENGTH,
			'keyPattern'        => Mapping_Validator::KEY_FORMAT,
			'maxChain'          => Mapping_Validator::MAX_CHAIN,
			'valueMaxLength'    => Mapping_Validator::VALUE_MAX_LENGTH,
			'templateMaxLength' => Template::MAX_LENGTH,
			'maxCases'          => Mapping_Validator::MAX_CASES,
			'maxLengthLimit'    => Mapping_Validator::MAX_LENGTH_LIMIT,
			'metaKeyMaxLength'  => Meta_Keys::MAX_LENGTH,
			'rules'             => self::rules(),
			'languageGuide'     => Guide_Page::url( 'overview' ) . '#kkce-guide-multilingual',
		);

		$draft = self::refused_mapping( $post->ID );
		if ( null !== $draft ) {
			$data['draft'] = array(
				'errors'   => $draft['errors'],
				'readable' => null !== $draft['mapping'],
			);
			// The JSON text, not the decoded array: PHP decodes an empty object `{}` as an empty array, which
			// would come back to the script as `[]` and make an unfinished row impossible to edit.
			if ( null !== $draft['mapping'] ) {
				$data['draft']['mapping'] = $draft['mapping'];
			}
		}
		?>
		<p class="description kkce-box-intro">
			<?php esc_html_e( 'Each row defines one key of the JSON of every item. Use the preview to check the result before saving.', 'kk-content-exporter' ); ?>
			<?php // A new tab: the editor often has unsaved changes, and leaving it would ask to discard them. ?>
			<a href="<?php echo esc_url( Guide_Page::url( 'overview' ) ); ?>" target="_blank"><?php esc_html_e( 'How the rows work', 'kk-content-exporter' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'kk-content-exporter' ); ?></span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>
		</p>
		<div id="kkce-feed-mapping" class="kkce-mapping" data-kkce-mapping="<?php echo esc_attr( (string) wp_json_encode( $data ) ); ?>"></div>
		<noscript>
			<p><?php esc_html_e( 'The mapping editor needs JavaScript. Without it, saving the feed keeps the saved mapping unchanged.', 'kk-content-exporter' ); ?></p>
		</noscript>
		<?php
	}

	/**
	 * Mapping refused by the last save of the current user, read once: the box shows it again with its errors,
	 * so that the work is not lost (decision of 2026-11-13).
	 *
	 * @param int $post_id ID of the feed.
	 * @return array{mapping: string|null, errors: array<mixed>}|null The JSON text of the mapping (null when it
	 *         cannot be shown as rows) and its errors; null without a draft.
	 */
	private static function refused_mapping( int $post_id ): ?array {
		$key   = Feed_Editor::draft_key( $post_id );
		$draft = get_transient( $key );
		if ( ! is_array( $draft ) ) {
			return null;
		}
		delete_transient( $key );

		$mapping = Mapping::decode( $draft['mapping'] ?? null );
		$rows    = $mapping['rows'] ?? null;
		$usable  = is_array( $rows ) && Arrays::is_list( $rows ) && array() === array_filter( $rows, static fn( $row ): bool => ! is_array( $row ) );

		return array(
			'mapping' => $usable ? (string) $draft['mapping'] : null,
			'errors'  => is_array( $draft['errors'] ?? null ) ? $draft['errors'] : array(),
		);
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

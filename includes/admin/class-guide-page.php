<?php
/**
 * The guide page of the plugin.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

use KKCE\Feed_Post_Type;

defined( 'ABSPATH' ) || exit;

/**
 * "Guide" submenu of the Content Exporter menu, after "Settings": how the rows of a mapping work, one tab per
 * topic (overview, each rule, output types, preview), each with examples.
 *
 * The tabs are links to their own address (`?page=kkce-guide&tab=<tab>`), printed by the server, so a tab can be
 * linked from the mapping editor and the page works without JavaScript. The page only shows text: it reads no
 * data and changes nothing.
 */
final class Guide_Page {

	/**
	 * Slug of the page.
	 */
	public const MENU_SLUG = 'kkce-guide';

	/**
	 * Capability required to see the page, the same as the editor of the feeds.
	 */
	private const CAPABILITY = 'manage_options';

	/**
	 * Markup allowed in the texts of the guide.
	 */
	private const ALLOWED_HTML = array(
		'code'   => array(),
		'strong' => array(),
		'em'     => array(),
	);

	/**
	 * Adds the page under the Content Exporter menu. Hooked on `admin_menu` after the settings page, so it follows
	 * it in the menu.
	 *
	 * @return void
	 */
	public static function add_menu(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		add_submenu_page(
			'edit.php?post_type=' . Feed_Post_Type::POST_TYPE,
			__( 'Content Exporter guide', 'kk-content-exporter' ),
			__( 'Guide', 'kk-content-exporter' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Address of a tab of the guide.
	 *
	 * @param string $tab One of `Guide_Tabs::TABS`; any other value gives the first tab.
	 * @return string Address, not escaped.
	 */
	public static function url( string $tab ): string {
		return add_query_arg(
			array(
				'post_type' => Feed_Post_Type::POST_TYPE,
				'page'      => self::MENU_SLUG,
				'tab'       => Guide_Tabs::choose( $tab ),
			),
			admin_url( 'edit.php' )
		);
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

		// Read-only choice of the tab to show, compared with a fixed list: no state changes, so no nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		$current   = Guide_Tabs::choose( $requested );
		$labels    = self::labels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Exporter guide', 'kk-content-exporter' ); ?></h1>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Topics of the guide', 'kk-content-exporter' ); ?>">
				<?php
				foreach ( Guide_Tabs::TABS as $tab ) {
					printf(
						'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
						esc_url( self::url( $tab ) ),
						$tab === $current ? ' nav-tab-active' : '',
						$tab === $current ? ' aria-current="page"' : '',
						esc_html( $labels[ $tab ] )
					);
				}
				?>
			</nav>
			<div class="kkce-guide">
				<h2><?php echo esc_html( $labels[ $current ] ); ?></h2>
				<?php self::render_blocks( self::content( $current ) ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Labels of the tabs, the same words as in the mapping editor.
	 *
	 * @return array<string, string> Tab => label, for every tab of `Guide_Tabs::TABS`.
	 */
	private static function labels(): array {
		return array(
			'overview'  => __( 'Overview', 'kk-content-exporter' ),
			'field'     => __( 'Field', 'kk-content-exporter' ),
			'fixed'     => __( 'Fixed value', 'kk-content-exporter' ),
			'template'  => __( 'Template', 'kk-content-exporter' ),
			'lookup'    => __( 'Lookup table', 'kk-content-exporter' ),
			'condition' => __( 'Condition', 'kk-content-exporter' ),
			'output'    => __( 'Output types', 'kk-content-exporter' ),
			'preview'   => __( 'Preview', 'kk-content-exporter' ),
		);
	}

	/**
	 * Prints the blocks of a tab.
	 *
	 * A block is one of: `array( 'p', text )`, `array( 'h3', text, anchor? )` (anchor printed as `id="kkce-guide-<anchor>"`), `array( 'list', list<text> )`,
	 * `array( 'example', settings, results )` where settings are pairs label => value of the row and results are
	 * pairs caption => JSON of the item. Texts can hold `code`, `strong` and `em`.
	 *
	 * @param list<array{0: string, 1: mixed, 2?: mixed}> $blocks Blocks.
	 * @return void
	 */
	private static function render_blocks( array $blocks ): void {
		foreach ( $blocks as $block ) {
			switch ( $block[0] ) {
				case 'h3':
					// An optional anchor, so another screen can link to the section.
					if ( isset( $block[2] ) ) {
						printf( '<h3 id="kkce-guide-%1$s">%2$s</h3>', esc_attr( (string) $block[2] ), esc_html( (string) $block[1] ) );
					} else {
						printf( '<h3>%s</h3>', esc_html( (string) $block[1] ) );
					}
					break;
				case 'list':
					echo '<ul class="ul-disc">';
					foreach ( (array) $block[1] as $item ) {
						printf( '<li>%s</li>', wp_kses( (string) $item, self::ALLOWED_HTML ) );
					}
					echo '</ul>';
					break;
				case 'example':
					self::render_example( (array) $block[1], (array) ( $block[2] ?? array() ) );
					break;
				default:
					printf( '<p>%s</p>', wp_kses( (string) $block[1], self::ALLOWED_HTML ) );
			}
		}
	}

	/**
	 * Prints an example: the settings of a row, then the JSON it gives for some items.
	 *
	 * @param array<mixed, mixed> $settings Label of a setting => its value.
	 * @param array<mixed, mixed> $results  Caption of an item => the JSON it gets.
	 * @return void
	 */
	private static function render_example( array $settings, array $results ): void {
		echo '<table class="widefat striped kkce-guide-settings" style="max-width: 40em;">';
		printf( '<caption class="screen-reader-text">%s</caption>', esc_html__( 'Settings of the row', 'kk-content-exporter' ) );
		echo '<tbody>';
		foreach ( $settings as $label => $value ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( (string) $label ), wp_kses( (string) $value, self::ALLOWED_HTML ) );
		}
		echo '</tbody></table>';
		foreach ( $results as $caption => $json ) {
			printf( '<p>%s</p>', esc_html( (string) $caption ) );
			printf( '<pre style="background: #f6f7f7; border: 1px solid #dcdcde; max-width: 40em; padding: 0.75em; white-space: pre-wrap;">%s</pre>', esc_html( (string) $json ) );
		}
	}

	/**
	 * The blocks of a tab.
	 *
	 * @param string $tab One of `Guide_Tabs::TABS`.
	 * @return list<array{0: string, 1: mixed, 2?: mixed}>
	 */
	private static function content( string $tab ): array {
		$rule       = __( 'Rule', 'kk-content-exporter' );
		$key        = __( 'JSON key', 'kk-content-exporter' );
		$type       = __( 'Output type', 'kk-content-exporter' );
		$field      = __( 'Field', 'kk-content-exporter' );
		$fixed_text = __( 'Fixed text', 'kk-content-exporter' );
		$posts      = __( 'Posts', 'kk-content-exporter' );
		$pages      = __( 'Pages', 'kk-content-exporter' );
		/* translators: %s: name of a post type, for example Posts. */
		$item_of = __( 'An item of %s:', 'kk-content-exporter' );

		switch ( $tab ) {
			case 'field':
				return array(
					array( 'p', __( 'Reads a field of the content: a common field (title, content, excerpt, dates, author, featured image, language), a taxonomy, or a post meta.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Add fallback', 'kk-content-exporter' ) ),
					array( 'p', __( 'Another field is used when the first one is empty. The first field with a value wins: <code>0</code> and <code>false</code> are values, while an empty text, or spaces only, passes to the next field.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Add exception for a post type', 'kk-content-exporter' ) ),
					array( 'p', __( 'A feed that reads several post types can read other fields for one of them. The other post types keep the general fields.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Advanced options', 'kk-content-exporter' ) ),
					array( 'p', __( 'Content and excerpt accept two options: <strong>Raw content</strong>, the text as stored in the database (blocks are not turned into HTML), and <strong>Run the shortcodes</strong>, off by default (shortcodes are removed).', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Example', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key   => '<code>summary</code>',
							$rule  => $field,
							$field => __( 'Excerpt', 'kk-content-exporter' ),
							/* translators: %d: position of a fallback field in its chain (2 for the first fallback). */
							sprintf( __( 'Otherwise, field %d', 'kk-content-exporter' ), 2 ) => __( 'Title', 'kk-content-exporter' ),
						),
						array(
							__( 'An item with an excerpt:', 'kk-content-exporter' ) => '{ "summary": "An evening of music in the square." }',
							__( 'An item without an excerpt, titled "Summer concert":', 'kk-content-exporter' ) => '{ "summary": "Summer concert" }',
						),
					),
				);

			case 'fixed':
				return array(
					array( 'p', __( 'Every item gets the same text, for example the name of the site, or the language when the feed has one language only.', 'kk-content-exporter' ) ),
					array( 'p', __( 'With <strong>Add exception for a post type</strong> one post type gets another text. The text is converted to the output type of the row, for example to a number with the type Number.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Example', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key  => '<code>source</code>',
							$rule => __( 'Fixed value', 'kk-content-exporter' ),
							__( 'Value', 'kk-content-exporter' ) => 'Example Magazine',
							/* translators: %s: label of a post type. */
							sprintf( __( 'Value (For %s)', 'kk-content-exporter' ), $pages ) => 'Example Magazine – Info',
						),
						array(
							sprintf( $item_of, $posts ) => '{ "source": "Example Magazine" }',
							sprintf( $item_of, $pages ) => '{ "source": "Example Magazine – Info" }',
						),
					),
				);

			case 'template':
				return array(
					array( 'p', __( 'Joins fields and words in one text. Write the text and put the fields between braces; <strong>Insert field</strong> writes the field you choose where the cursor is.', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( '<code>{core.title}</code> writes the title of the item.', 'kk-content-exporter' ),
							__( '<code>{meta.city|meta.town|"unknown"}</code> uses the first one with a value; a text between double quotes is written as it is.', 'kk-content-exporter' ),
							__( '<code>[, {meta.address}]</code>: a part between square brackets is written only when all its fields have a value.', 'kk-content-exporter' ),
							__( 'Write <code>{{</code>, <code>}}</code>, <code>[[</code> and <code>]]</code> for a brace or a bracket.', 'kk-content-exporter' ),
						),
					),
					array( 'p', __( 'When no field of the template has a value, the key is <code>null</code>. The result is plain text, or HTML when the output type is HTML.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Example', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key  => '<code>place</code>',
							$rule => __( 'Template', 'kk-content-exporter' ),
							__( 'Template', 'kk-content-exporter' ) => '<code>{meta.city}[, {meta.address}]</code>',
						),
						array(
							__( 'An item with a city and an address:', 'kk-content-exporter' ) => '{ "place": "Florence, Via Roma 1" }',
							__( 'An item with a city only:', 'kk-content-exporter' ) => '{ "place": "Florence" }',
						),
					),
				);

			case 'lookup':
				return array(
					array( 'p', __( 'Reads a field and translates its value with a table, like a switch: every line says which value to look for and what to write instead.', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( 'The comparison is exact: capital letters count, spaces at the ends are ignored.', 'kk-content-exporter' ),
							__( 'The first line that matches gives the output: a fixed text, or another field.', 'kk-content-exporter' ),
							__( '<strong>When no line matches</strong> decides what is written otherwise: nothing (<code>null</code>), a fixed text, a field, or the value read, unchanged.', 'kk-content-exporter' ),
							__( 'The field to read must be one value: a list, such as all the categories, cannot be read. Use the first term of a taxonomy instead.', 'kk-content-exporter' ),
						),
					),
					array( 'h3', __( 'Example', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key  => '<code>section</code>',
							$rule => __( 'Lookup table', 'kk-content-exporter' ),
							__( 'Field to read', 'kk-content-exporter' ) => __( 'Post type (slug)', 'kk-content-exporter' ),
							__( 'Table', 'kk-content-exporter' ) => '<code>post</code> → News, <code>page</code> → Institutional pages',
							__( 'When no line matches', 'kk-content-exporter' ) => $fixed_text . ': Other',
						),
						array(
							__( 'A post:', 'kk-content-exporter' )        => '{ "section": "News" }',
							__( 'A page:', 'kk-content-exporter' )        => '{ "section": "Institutional pages" }',
							__( 'An item of another post type:', 'kk-content-exporter' ) => '{ "section": "Other" }',
						),
					),
				);

			case 'condition':
				return array(
					array( 'p', __( 'Checks one field and writes one output when the check is true, another when it is false: an if-then-else.', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( '<strong>is empty</strong>, <strong>is not empty</strong>: the field has no value, or has one; lists can be checked too.', 'kk-content-exporter' ),
							__( '<strong>is equal to</strong>, <strong>is different from</strong>: the value compared exactly with a text.', 'kk-content-exporter' ),
							__( '<strong>is one of</strong>: the value is one of the texts written one per line.', 'kk-content-exporter' ),
							__( '<strong>contains</strong>: for a list, one of its elements is equal to the text; for a text, the text appears inside it. Capital letters count.', 'kk-content-exporter' ),
						),
					),
					array( 'p', __( 'Each output, <strong>If true, write</strong> and <strong>Otherwise, write</strong>, can be nothing (<code>null</code>), a fixed text, a field, or the value read, unchanged.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Example: one field or another', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key  => '<code>summary</code>',
							$rule => __( 'Condition', 'kk-content-exporter' ),
							__( 'Field to read', 'kk-content-exporter' ) => __( 'Excerpt', 'kk-content-exporter' ),
							__( 'The field', 'kk-content-exporter' ) => __( 'is not empty', 'kk-content-exporter' ),
							__( 'If true, write', 'kk-content-exporter' ) => __( 'The value read, unchanged', 'kk-content-exporter' ),
							__( 'Otherwise, write', 'kk-content-exporter' ) => $field . ': ' . __( 'Title', 'kk-content-exporter' ),
						),
						array(
							__( 'An item with an excerpt:', 'kk-content-exporter' ) => '{ "summary": "An evening of music in the square." }',
							__( 'An item without an excerpt, titled "Summer concert":', 'kk-content-exporter' ) => '{ "summary": "Summer concert" }',
						),
					),
					array( 'h3', __( 'Example: true or false', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							$key  => '<code>featured</code>',
							$rule => __( 'Condition', 'kk-content-exporter' ),
							__( 'Field to read', 'kk-content-exporter' ) => __( 'Tags (all terms)', 'kk-content-exporter' ),
							__( 'The field', 'kk-content-exporter' ) => __( 'contains', 'kk-content-exporter' ) . ' <code>Featured</code>',
							__( 'If true, write', 'kk-content-exporter' ) => $fixed_text . ': <code>true</code>',
							__( 'Otherwise, write', 'kk-content-exporter' ) => $fixed_text . ': <code>false</code>',
							$type => __( 'True or false', 'kk-content-exporter' ),
						),
						array(
							__( 'An item with the tags Events and Featured:', 'kk-content-exporter' ) => '{ "featured": true }',
							__( 'An item with the tag Events only:', 'kk-content-exporter' ) => '{ "featured": false }',
						),
					),
				);

			case 'output':
				return array(
					array( 'p', __( 'The output type decides how the value is written in the JSON. A value that cannot be converted gives <code>null</code>.', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( '<strong>Automatic</strong>: the type of the field, for example a date stays a date and a list stays a list.', 'kk-content-exporter' ),
							__( '<strong>Text</strong>: plain text, without HTML tags.', 'kk-content-exporter' ),
							__( '<strong>HTML</strong>: the text with its HTML, for example the content of a post.', 'kk-content-exporter' ),
							__( '<strong>Number</strong>: a JSON number.', 'kk-content-exporter' ),
							__( '<strong>True or false</strong>: <code>true</code> or <code>false</code>.', 'kk-content-exporter' ),
							__( '<strong>Date and time</strong>: ISO 8601 in UTC, for example <code>2026-11-25T10:00:00Z</code>; a date without a time stays <code>2026-11-25</code>.', 'kk-content-exporter' ),
							__( '<strong>URL</strong>: an absolute http or https address; an address that starts with <code>/</code> gets the address of the site.', 'kk-content-exporter' ),
							__( '<strong>List</strong>: a JSON list, for example the names of the categories.', 'kk-content-exporter' ),
						),
					),
					array( 'h3', __( 'Maximum length', 'kk-content-exporter' ) ),
					array( 'p', __( 'For a plain text, the advanced options of the row can set a maximum length: a longer text is cut at a whole word and ends with "…".', 'kk-content-exporter' ) ),
				);

			case 'preview':
				return array(
					array( 'p', __( 'As soon as the mapping has a row, the preview shows the JSON of one published item for each checked post type, computed with the rules of the box, saved or not, as a visitor of the site would get it.', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( 'It is computed again shortly after every change; <strong>Refresh</strong> computes it at once.', 'kk-content-exporter' ),
							__( 'It uses the latest modified item; you can choose another one among the latest published items.', 'kk-content-exporter' ),
							__( '<strong>Expand</strong> shows the whole JSON; <strong>Copy JSON</strong> copies it. Without HTTPS the browser does not allow copying: the JSON is selected and you copy it with Ctrl+C.', 'kk-content-exporter' ),
							__( 'When the mapping has errors, the preview lists them instead of the JSON.', 'kk-content-exporter' ),
							__( 'It warns when the author archive URL in an item shows the login name of the author.', 'kk-content-exporter' ),
						),
					),
				);

			default:
				return array(
					array( 'p', __( 'A feed is a read-only JSON address that an external program, for example an importer on another site, reads to copy your published content. The <strong>Feed settings</strong> box chooses its address, the post types it reads and who can read it; the <strong>Mapping</strong> box chooses what every item contains.', 'kk-content-exporter' ) ),
					array( 'p', __( 'Every row of the mapping writes one key in every item:', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( '<strong>Every item has all the keys</strong>, in the order of the rows, so the program that reads the feed always finds the same keys.', 'kk-content-exporter' ),
							__( 'A key without a value is written as <code>null</code>; an empty text stays <code>""</code> and an empty list <code>[]</code>.', 'kk-content-exporter' ),
							__( 'The <strong>JSON key</strong> is the name of the key, for example <code>title</code>. It is proposed from the field you choose, and you can change it.', 'kk-content-exporter' ),
							__( 'The <strong>rule</strong> decides how the value is computed: Field, Fixed value, Template, Lookup table or Condition. Each one has a tab in this guide.', 'kk-content-exporter' ),
							__( 'The <strong>output type</strong> decides how the value is written.', 'kk-content-exporter' ),
						),
					),
					array( 'p', __( 'Only published content without a password is exported, and only the fields you map. A field whose name looks sensitive (a password, a key, an e-mail address) is exported only after you confirm it in its row.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Multilingual sites', 'kk-content-exporter' ), 'multilingual' ),
					array( 'p', __( 'With Polylang, a feed returns the items of every language. When the program that reads the feed must tell them apart, choose one of two ways:', 'kk-content-exporter' ) ),
					array(
						'list',
						array(
							__( 'Add a row with a language field, for example <strong>Language code</strong>: every item says its language. Add <strong>Translation group</strong> too: the translations of the same content share the same group.', 'kk-content-exporter' ),
							__( 'Or let the program read one language at a time with the <code>lang</code> parameter, for example <code>?lang=en</code>.', 'kk-content-exporter' ),
						),
					),
					array( 'p', __( 'The Mapping box warns when Polylang translates the content of the feed and no row exports the language of the items.', 'kk-content-exporter' ) ),
					array( 'h3', __( 'Example', 'kk-content-exporter' ) ),
					array(
						'example',
						array(
							__( 'Rows', 'kk-content-exporter' ) => '<code>title</code> ← ' . __( 'Title', 'kk-content-exporter' ) . ', <code>link</code> ← ' . __( 'Permalink', 'kk-content-exporter' ) . ', <code>image</code> ← ' . __( 'Featured image (URL)', 'kk-content-exporter' ),
						),
						array(
							__( 'An item without a featured image:', 'kk-content-exporter' ) => "{\n  \"title\": \"Summer concert\",\n  \"link\": \"https://example.com/summer-concert/\",\n  \"image\": null\n}",
						),
					),
				);
		}
	}
}

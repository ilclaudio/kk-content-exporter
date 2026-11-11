<?php
/**
 * Integration tests of the export of one item with its mapping.
 *
 * @package KKCE
 */

use KKCE\Output\Item_Exporter;

/**
 * An item is exported with the values a visitor would see, and the current user is always restored.
 */
class KKCE_Item_Exporter_Integration_Test extends WP_UnitTestCase {

	/**
	 * Blocks and shortcodes see the exported post, even with a different or absent global post.
	 */
	public function test_rendered_values_use_the_target_post_context() {
		$target = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<!-- wp:kkce-test/context /-->[kkce_context]' ) ) );
		$other  = get_post( self::factory()->post->create() );
		$names = array( 'post', 'id', 'authordata', 'currentday', 'currentmonth', 'page', 'pages', 'multipage', 'more', 'numpages' );
		$saved = array_intersect_key( $GLOBALS, array_flip( $names ) );
		register_block_type( 'kkce-test/context', array(
			'uses_context' => array( 'postId', 'postType' ),
			'render_callback' => static fn( $attributes, $content, $block ) => 'block:' . ( $block->context['postId'] ?? 'missing' ) . ':' . ( $block->context['postType'] ?? 'missing' ) . ';',
		) );
		add_shortcode( 'kkce_context', static fn() => 'shortcode:' . get_the_ID() . ':' . $GLOBALS['id'] );
		$mapping = $this->mapping( array( array(
			'key' => 'body', 'kind' => 'field', 'type' => 'html',
			'sources' => array( '*' => array( 'core.content' ) ), 'options' => array( 'shortcodes' => true ),
		) ) );
		try {
			foreach ( array( 'other', 'absent', 'null' ) as $mode ) {
				$GLOBALS['post'] = $other;
				setup_postdata( $other );
				if ( 'absent' === $mode ) {
					unset( $GLOBALS['post'] );
				} elseif ( 'null' === $mode ) {
					$GLOBALS['post'] = null;
				}
				$before = array_intersect_key( $GLOBALS, array_flip( $names ) );
				$item = Item_Exporter::export( $target, $mapping );
				$this->assertStringContainsString( 'block:' . $target->ID . ':post;', $item['body'] );
				$this->assertStringContainsString( 'shortcode:' . $target->ID . ':' . $target->ID, $item['body'] );
				$after = array_intersect_key( $GLOBALS, array_flip( $names ) );
				ksort( $before );
				ksort( $after );
				$this->assertSame( $before, $after );
			}
		} finally {
			unregister_block_type( 'kkce-test/context' );
			remove_shortcode( 'kkce_context' );
			foreach ( $names as $name ) {
				unset( $GLOBALS[ $name ] );
				if ( array_key_exists( $name, $saved ) ) {
					$GLOBALS[ $name ] = $saved[ $name ];
				}
			}
		}
	}

	/**
	 * The post and loop globals are restored when a rendered shortcode throws.
	 */
	public function test_post_context_is_restored_after_rendering_error() {
		$target = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '[kkce_failure]' ) ) );
		$before = $GLOBALS['post'] ?? null;
		$previous_id = $GLOBALS['id'] ?? null;
		add_shortcode( 'kkce_failure', static function () {
			$GLOBALS['id'] = -1;
			throw new RuntimeException( 'Context failure.' );
		} );
		try {
			Item_Exporter::export( $target, $this->mapping( array( array(
				'key' => 'body', 'kind' => 'field', 'type' => 'html',
				'sources' => array( '*' => array( 'core.content' ) ), 'options' => array( 'shortcodes' => true ),
			) ) ) );
			$this->fail( 'The exception must propagate.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Context failure.', $error->getMessage() );
		} finally {
			remove_shortcode( 'kkce_failure' );
		}
		$this->assertSame( $before, $GLOBALS['post'] ?? null );
		$this->assertSame( $previous_id, $GLOBALS['id'] ?? null );
	}

	/**
	 * An accepted fixed date with a null byte gives null without aborting the other rows.
	 */
	public function test_invalid_fixed_date_gives_null() {
		$post    = get_post( self::factory()->post->create() );
		$mapping = $this->mapping( array( array( 'key' => 'when', 'kind' => 'fixed', 'type' => 'datetime', 'values' => array( '*' => "2026-07\0-01" ) ) ) );
		$this->assertSame( array(), KKCE\Output\Mapping_Validator::validate( $mapping ) );
		$item = Item_Exporter::export( $post, $mapping );
		$this->assertArrayHasKey( 'when', $item );
		$this->assertNull( $item['when'] );
		$this->assertSame( $post->ID, $item['id'] );
	}

	/**
	 * The mapping is a contract: every item has every key of the mapping, in the order of the rows, also
	 * when a field is absent, empty or cannot be converted.
	 */
	public function test_every_item_has_every_key_of_the_mapping() {
		$with    = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_excerpt' => 'Short', 'post_title' => 'With' ) ) );
		$without = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_excerpt' => '', 'post_title' => '', 'post_author' => 0 ) ) );
		$mapping = $this->mapping(
			array(
				array( 'key' => 'excerpt', 'kind' => 'field', 'type' => 'html', 'sources' => array( '*' => array( 'core.excerpt' ) ) ),
				array( 'key' => 'author', 'kind' => 'field', 'type' => 'auto', 'sources' => array( '*' => array( 'core.author.name' ) ) ),
				array( 'key' => 'price', 'kind' => 'field', 'type' => 'number', 'sources' => array( '*' => array( 'meta.price' ) ) ),
				array( 'key' => 'only_pages', 'kind' => 'fixed', 'type' => 'auto', 'values' => array( 'page' => 'p' ) ),
			)
		);

		$first  = Item_Exporter::export( $with, $mapping );
		$second = Item_Exporter::export( $without, $mapping );

		$keys = array( 'id', 'summary', 'source', 'excerpt', 'author', 'price', 'only_pages' );
		$this->assertSame( $keys, array_keys( $first ) );
		$this->assertSame( $keys, array_keys( $second ) );
		$this->assertSame( 'Short', $first['excerpt'] );
		$this->assertSame( '', $second['excerpt'], 'An empty excerpt is there, but empty.' );
		$this->assertNull( $second['author'], 'No author gives null.' );
		$this->assertNull( $first['price'], 'A source without a value gives null.' );
		$this->assertNull( $first['only_pages'], 'A fixed value of another post type gives null.' );
	}

	/**
	 * Mapping used by the tests: title, a chain per post type, a fixed value, the author archive URL.
	 *
	 * @param array<int, array<string, mixed>> $extra Rows to add.
	 * @return array{version: int, rows: list<array<string, mixed>>}
	 */
	private function mapping( array $extra = array() ): array {
		return array(
			'version' => 1,
			'rows'    => array_values(
				array_merge(
					array(
						array(
							'key'     => 'id',
							'kind'    => 'field',
							'type'    => 'auto',
							'sources' => array( '*' => array( 'core.id' ) ),
						),
						array(
							'key'     => 'summary',
							'kind'    => 'field',
							'type'    => 'auto',
							'sources' => array(
								'*'    => array( 'core.excerpt', 'core.title' ),
								'page' => array( 'core.title' ),
							),
						),
						array(
							'key'     => 'source',
							'kind'    => 'fixed',
							'type'    => 'auto',
							'values'  => array( '*' => 'site-a' ),
						),
					),
					$extra
				)
			),
		);
	}

	/**
	 * A post and a page are exported with their own chains.
	 */
	public function test_items_are_exported_with_the_chains_of_their_post_type() {
		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_title'   => 'Post title',
					'post_excerpt' => '',
				)
			)
		);
		$page = get_post(
			self::factory()->post->create(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Page title',
					'post_excerpt' => 'Ignored for pages',
				)
			)
		);

		$this->assertSame(
			array(
				'id'      => $post->ID,
				'summary' => 'Post title',
				'source'  => 'site-a',
			),
			Item_Exporter::export( $post, $this->mapping() )
		);
		$this->assertSame(
			array(
				'id'      => $page->ID,
				'summary' => 'Page title',
				'source'  => 'site-a',
			),
			Item_Exporter::export( $page, $this->mapping() )
		);
	}

	/**
	 * Values are computed as an anonymous visitor: a filter that depends on the current user gives what a
	 * visitor gets, also when an administrator exports; the user is restored afterwards.
	 */
	public function test_values_are_computed_as_an_anonymous_visitor() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$mark  = static fn( $url ) => $url . ( is_user_logged_in() ? '#logged-in' : '#visitor' );
		add_filter( 'post_link', $mark );

		$mapping = $this->mapping(
			array(
				array(
					'key'     => 'link',
					'kind'    => 'field',
					'type'    => 'auto',
					'sources' => array( '*' => array( 'core.permalink' ) ),
				),
			)
		);

		wp_set_current_user( $admin );
		$as_admin = Item_Exporter::export( $post, $mapping );
		$this->assertSame( $admin, get_current_user_id(), 'The administrator is restored.' );

		wp_set_current_user( 0 );
		$as_visitor = Item_Exporter::export( $post, $mapping );

		remove_filter( 'post_link', $mark );

		$this->assertStringEndsWith( '#visitor', $as_admin['link'] );
		$this->assertSame( $as_visitor, $as_admin );
	}

	/**
	 * The current user is restored even when the computation fails.
	 */
	public function test_the_user_is_restored_after_an_error() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post  = get_post( self::factory()->post->create( array( 'post_status' => 'publish' ) ) );
		$fail  = static function () {
			throw new RuntimeException( 'Failure inside a filter.' );
		};
		add_filter( 'post_link', $fail );
		wp_set_current_user( $admin );

		$mapping = $this->mapping(
			array(
				array(
					'key'     => 'link',
					'kind'    => 'field',
					'type'    => 'auto',
					'sources' => array( '*' => array( 'core.permalink' ) ),
				),
			)
		);

		try {
			Item_Exporter::export( $post, $mapping );
			$this->fail( 'The exception is not swallowed.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'Failure inside a filter.', $e->getMessage() );
		} finally {
			remove_filter( 'post_link', $fail );
		}

		$this->assertSame( $admin, get_current_user_id() );
	}

	/**
	 * The author archive URL comes out only when the row confirms it; a mapping that was not validated
	 * cannot skip the confirmation.
	 */
	public function test_the_author_url_needs_its_confirmation_at_output() {
		$author = self::factory()->user->create( array( 'role' => 'author', 'user_nicename' => 'jane' ) );
		$post   = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_author' => $author ) ) );
		$row    = array(
			'key'     => 'author_page',
			'kind'    => 'field',
			'type'    => 'auto',
			'sources' => array( '*' => array( 'core.author.url' ) ),
		);

		$unconfirmed = Item_Exporter::export( $post, $this->mapping( array( $row ) ) );
		$this->assertArrayHasKey( 'author_page', $unconfirmed, 'The key of the contract is still written.' );
		$this->assertNull( $unconfirmed['author_page'] );

		$row['confirmed'] = array( 'core.author.url' );
		$this->assertSame( get_author_posts_url( $author ), Item_Exporter::export( $post, $this->mapping( array( $row ) ) )['author_page'] );
	}

	/**
	 * Sources of providers that do not exist yet, and unknown core fields, do not exist: the chain goes on.
	 */
	public function test_unknown_sources_do_not_exist() {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_title' => 'T' ) ) );
		$row  = array(
			'key'     => 'name',
			'kind'    => 'field',
			'type'    => 'auto',
			'sources' => array( '*' => array( 'meta.name', 'core.nothing', 'core.title' ) ),
		);

		$this->assertSame( 'T', Item_Exporter::export( $post, $this->mapping( array( $row ) ) )['name'] );
	}

	/**
	 * Values come out normalized to the declared types of the core sources: the ID as a number, the dates
	 * in UTC ISO 8601, the title as plain text even when WordPress stored entities.
	 */
	public function test_values_are_normalized() {
		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_title'    => 'Concert & <em>more</em>',
					'post_date'     => '2026-07-01 10:30:00',
					'post_date_gmt' => '2026-07-01 08:30:00',
				)
			)
		);
		$row  = static fn( string $key, string $source, string $type = 'auto' ): array => array(
			'key'     => $key,
			'kind'    => 'field',
			'type'    => $type,
			'sources' => array( '*' => array( $source ) ),
		);

		$item = Item_Exporter::export(
			$post,
			array(
				'version' => 1,
				'rows'    => array(
					$row( 'title', 'core.title' ),
					$row( 'title_html', 'core.title', 'html' ),
					$row( 'date', 'core.date' ),
					$row( 'post_id', 'core.id' ),
					$row( 'link', 'core.permalink' ),
				),
			)
		);

		$this->assertSame( 'Concert & more', $item['title'] );
		$this->assertSame( $post->post_title, $item['title_html'] );
		$this->assertSame( '2026-07-01T08:30:00Z', $item['date'] );
		$this->assertSame( $post->ID, $item['post_id'] );
		$this->assertSame( get_permalink( $post ), $item['link'] );
	}

	/**
	 * A template joins the values of a real post: dates in ISO 8601, a literal fallback for an empty excerpt,
	 * plain text by default and HTML on request.
	 */
	public function test_templates_on_a_real_post() {
		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status'   => 'publish',
					'post_title'    => 'Concert & more',
					'post_excerpt'  => '',
					'post_date'     => '2026-07-01 10:30:00',
					'post_date_gmt' => '2026-07-01 08:30:00',
				)
			)
		);
		$mapping = $this->mapping(
			array(
				array( 'key' => 'subtitle', 'kind' => 'template', 'type' => 'string', 'templates' => array( '*' => '{core.title} ({core.date}) – {core.excerpt|"no excerpt"}' ) ),
				array( 'key' => 'link', 'kind' => 'template', 'type' => 'html', 'templates' => array( '*' => '<a href="{core.permalink}">{core.title}</a>' ) ),
				array( 'key' => 'nothing', 'kind' => 'template', 'type' => 'string', 'templates' => array( '*' => 'Place: {meta.place}' ) ),
			)
		);

		$item = Item_Exporter::export( $post, $mapping );

		$this->assertSame( 'Concert & more (2026-07-01T08:30:00Z) – no excerpt', $item['subtitle'] );
		$this->assertSame( '<a href="' . get_permalink( $post ) . '">Concert &amp; more</a>', $item['link'] );
		$this->assertArrayHasKey( 'nothing', $item );
		$this->assertNull( $item['nothing'], 'No placeholder with a value gives null.' );
	}
}

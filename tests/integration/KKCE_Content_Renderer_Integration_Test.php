<?php
/**
 * Integration tests of the controlled rendering of content and excerpt, and of the absolute URLs.
 *
 * @package KKCE
 */

use KKCE\Output\Content_Renderer;
use KKCE\Output\Item_Exporter;
use KKCE\Output\Url_Absolutizer;
use KKCE\Sources\Core_Provider;

/**
 * Blocks rendered without comments, shortcodes only on request, raw text untouched, no external request.
 */
class KKCE_Content_Renderer_Integration_Test extends WP_UnitTestCase {

	/**
	 * Permalink used as base of the relative URLs.
	 */
	private const BASE = 'https://example.org/2026/07/concert/';

	/**
	 * Registers a test shortcode and a dynamic block that depends on the current user.
	 */
	public function set_up() {
		parent::set_up();

		add_shortcode( 'kkce_test', static fn() => 'SHORTCODE-OUTPUT' );
		if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'kkce-test/greeting' ) ) {
			register_block_type(
				'kkce-test/greeting',
				array( 'render_callback' => static fn() => is_user_logged_in() ? '<p>For members</p>' : '<p>For visitors</p>' )
			);
		}
	}

	/**
	 * Removes the test shortcode and block.
	 */
	public function tear_down() {
		remove_shortcode( 'kkce_test' );
		unregister_block_type( 'kkce-test/greeting' );

		parent::tear_down();
	}

	/**
	 * Blocks become HTML without the block comments, and no paragraph is added to block content.
	 */
	public function test_blocks_are_rendered_without_comments() {
		$html = Content_Renderer::render( "<!-- wp:paragraph -->\n<p>Hello</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>World</p>\n<!-- /wp:paragraph -->", array(), false, self::BASE );

		$this->assertStringNotContainsString( '<!--', $html );
		// WordPress may add attributes to the rendered paragraphs (a class since 7.x): only the element counts.
		$this->assertMatchesRegularExpression( '#<p[^>]*>Hello</p>#', $html );
		$this->assertMatchesRegularExpression( '#<p[^>]*>World</p>#', $html );
		$this->assertStringNotContainsString( '<p><p>', $html );
	}

	/**
	 * Content of the classic editor gets its paragraphs, as WordPress does.
	 */
	public function test_classic_content_gets_paragraphs() {
		$html = Content_Renderer::render( "First line\n\nSecond line", array(), false, self::BASE );

		$this->assertStringContainsString( '<p>First line</p>', $html );
		$this->assertStringContainsString( '<p>Second line</p>', $html );
	}

	/**
	 * Without the option, registered shortcodes are removed and unknown ones stay as text; with the option
	 * they run.
	 */
	public function test_shortcodes_run_only_with_the_option() {
		$text = '<!-- wp:paragraph --><p>A [kkce_test] B [not_registered]</p><!-- /wp:paragraph -->';

		$off = Content_Renderer::render( $text, array(), false, self::BASE );
		$this->assertStringNotContainsString( '[kkce_test]', $off );
		$this->assertStringNotContainsString( 'SHORTCODE-OUTPUT', $off );
		$this->assertStringContainsString( '[not_registered]', $off );

		$on = Content_Renderer::render( $text, array( 'shortcodes' => true ), false, self::BASE );
		$this->assertStringContainsString( 'SHORTCODE-OUTPUT', $on );

		$this->assertStringNotContainsString( 'SHORTCODE-OUTPUT', Content_Renderer::render( $text, array( 'shortcodes' => 'yes' ), false, self::BASE ), 'Only true turns the option on.' );
	}

	/**
	 * The raw variant is the stored text, untouched: comments, shortcodes and relative URLs included.
	 */
	public function test_the_raw_variant_is_the_stored_text() {
		$text = '<!-- wp:paragraph --><p>[kkce_test] <a href="/x">x</a></p><!-- /wp:paragraph -->';

		$this->assertSame( $text, Content_Renderer::render( $text, array( 'raw' => true, 'shortcodes' => true ), false, self::BASE ) );
	}

	/**
	 * The excerpt gets no paragraphs; shortcodes follow the option.
	 */
	public function test_the_excerpt_keeps_its_text() {
		$this->assertSame( "Short\n\nsummary ", Content_Renderer::render( "Short\n\nsummary [kkce_test]", array(), true, self::BASE ) );
		$this->assertSame( 'Short summary SHORTCODE-OUTPUT', Content_Renderer::render( 'Short summary [kkce_test]', array( 'shortcodes' => true ), true, self::BASE ) );
	}

	/**
	 * Relative URLs in href, src and srcset become absolute; absolute, protocol-relative, anchors and
	 * other schemes stay as they are.
	 */
	public function test_relative_urls_become_absolute() {
		$html = Url_Absolutizer::absolutize(
			'<a href="/about/">a</a><a href="photos/b.html">b</a><img src="/wp-content/c.jpg" srcset="/wp-content/c-300.jpg 300w, d-600.jpg 600w">'
			. '<a href="https://other.test/x">x</a><img src="//cdn.test/e.jpg"><a href="#top">t</a><a href="mailto:a@example.org">m</a><a href="tel:+39123">p</a><img src="data:image/gif;base64,R0lGOD">',
			self::BASE
		);

		$this->assertStringContainsString( 'href="https://example.org/about/"', $html );
		$this->assertStringContainsString( 'href="https://example.org/2026/07/concert/photos/b.html"', $html );
		$this->assertStringContainsString( 'src="https://example.org/wp-content/c.jpg"', $html );
		$this->assertStringContainsString( 'srcset="https://example.org/wp-content/c-300.jpg 300w, https://example.org/2026/07/concert/d-600.jpg 600w"', $html );
		$this->assertStringContainsString( 'href="https://other.test/x"', $html );
		$this->assertStringContainsString( 'src="//cdn.test/e.jpg"', $html );
		$this->assertStringContainsString( 'href="#top"', $html );
		$this->assertStringContainsString( 'href="mailto:a@example.org"', $html );
		$this->assertStringContainsString( 'href="tel:+39123"', $html );
		$this->assertStringContainsString( 'src="data:image/gif;base64,R0lGOD"', $html );
	}

	/**
	 * The rendered content has absolute URLs.
	 */
	public function test_rendered_content_has_absolute_urls() {
		$html = Content_Renderer::render( '<!-- wp:paragraph --><p><a href="/about/">About</a></p><!-- /wp:paragraph -->', array(), false, self::BASE );

		$this->assertStringContainsString( 'href="https://example.org/about/"', $html );
	}

	/**
	 * Commas inside URLs are not candidate separators; relative candidates still become absolute.
	 */
	public function test_srcset_preserves_commas_inside_urls() {
		foreach ( array(
			'data:image/png;base64,AAAA 1x, /large.png 2x' => 'data:image/png;base64,AAAA 1x, https://example.org/large.png 2x',
			'data:image/png;base64,AAAA, /large.png 2x' => 'data:image/png;base64,AAAA, https://example.org/large.png 2x',
			'/image,a.png 1x, https://cdn.test/b,c.png 2x' => 'https://example.org/image,a.png 1x, https://cdn.test/b,c.png 2x',
			'/small.png, /large.png 2x' => 'https://example.org/small.png, https://example.org/large.png 2x',
		) as $input => $expected ) {
			$html = Url_Absolutizer::absolutize( '<img srcset="' . $input . '">', self::BASE );
			$this->assertStringContainsString( 'srcset="' . $expected . '"', $html );
		}
	}

	/**
	 * HTTP is blocked for RSS blocks and opted-in shortcodes, and filters are restored afterwards.
	 */
	public function test_rendering_blocks_http_and_restores_filters() {
		$seen = array();
		$observe = static function ( $pre ) use ( &$seen ) {
			$seen[] = $pre instanceof WP_Error ? $pre->get_error_code() : 'not_blocked';
			return new WP_Error( 'test_transport_guard' );
		};
		$install_observer = static function ( $args ) use ( $observe ) {
			add_filter( 'pre_http_request', $observe, PHP_INT_MAX );
			return $args;
		};
		$before = isset( $GLOBALS['wp_filter']['pre_http_request'] ) ? clone $GLOBALS['wp_filter']['pre_http_request'] : null;
		add_filter( 'http_request_args', $install_observer );
		add_shortcode( 'kkce_http', static function () {
			wp_remote_get( 'https://example.org/shortcode-test' );
			return 'done';
		} );
		try {
			Content_Renderer::render( '<!-- wp:rss {"feedURL":"https://example.org/kkce-uncached-feed"} /-->', array(), false, self::BASE );
			remove_filter( 'pre_http_request', $observe, PHP_INT_MAX );
			Content_Renderer::render( '[kkce_http]', array( 'shortcodes' => true ), false, self::BASE );
		} finally {
			remove_filter( 'pre_http_request', $observe, PHP_INT_MAX );
			remove_filter( 'http_request_args', $install_observer );
			remove_shortcode( 'kkce_http' );
		}
		$this->assertSame( array( 'kkce_http_blocked', 'kkce_http_blocked' ), $seen );
		$this->assertEquals( $before, $GLOBALS['wp_filter']['pre_http_request'] ?? null );
	}

	/**
	 * Nested renders and exceptions must not leak or prematurely remove an HTTP guard.
	 */
	public function test_http_guard_survives_nested_render_and_is_removed_after_error() {
		$before = isset( $GLOBALS['wp_filter']['pre_http_request'] ) ? clone $GLOBALS['wp_filter']['pre_http_request'] : null;
		$guard = static fn() => new WP_Error( 'test_transport_guard' );
		add_filter( 'pre_http_request', $guard );
		add_shortcode( 'kkce_nested', function () {
			Content_Renderer::render( 'Nested', array(), false, self::BASE );
			$result = wp_remote_get( 'https://example.org/nested' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'kkce_http_blocked', $result->get_error_code() );
			throw new RuntimeException( 'Rendering failed.' );
		} );
		try {
			Content_Renderer::render( '[kkce_nested]', array( 'shortcodes' => true ), false, self::BASE );
			$this->fail( 'The exception must propagate.' );
		} catch ( RuntimeException $error ) {
			$this->assertSame( 'Rendering failed.', $error->getMessage() );
		} finally {
			remove_shortcode( 'kkce_nested' );
			remove_filter( 'pre_http_request', $guard );
		}
		$this->assertEquals( $before, $GLOBALS['wp_filter']['pre_http_request'] ?? null );
	}

	/**
	 * No external request is made: embeds are not resolved, also with the shortcodes on.
	 */
	public function test_no_external_request_is_made() {
		$requests = 0;
		$count    = static function ( $pre ) use ( &$requests ) {
			++$requests;
			return new WP_Error( 'kkce_test_blocked', 'Blocked by the test.' );
		};
		add_filter( 'pre_http_request', $count );

		$text = "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=abc\",\"type\":\"video\",\"providerNameSlug\":\"youtube\"} -->\n"
			. "<figure class=\"wp-block-embed\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=abc\n</div></figure>\n<!-- /wp:embed -->\n"
			. "<!-- wp:paragraph --><p>[embed]https://vimeo.com/123[/embed]</p><!-- /wp:paragraph -->";

		$html = Content_Renderer::render( $text, array( 'shortcodes' => true ), false, self::BASE );
		remove_filter( 'pre_http_request', $count );

		$this->assertSame( 0, $requests );
		$this->assertStringContainsString( 'https://www.youtube.com/watch?v=abc', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	/**
	 * The core content source gives the rendered HTML and follows the options of the row.
	 */
	public function test_the_content_source_is_rendered() {
		$post = get_post(
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:paragraph --><p>Body [kkce_test]</p><!-- /wp:paragraph -->',
					'post_excerpt' => 'Summary [kkce_test]',
				)
			)
		);

		$this->assertStringNotContainsString( '<!--', (string) Core_Provider::value( $post, 'core.content' ) );
		$this->assertStringNotContainsString( 'SHORTCODE-OUTPUT', (string) Core_Provider::value( $post, 'core.content' ) );
		$this->assertStringContainsString( 'SHORTCODE-OUTPUT', (string) Core_Provider::value( $post, 'core.content', array( 'shortcodes' => true ) ) );
		$this->assertSame( $post->post_content, Core_Provider::value( $post, 'core.content', array( 'raw' => true ) ) );
		$this->assertSame( 'Summary ', Core_Provider::value( $post, 'core.excerpt' ) );
		$this->assertSame( 'Summary SHORTCODE-OUTPUT', Core_Provider::value( $post, 'core.excerpt', array( 'shortcodes' => true ) ) );
	}

	/**
	 * A dynamic block that depends on the user gives what a visitor sees, also when an administrator
	 * exports (test moved here from step 5, now that blocks are rendered).
	 */
	public function test_a_block_that_depends_on_the_user_is_rendered_for_a_visitor() {
		$post    = get_post(
			self::factory()->post->create(
				array(
					'post_status'  => 'publish',
					'post_content' => '<!-- wp:kkce-test/greeting /-->',
				)
			)
		);
		$mapping = array(
			'version' => 1,
			'rows'    => array(
				array(
					'key'     => 'body',
					'kind'    => 'field',
					'type'    => 'auto',
					'missing' => 'omit',
					'sources' => array( '*' => array( 'core.content' ) ),
				),
			),
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$as_admin = Item_Exporter::export( $post, $mapping );
		wp_set_current_user( 0 );
		$as_visitor = Item_Exporter::export( $post, $mapping );

		$this->assertStringContainsString( 'For visitors', $as_admin['body'] );
		$this->assertSame( $as_visitor, $as_admin );
	}

	/**
	 * The options of the row reach the provider through the exporter.
	 */
	public function test_the_options_of_the_row_reach_the_provider() {
		$post = get_post( self::factory()->post->create( array( 'post_status' => 'publish', 'post_content' => '<p>[kkce_test]</p>' ) ) );
		$row  = static fn( string $key, array $options ): array => array(
			'key'     => $key,
			'kind'    => 'field',
			'type'    => 'html',
			'missing' => 'omit',
			'sources' => array( '*' => array( 'core.content' ) ),
			'options' => $options,
		);

		$item = Item_Exporter::export(
			$post,
			array(
				'version' => 1,
				'rows'    => array( $row( 'plain', array() ), $row( 'with_shortcodes', array( 'shortcodes' => true ) ), $row( 'raw', array( 'raw' => true ) ) ),
			)
		);

		$this->assertStringNotContainsString( 'SHORTCODE-OUTPUT', $item['plain'] );
		$this->assertStringContainsString( 'SHORTCODE-OUTPUT', $item['with_shortcodes'] );
		$this->assertSame( '<p>[kkce_test]</p>', $item['raw'] );
	}
}

<?php
/**
 * Controlled rendering of the content and of the excerpt.
 *
 * @package KKCE
 */

namespace KKCE\Output;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the stored content of a post into clean HTML for the feed, without running the filters of every
 * other plugin.
 *
 * The full `the_content` filter is never applied: in a public request it would run the code of every
 * plugin hooked there. Instead (decisions of 2026-11-08 and 2026-11-10):
 * - blocks become HTML with `do_blocks()`, without the block comments; content without blocks (classic
 *   editor) gets its paragraphs with `wpautop()`, as WordPress does; the excerpt gets no paragraphs;
 * - shortcodes run only with the option `shortcodes`; without it the registered ones are removed and
 *   unknown ones stay as text;
 * - no embed is resolved: oEmbed would ask the HTML to the site of the video, an external request;
 * - WordPress HTTP API requests are blocked throughout rendering, including dynamic blocks and shortcodes;
 * - relative URLs become absolute (`Url_Absolutizer`);
 * - with the option `raw` the stored text is returned untouched.
 * Typographic quotes (`wptexturize()`) and the attributes added for the page (`wp_filter_content_tags()`)
 * are left out on purpose. Callers compute the values as an anonymous visitor (`Item_Exporter`).
 */
final class Content_Renderer {

	/**
	 * Rendered HTML of a content or of an excerpt.
	 *
	 * @param string               $text       Stored text.
	 * @param array<string, mixed> $options    Options of the mapping row: `shortcodes` and `raw` (only `true` turns them on).
	 * @param bool                 $is_excerpt Whether the text is an excerpt (no paragraphs added).
	 * @param string               $base       Permalink of the content, base of the relative URLs.
	 * @return string
	 */
	public static function render( string $text, array $options, bool $is_excerpt, string $base ): string {
		if ( true === ( $options['raw'] ?? false ) || '' === $text ) {
			return $text;
		}

		// A unique callback for each render keeps an outer guard in place during nested rendering.
		$block_http = static fn(): WP_Error => new WP_Error( 'kkce_http_blocked' );
		add_filter( 'pre_http_request', $block_http, PHP_INT_MAX );

		try {
			if ( has_blocks( $text ) ) {
				$html = do_blocks( $text );
			} elseif ( $is_excerpt ) {
				$html = $text;
			} else {
				$html = shortcode_unautop( wpautop( $text ) );
			}

			$html = true === ( $options['shortcodes'] ?? false ) ? do_shortcode( $html ) : strip_shortcodes( $html );

			return Url_Absolutizer::absolutize( $html, $base );
		} finally {
			remove_filter( 'pre_http_request', $block_http, PHP_INT_MAX );
		}
	}
}

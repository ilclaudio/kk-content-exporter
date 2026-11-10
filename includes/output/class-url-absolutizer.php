<?php
/**
 * Absolute URLs inside exported HTML.
 *
 * @package KKCE
 */

namespace KKCE\Output;

use WP_HTML_Tag_Processor;
use WP_Http;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the relative URLs of an HTML fragment absolute, so that links and images still work on the site
 * that imports the content.
 *
 * Reads and writes the attributes `href`, `src` and `srcset` with `WP_HTML_Tag_Processor` (WordPress 6.2+),
 * never with regular expressions on the markup. A path that starts with `/` is resolved against the origin
 * of the base URL, any other relative path against the base URL itself (the permalink of the content), as
 * a browser would do. Absolute URLs, protocol-relative URLs (`//cdn…`), anchors (`#top`) and any scheme
 * (`mailto:`, `tel:`, `data:`) stay as they are.
 */
final class Url_Absolutizer {

	/**
	 * Attributes that hold one URL.
	 */
	private const URL_ATTRIBUTES = array( 'href', 'src' );

	/**
	 * HTML with absolute URLs.
	 *
	 * @param string $html HTML fragment.
	 * @param string $base Absolute URL the relative ones are resolved against (the permalink of the content).
	 * @return string
	 */
	public static function absolutize( string $html, string $base ): string {
		if ( '' === $html || '' === $base ) {
			return $html;
		}

		$processor = new WP_HTML_Tag_Processor( $html );

		while ( $processor->next_tag() ) {
			foreach ( self::URL_ATTRIBUTES as $attribute ) {
				$value = $processor->get_attribute( $attribute );
				if ( is_string( $value ) && self::is_relative( trim( $value ) ) ) {
					$processor->set_attribute( $attribute, WP_Http::make_absolute_url( trim( $value ), $base ) );
				}
			}

			$srcset = $processor->get_attribute( 'srcset' );
			if ( is_string( $srcset ) && '' !== trim( $srcset ) ) {
				$processor->set_attribute( 'srcset', self::absolutize_srcset( $srcset, $base ) );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * A `srcset` with absolute URLs: every candidate is "URL [descriptor]", separated by commas.
	 *
	 * @param string $srcset Value of the attribute.
	 * @param string $base   Base URL.
	 * @return string
	 */
	private static function absolutize_srcset( string $srcset, string $base ): string {
		$candidates = array();

		foreach ( explode( ',', $srcset ) as $candidate ) {
			$parts = preg_split( '/\s+/', trim( $candidate ), 2 );
			if ( ! is_array( $parts ) || '' === $parts[0] ) {
				continue;
			}

			$url          = self::is_relative( $parts[0] ) ? WP_Http::make_absolute_url( $parts[0], $base ) : $parts[0];
			$candidates[] = isset( $parts[1] ) ? $url . ' ' . $parts[1] : $url;
		}

		return implode( ', ', $candidates );
	}

	/**
	 * Whether a URL is relative to the site: not empty, not an anchor, not protocol-relative, no scheme.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function is_relative( string $url ): bool {
		if ( '' === $url || str_starts_with( $url, '#' ) || str_starts_with( $url, '//' ) ) {
			return false;
		}

		return 1 !== preg_match( '/\A[a-z][a-z0-9+.-]*:/i', $url );
	}
}

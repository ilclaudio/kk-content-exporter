<?php
/**
 * The tabs of the guide page.
 *
 * @package KKCE
 */

namespace KKCE\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: which tabs the guide has, in their order, and which one a request shows.
 *
 * No WordPress function is called: the page gives the requested value and prints the labels.
 */
final class Guide_Tabs {

	/**
	 * Tabs of the guide, in the order shown. The first one is shown when none, or an unknown one, is asked.
	 */
	public const TABS = array( 'overview', 'field', 'fixed', 'template', 'lookup', 'condition', 'output', 'preview' );

	/**
	 * The tab to show for a requested value.
	 *
	 * @param mixed $requested Value of the request (any type: it comes from the address).
	 * @return string One of `TABS`.
	 */
	public static function choose( mixed $requested ): string {
		if ( is_string( $requested ) && in_array( $requested, self::TABS, true ) ) {
			return $requested;
		}

		return self::TABS[0];
	}
}

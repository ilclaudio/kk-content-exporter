<?php
/**
 * Small helpers on arrays.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Checks on the shape of arrays.
 *
 * `is_list()` does what the native `array_is_list()` of PHP 8.1 does. The native function is always there
 * with PHP 8.3, but Plugin Check reports it as a function of WordPress 6.5 (the version that added its
 * polyfill), which is above the minimum of the plugin (6.4). Pure logic: no WordPress function is called.
 */
final class Arrays {

	/**
	 * Whether the keys of the array are 0, 1, 2… in this order; an empty array is a list.
	 *
	 * @template T
	 * @param array<T> $value Array to check.
	 * @return bool
	 * @phpstan-assert-if-true list<T> $value
	 */
	public static function is_list( array $value ): bool {
		return array_values( $value ) === $value;
	}
}

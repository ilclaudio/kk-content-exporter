<?php
/**
 * Evaluation of the rules of a mapping.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * The only place where the value of a mapping row is computed, shared by the preview and the endpoint.
 *
 * The behavior depends on the `kind` of the row:
 * - `field`: the chain of sources of the post type of the item (it replaces the general chain `*`);
 *   the first source with a value wins. `0`, `"0"` and `false` are values. A source that does not
 *   exist, or that is empty (empty string, only spaces, empty list), passes to the next one. When every
 *   existing source is empty, the first empty value comes out (`""` or `[]`); when none exists, the row
 *   has no value and its missing-value policy applies;
 * - `fixed`: the value of the post type of the item, or the general one `*`;
 * - any other kind (unknown, or of a later phase) has no value.
 *
 * A sensitive source that the row does not confirm is never read, also when the mapping did not go
 * through the validator (an imported or damaged one). Values come from a function given by the caller:
 * it receives the source and the options of the row (`shortcodes`, `raw`),
 * returns null when the source does not exist, and the providers translate their own special empty
 * values into `""` or `[]` (decision of 2026-11-10). `evaluate()` gives the raw value of a row;
 * `evaluate_mapping()` converts it to the type of the row with `Normalizer`. Pure logic: no WordPress
 * function is called.
 *
 * @phpstan-type RuleResult array{present: bool, value: mixed}
 */
final class Rule_Evaluator {

	/**
	 * Value of one row for an item.
	 *
	 * @param array<mixed> $row       Row of the mapping.
	 * @param string       $post_type Post type of the item.
	 * @param callable     $read      Reads a source of the item; null when it does not exist.
	 * @return array{present: bool, value: mixed} Whether the row has a value, and the value.
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	public static function evaluate( array $row, string $post_type, callable $read ): array {
		return match ( $row['kind'] ?? null ) {
			'field' => self::field( $row, $post_type, $read ),
			'fixed' => self::fixed( $row, $post_type ),
			default => self::absent(),
		};
	}

	/**
	 * Object exported for an item: the key of every row with a value, `null` for the rows without a value
	 * and the `null` policy; the rows without a value and the `omit` policy are left out. The order of the
	 * rows is kept.
	 *
	 * Every value is normalized to the type of its row (`Normalizer`). With `auto`, a `field` row takes the
	 * declared type shared by all its sources (every chain), otherwise text, so a key has the same JSON type
	 * for every item; a `fixed` row takes the JSON type of its values. A value that cannot be converted
	 * counts as missing.
	 *
	 * @param array<mixed>                     $mapping   Mapping (canonical form).
	 * @param string                           $post_type Post type of the item.
	 * @param callable                         $read      Reads a source of the item; null when it does not exist.
	 * @param (callable(string): ?string)|null $declared Declared type of a source, null when unknown (text).
	 * @param string                           $origin    Origin of the site, for root-relative URLs.
	 * @return array<string, mixed>
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	public static function evaluate_mapping( array $mapping, string $post_type, callable $read, ?callable $declared = null, string $origin = '' ): array {
		$item = array();

		foreach ( (array) ( $mapping['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) || ! is_string( $row['key'] ?? null ) ) {
				continue;
			}

			$result = self::evaluate( $row, $post_type, $read );
			if ( $result['present'] ) {
				$result = Normalizer::normalize( $result['value'], self::row_type( $row, $declared ), $origin );
			}

			if ( $result['present'] ) {
				$item[ $row['key'] ] = $result['value'];
			} elseif ( 'null' === ( $row['missing'] ?? 'omit' ) ) {
				$item[ $row['key'] ] = null;
			}
		}

		return $item;
	}

	/**
	 * Output type of a row, with `auto` resolved.
	 *
	 * @param array<mixed>                     $row      Row of the mapping.
	 * @param (callable(string): ?string)|null $declared Declared type of a source.
	 * @return string
	 */
	private static function row_type( array $row, ?callable $declared ): string {
		$type = is_string( $row['type'] ?? null ) ? $row['type'] : 'auto';
		if ( 'auto' !== $type ) {
			return $type;
		}

		$types = array();
		if ( 'fixed' === ( $row['kind'] ?? null ) ) {
			foreach ( (array) ( $row['values'] ?? array() ) as $value ) {
				$types[] = Normalizer::type_of_value( $value );
			}

			return Normalizer::common_type( $types );
		}

		foreach ( (array) ( $row['sources'] ?? array() ) as $chain ) {
			foreach ( (array) $chain as $source ) {
				if ( is_string( $source ) ) {
					$types[] = null === $declared ? null : $declared( $source );
				}
			}
		}

		return Normalizer::common_type( $types );
	}

	/**
	 * Value of a `field` row.
	 *
	 * @param array<mixed> $row       Row of the mapping.
	 * @param string       $post_type Post type of the item.
	 * @param callable     $read      Reads a source of the item.
	 * @return array{present: bool, value: mixed}
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	private static function field( array $row, string $post_type, callable $read ): array {
		$chain = self::for_post_type( $row['sources'] ?? null, $post_type );
		if ( ! is_array( $chain ) ) {
			return self::absent();
		}

		$confirmed = is_array( $row['confirmed'] ?? null ) ? $row['confirmed'] : array();
		$options   = is_array( $row['options'] ?? null ) ? $row['options'] : array();
		$empty     = null;

		foreach ( $chain as $source ) {
			if ( ! is_string( $source ) ) {
				continue;
			}

			if ( Sensitive_Keys::is_sensitive_source( $source ) && ! in_array( $source, $confirmed, true ) ) {
				continue;
			}

			$value = $read( $source, $options );
			if ( null === $value ) {
				continue;
			}

			if ( self::is_empty( $value ) ) {
				$empty ??= is_array( $value ) ? array() : '';
				continue;
			}

			return self::present( $value );
		}

		return null === $empty ? self::absent() : self::present( $empty );
	}

	/**
	 * Value of a `fixed` row.
	 *
	 * @param array<mixed> $row       Row of the mapping.
	 * @param string       $post_type Post type of the item.
	 * @return array{present: bool, value: mixed}
	 */
	private static function fixed( array $row, string $post_type ): array {
		$values = $row['values'] ?? null;

		if ( is_array( $values ) && array_key_exists( $post_type, $values ) ) {
			return self::present( $values[ $post_type ] );
		}

		if ( is_array( $values ) && array_key_exists( '*', $values ) ) {
			return self::present( $values['*'] );
		}

		return self::absent();
	}

	/**
	 * Entry of a map of post types for a post type: its own entry, otherwise the general one `*`.
	 *
	 * @param mixed  $map       Map of post types.
	 * @param string $post_type Post type of the item.
	 * @return mixed The entry, or null.
	 */
	private static function for_post_type( $map, string $post_type ) {
		if ( ! is_array( $map ) ) {
			return null;
		}

		return $map[ $post_type ] ?? $map['*'] ?? null;
	}

	/**
	 * Whether a value is empty: an empty string, a string of spaces, an empty list. `0`, `"0"` and `false`
	 * are values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_empty( $value ): bool {
		return array() === $value || ( is_string( $value ) && '' === trim( $value ) );
	}

	/**
	 * Result with a value.
	 *
	 * @param mixed $value Value.
	 * @return array{present: bool, value: mixed}
	 */
	private static function present( $value ): array {
		return array(
			'present' => true,
			'value'   => $value,
		);
	}

	/**
	 * Result without a value.
	 *
	 * @return array{present: bool, value: mixed}
	 */
	private static function absent(): array {
		return array(
			'present' => false,
			'value'   => null,
		);
	}
}

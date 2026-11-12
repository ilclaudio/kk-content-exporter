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
 *   has no value;
 * - `fixed`: the value of the post type of the item, or the general one `*`;
 * - `template`: the template of the post type of the item (or `*`), with the value of every placeholder: the
 *   first alternative with a value (the same empty values as a chain pass on; a literal always has one), in its
 *   standard text form (by the declared type of its source: dates in ISO 8601, lists joined by `, `, booleans
 *   `true`/`false`). A placeholder without a value is empty; when no placeholder has a value, the row has
 *   none. A template without placeholders is a fixed text;
 * - `lookup`: the input of the post type of the item (or `*`), in its standard text form without spaces at
 *   the ends, is compared exactly with the text of every case; the first case that matches gives its output
 *   (a fixed value, or the value of a source: none when it does not exist, its empty form when empty).
 *   An input that does not exist, is empty or is not confirmed matches no case: the default is used (none
 *   gives no value; a fixed value, a source, or the input as it is). A list is not an input: no value;
 * - `condition`: the input of the post type of the item (or `*`) is checked with one operator: `empty` /
 *   `not_empty` (the empty values of a chain; lists too), `equals` / `not_equals` / `one_of` (standard text
 *   form without spaces at the ends, exact; a list gives no value), `contains` (an element of a list, or a
 *   single value, equal to the text). A missing or unconfirmed input counts as an empty text. The `then` or
 *   `else` output follows (missing: no value; fixed, source or the input as it is);
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
	 * @param array<mixed>                     $row       Row of the mapping.
	 * @param string                           $post_type Post type of the item.
	 * @param callable                         $read      Reads a source of the item; null when it does not exist.
	 * @param (callable(string): ?string)|null $declared  Declared type of a source (for the text form of the
	 *                                                    placeholders of a template), null when unknown.
	 * @param string                           $origin    Origin of the site, for root-relative URLs.
	 * @return array{present: bool, value: mixed} Whether the row has a value, and the value.
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	public static function evaluate( array $row, string $post_type, callable $read, ?callable $declared = null, string $origin = '' ): array {
		return match ( $row['kind'] ?? null ) {
			'field'    => self::field( $row, $post_type, $read ),
			'fixed'    => self::fixed( $row, $post_type ),
			'template' => self::template( $row, $post_type, $read, $declared, $origin ),
			'lookup'    => self::lookup( $row, $post_type, $read, $declared, $origin ),
			'condition' => self::condition( $row, $post_type, $read, $declared, $origin ),
			default    => self::absent(),
		};
	}

	/**
	 * Object exported for an item: the mapping is a contract, so it has the key of every row, in the order of
	 * the rows, with `null` when the row has no value (a source that does not exist, a value that cannot be
	 * converted, an unconfirmed sensitive source, an unknown kind). An empty value keeps its empty form for
	 * text, HTML and lists (`""`, `[]`). Rows without a text key are skipped (a damaged mapping).
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

			$result = self::evaluate( $row, $post_type, $read, $declared, $origin );
			if ( $result['present'] ) {
				$result = Normalizer::normalize( $result['value'], self::row_type( $row, $declared ), $origin );
			}

			$item[ $row['key'] ] = $result['present'] ? $result['value'] : null;
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
		if ( 'template' === ( $row['kind'] ?? null ) ) {
			// A template always gives a text: plain unless the row asks for HTML.
			return 'html' === $type ? 'html' : 'string';
		}
		if ( 'auto' !== $type ) {
			return $type;
		}

		$types = array();
		if ( 'lookup' === ( $row['kind'] ?? null ) ) {
			$outputs   = array_map( static fn( $item ) => is_array( $item ) ? $item['output'] ?? null : null, (array) ( $row['cases'] ?? array() ) );
			$outputs[] = $row['default'] ?? null;

			return Normalizer::common_type( self::output_types( $outputs, $row, $declared ) );
		}

		if ( 'condition' === ( $row['kind'] ?? null ) ) {
			return Normalizer::common_type( self::output_types( array( $row['then'] ?? null, $row['else'] ?? null ), $row, $declared ) );
		}

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

			if ( ! self::can_read( $source, $confirmed ) ) {
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
	 * Value of a `template` row.
	 *
	 * @param array<mixed>                     $row       Row of the mapping.
	 * @param string                           $post_type Post type of the item.
	 * @param callable                         $read      Reads a source of the item.
	 * @param (callable(string): ?string)|null $declared  Declared type of a source.
	 * @param string                           $origin    Origin of the site.
	 * @return array{present: bool, value: mixed}
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	private static function template( array $row, string $post_type, callable $read, ?callable $declared, string $origin ): array {
		$parsed = Template::parse( self::for_post_type( $row['templates'] ?? null, $post_type ) );
		if ( '' !== $parsed['error'] ) {
			return self::absent();
		}

		$confirmed    = is_array( $row['confirmed'] ?? null ) ? $row['confirmed'] : array();
		$text         = '';
		$placeholders = 0;
		$found        = false;

		foreach ( $parsed['parts'] as $part ) {
			if ( array() === $part['alternatives'] ) {
				$text .= $part['text'];
				continue;
			}

			++$placeholders;
			foreach ( $part['alternatives'] as $alternative ) {
				if ( isset( $alternative['literal'] ) ) {
					$text .= $alternative['literal'];
					$found = true;
					break;
				}

				$source = $alternative['source'];
				if ( ! self::can_read( $source, $confirmed ) ) {
					continue;
				}

				$value = $read( $source, array() );
				if ( null === $value || self::is_empty( $value ) ) {
					continue;
				}

				$text .= self::as_text( $value, null === $declared ? null : $declared( $source ), $origin );
				$found = true;
				break;
			}
		}

		return $placeholders > 0 && ! $found ? self::absent() : self::present( $text );
	}

	/**
	 * Value of a `lookup` row.
	 *
	 * @param array<mixed>                     $row       Row of the mapping.
	 * @param string                           $post_type Post type of the item.
	 * @param callable                         $read      Reads a source of the item.
	 * @param (callable(string): ?string)|null $declared  Declared type of a source.
	 * @param string                           $origin    Origin of the site.
	 * @return array{present: bool, value: mixed}
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	private static function lookup( array $row, string $post_type, callable $read, ?callable $declared, string $origin ): array {
		$confirmed = is_array( $row['confirmed'] ?? null ) ? $row['confirmed'] : array();
		$input     = self::for_post_type( $row['inputs'] ?? null, $post_type );
		$value     = is_string( $input ) && self::can_read( $input, $confirmed ) ? $read( $input, array() ) : null;

		if ( is_array( $value ) ) {
			return self::absent();
		}

		if ( is_string( $input ) && null !== $value && ! self::is_empty( $value ) && is_array( $row['cases'] ?? null ) ) {
			$text = trim( self::as_text( $value, null === $declared ? null : $declared( $input ), $origin ) );
			foreach ( $row['cases'] as $case ) {
				if ( is_array( $case ) && ( $case['match'] ?? null ) === $text ) {
					return self::output( $case['output'] ?? null, $read, $confirmed );
				}
			}
		}

		$default = $row['default'] ?? null;
		if ( is_array( $default ) && true === ( $default['input'] ?? null ) ) {
			return self::found( $value );
		}

		return self::output( $default, $read, $confirmed );
	}

	/**
	 * Value of an output of a `lookup` or `condition` row: a fixed value, or the value of a source.
	 *
	 * @param mixed        $output    The output (`fixed` or `source`).
	 * @param callable     $read      Reads a source of the item.
	 * @param array<mixed> $confirmed Confirmed sources of the row.
	 * @return array{present: bool, value: mixed}
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	private static function output( $output, callable $read, array $confirmed ): array {
		if ( ! is_array( $output ) ) {
			return self::absent();
		}

		if ( array_key_exists( 'fixed', $output ) && is_scalar( $output['fixed'] ) ) {
			return self::present( $output['fixed'] );
		}

		$source = $output['source'] ?? null;
		if ( ! is_string( $source ) || ! self::can_read( $source, $confirmed ) ) {
			return self::absent();
		}

		return self::found( $read( $source, array() ) );
	}

	/**
	 * Result of a value read from a source: none for null, an empty value as `""` or `[]`.
	 *
	 * @param mixed $value Value read.
	 * @return array{present: bool, value: mixed}
	 */
	private static function found( $value ): array {
		if ( null === $value ) {
			return self::absent();
		}

		return self::present( self::is_empty( $value ) ? ( is_array( $value ) ? array() : '' ) : $value );
	}

	/**
	 * Value of a `condition` row.
	 *
	 * @param array<mixed>                     $row       Row of the mapping.
	 * @param string                           $post_type Post type of the item.
	 * @param callable                         $read      Reads a source of the item.
	 * @param (callable(string): ?string)|null $declared  Declared type of a source.
	 * @param string                           $origin    Origin of the site.
	 * @return array{present: bool, value: mixed}
	 *
	 * @phpstan-param callable(string, array<mixed>): mixed $read
	 */
	private static function condition( array $row, string $post_type, callable $read, ?callable $declared, string $origin ): array {
		$input    = self::for_post_type( $row['inputs'] ?? null, $post_type );
		$operator = $row['operator'] ?? null;
		$matches  = $row['matches'] ?? null;
		$compares = ! in_array( $operator, array( 'empty', 'not_empty' ), true );

		if ( ! is_string( $input ) || ! in_array( $operator, Mapping::OPERATORS, true ) || ( $compares && ! is_array( $matches ) ) ) {
			return self::absent();
		}

		$confirmed = is_array( $row['confirmed'] ?? null ) ? $row['confirmed'] : array();
		$value     = self::can_read( $input, $confirmed ) ? $read( $input, array() ) : null;
		$empty     = null === $value || self::is_empty( $value );

		if ( is_array( $value ) && in_array( $operator, array( 'equals', 'not_equals', 'one_of' ), true ) ) {
			return self::absent();
		}

		$text = static fn( $item ): string => $empty ? '' : trim( self::as_text( $item, null === $declared ? null : $declared( $input ), $origin ) );

		$true = match ( $operator ) {
			'empty'      => $empty,
			'not_empty'  => ! $empty,
			'equals'     => in_array( $text( $value ), array_slice( (array) $matches, 0, 1 ), true ),
			'not_equals' => ! in_array( $text( $value ), array_slice( (array) $matches, 0, 1 ), true ),
			'one_of'     => ! $empty && in_array( $text( $value ), (array) $matches, true ),
			default      => ! $empty && self::contains( $value, $text, (string) ( ( (array) $matches )[0] ?? '' ), $origin ),
		};

		$output = $row[ $true ? 'then' : 'else' ] ?? null;
		if ( is_array( $output ) && true === ( $output['input'] ?? null ) ) {
			return self::found( $value );
		}

		return self::output( $output, $read, $confirmed );
	}

	/**
	 * Whether a value contains a text: an element of a list equal to the text, or the text inside a single
	 * value (capital letters included).
	 *
	 * @param mixed    $value  Value of the input, not empty.
	 * @param callable $text   Standard text form of the value of the input.
	 * @param string   $needle Text to look for.
	 * @param string   $origin Origin of the site.
	 * @return bool
	 *
	 * @phpstan-param callable(mixed): string $text
	 */
	private static function contains( $value, callable $text, string $needle, string $origin ): bool {
		if ( '' === $needle ) {
			return false;
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( trim( self::as_text( $item, null, $origin ) ) === $needle ) {
					return true;
				}
			}
			return false;
		}

		return str_contains( $text( $value ), $needle );
	}

	/**
	 * Declared types of the outputs of a `lookup` or `condition` row: fixed values by their JSON type, sources
	 * by their declared type, and the inputs of the row for an output that is the input.
	 *
	 * @param array<mixed>                     $outputs  Outputs of the row.
	 * @param array<mixed>                     $row      Row of the mapping.
	 * @param (callable(string): ?string)|null $declared Declared type of a source.
	 * @return list<string|null>
	 */
	private static function output_types( array $outputs, array $row, ?callable $declared ): array {
		$types = array();
		foreach ( $outputs as $output ) {
			if ( ! is_array( $output ) ) {
				continue;
			}
			if ( array_key_exists( 'fixed', $output ) ) {
				$types[] = Normalizer::type_of_value( $output['fixed'] );
			} elseif ( is_string( $output['source'] ?? null ) ) {
				$types[] = null === $declared ? null : $declared( $output['source'] );
			} elseif ( true === ( $output['input'] ?? null ) ) {
				foreach ( (array) ( $row['inputs'] ?? array() ) as $input ) {
					$types[] = is_string( $input ) && null !== $declared ? $declared( $input ) : null;
				}
			}
		}

		return $types;
	}

	/**
	 * Whether a source can be read: a sensitive source only when the row confirms it.
	 *
	 * @param string       $source    Source identifier.
	 * @param array<mixed> $confirmed Confirmed sources of the row.
	 * @return bool
	 */
	private static function can_read( string $source, array $confirmed ): bool {
		return ! Sensitive_Keys::is_sensitive_source( $source ) || in_array( $source, $confirmed, true );
	}

	/**
	 * Standard text form of the value of a placeholder: texts as they are (HTML included), other values by
	 * the declared type of their source; a value that cannot be converted is empty.
	 *
	 * @param mixed       $value    Value of the source.
	 * @param string|null $declared Declared type of the source, null when unknown (text).
	 * @param string      $origin   Origin of the site.
	 * @return string
	 */
	private static function as_text( $value, ?string $declared, string $origin ): string {
		if ( is_string( $value ) && in_array( $declared ?? 'string', array( 'string', 'html' ), true ) ) {
			return $value;
		}

		$result = Normalizer::normalize( $value, $declared ?? 'string', $origin );
		if ( ! $result['present'] ) {
			return '';
		}

		return self::scalar_text( $result['value'] );
	}

	/**
	 * Text of a normalized value: booleans `true`/`false`, lists joined by `, `.
	 *
	 * @param mixed $value Normalized value.
	 * @return string
	 */
	private static function scalar_text( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_array( $value ) ) {
			return implode( ', ', array_map( array( self::class, 'scalar_text' ), $value ) );
		}

		return is_scalar( $value ) ? (string) $value : '';
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

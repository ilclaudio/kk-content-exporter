<?php
/**
 * Validation and canonical form of the mapping of a feed.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a decoded mapping against the format and gives it a canonical form.
 *
 * Pure logic: no WordPress function is called. `validate()` returns every problem it finds, so the
 * editor can list them all; it never changes the mapping, and the caller refuses the whole mapping
 * when there is at least one error (the rows depend on one another, for example through their keys).
 * Whether a source exists for the post types of a feed is not decided here: it needs the catalog of
 * the sources, and is checked when the mapping is saved. The sensitive keys are checked there too.
 *
 * Error codes (`code` of an error):
 * - `not_object`: the mapping is not an object;
 * - `unknown`: a version, kind, type or source group that is not known;
 * - `unknown_field`: a field that the mapping or the row does not have;
 * - `required`: a field that must be present;
 * - `format`: a value of the wrong shape or with characters that are not allowed;
 * - `empty`, `too_long`, `too_many`: a value that is empty, longer or more numerous than the limit;
 * - `duplicate`: an output key used by an earlier row;
 * - `not_allowed`: a field that this kind of row cannot have;
 * - `sensitive`: a source whose name looks sensitive (`Sensitive_Keys`) and that the row does not
 *   confirm in `confirmed` (decision of 2026-11-10: a sensitive source needs an explicit confirmation,
 *   source by source, instead of being refused).
 *
 * @phpstan-type MappingError array{row: int, field: string, code: string}
 */
final class Mapping_Validator {

	/**
	 * Row number of the errors that concern the whole mapping.
	 */
	public const MAPPING_ROW = -1;

	/**
	 * Maximum number of sources in the chain of a post type.
	 */
	public const MAX_CHAIN = 10;

	/**
	 * Maximum length of a fixed text.
	 */
	public const VALUE_MAX_LENGTH = 1000;

	/**
	 * Maximum number of options of a row.
	 */
	public const MAX_OPTIONS = 10;

	/**
	 * Maximum length of the text of an option.
	 */
	public const OPTION_MAX_LENGTH = 100;

	/**
	 * Fields every row has, in canonical order.
	 */
	private const COMMON_FIELDS = array( 'key', 'kind', 'type' );

	/**
	 * Fields of each kind of row, after the common ones, in canonical order.
	 */
	private const KIND_FIELDS = array(
		'field' => array( 'sources', 'confirmed', 'options' ),
		'fixed' => array( 'values', 'options' ),
	);

	/**
	 * Characters of the output key of a row: letters, digits, `_`, `-`, `.`.
	 */
	private const KEY_CHARACTERS = 'A-Za-z0-9_.-';

	/**
	 * Format of the output key, written so that PCRE and JavaScript read it the same way: the mapping editor
	 * receives it from the server instead of copying it.
	 */
	public const KEY_FORMAT = '^[' . self::KEY_CHARACTERS . ']+$';

	/**
	 * Format of the output key, as a PHP pattern (`\z` instead of `$`, which would accept a final newline).
	 */
	private const KEY_PATTERN = '/\A[' . self::KEY_CHARACTERS . ']+\z/';

	/**
	 * Format of a source identifier: a group, a dot and a name.
	 */
	private const SOURCE_PATTERN = '/\A([a-z][a-z0-9]*)\.([A-Za-z0-9_.:-]{1,191})\z/';

	/**
	 * Format of the key of a map of post types: a post type key, or `*` for every post type of the feed.
	 */
	private const POST_TYPE_PATTERN = '/\A(?:\*|[a-z0-9_-]{1,20})\z/';

	/**
	 * Format of the name of an option.
	 */
	private const OPTION_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

	/**
	 * Reads, checks and normalizes the JSON text of a mapping in one call.
	 *
	 * @param mixed $json JSON text.
	 * @return array{version: int, rows: list<array<string, mixed>>}|null The canonical mapping, or null
	 *                                                                   when the text is not a valid mapping.
	 */
	public static function parse( $json ): ?array {
		$decoded = Mapping::decode( $json );

		if ( null === $decoded || array() !== self::validate( $decoded ) ) {
			return null;
		}

		return self::normalize( $decoded );
	}

	/**
	 * Checks a decoded mapping.
	 *
	 * @param mixed $mapping Decoded mapping (see `Mapping::decode()`).
	 * @return list<array{row: int, field: string, code: string}> Every error found, in order of row; an
	 *                                                              empty list for a valid mapping.
	 */
	public static function validate( $mapping ): array {
		if ( ! is_array( $mapping ) || ( array() !== $mapping && array_is_list( $mapping ) ) ) {
			return array( self::error( self::MAPPING_ROW, '', 'not_object' ) );
		}

		if ( ! isset( $mapping['version'] ) || Mapping::VERSION !== $mapping['version'] ) {
			return array( self::error( self::MAPPING_ROW, 'version', 'unknown' ) );
		}

		$errors = array();
		foreach ( array_keys( $mapping ) as $name ) {
			if ( 'version' !== $name && 'rows' !== $name ) {
				$errors[] = self::error( self::MAPPING_ROW, (string) $name, 'unknown_field' );
			}
		}

		$rows = $mapping['rows'] ?? null;
		if ( ! is_array( $rows ) || ( array() !== $rows && ! array_is_list( $rows ) ) ) {
			$errors[] = self::error( self::MAPPING_ROW, 'rows', 'format' );
			return $errors;
		}

		if ( count( $rows ) > Mapping::MAX_ROWS ) {
			$errors[] = self::error( self::MAPPING_ROW, 'rows', 'too_many' );
			return $errors;
		}

		$keys = array();
		foreach ( $rows as $index => $row ) {
			foreach ( self::validate_row( $index, $row, $keys ) as $error ) {
				$errors[] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Canonical form of a valid mapping: every field in a fixed order, the default type (`auto`) written
	 * out, keys of the maps of post types as text, and empty options left out.
	 * Normalizing a normalized mapping changes nothing.
	 *
	 * Call it only after `validate()` found no error: on an invalid mapping the result is not defined.
	 *
	 * @param array<mixed> $mapping Valid decoded mapping.
	 * @return array{version: int, rows: list<array<string, mixed>>}
	 */
	public static function normalize( array $mapping ): array {
		$rows = array();

		foreach ( (array) ( $mapping['rows'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$kind = is_string( $row['kind'] ?? null ) ? $row['kind'] : '';
			$out  = array(
				'key'  => $row['key'] ?? '',
				'kind' => $kind,
				'type' => $row['type'] ?? 'auto',
			);

			if ( 'field' === $kind ) {
				$out['sources'] = self::normalize_map( $row['sources'] ?? array(), static fn( $chain ) => array_values( (array) $chain ) );
				if ( array() !== ( $row['confirmed'] ?? array() ) ) {
					$out['confirmed'] = array_values( (array) $row['confirmed'] );
				}
			} elseif ( 'fixed' === $kind ) {
				$out['values'] = self::normalize_map( $row['values'] ?? array(), static fn( $value ) => $value );
			}

			if ( array() !== ( $row['options'] ?? array() ) ) {
				$out['options'] = $row['options'];
			}

			$rows[] = $out;
		}

		return array(
			'version' => Mapping::VERSION,
			'rows'    => $rows,
		);
	}

	/**
	 * Checks one row.
	 *
	 * @param int                $index Position of the row.
	 * @param mixed              $row   The row.
	 * @param array<string,true> $keys  Output keys already used, updated by the call.
	 * @return list<array{row: int, field: string, code: string}>
	 */
	private static function validate_row( int $index, $row, array &$keys ): array {
		if ( ! is_array( $row ) || ( array() !== $row && array_is_list( $row ) ) ) {
			return array( self::error( $index, '', 'format' ) );
		}

		$errors = array();
		$kind   = $row['kind'] ?? null;
		$known  = is_string( $kind ) && in_array( $kind, Mapping::KINDS, true );

		$allowed = $known ? array_merge( self::COMMON_FIELDS, self::KIND_FIELDS[ $kind ] ) : array_keys( $row );
		foreach ( array_keys( $row ) as $name ) {
			if ( ! in_array( $name, $allowed, true ) ) {
				$errors[] = self::error( $index, (string) $name, 'unknown_field' );
			}
		}

		$code = self::key_error( $row['key'] ?? null, array_key_exists( 'key', $row ), $keys );
		if ( '' !== $code ) {
			$errors[] = self::error( $index, 'key', $code );
		}

		if ( ! array_key_exists( 'kind', $row ) ) {
			$errors[] = self::error( $index, 'kind', 'required' );
		} elseif ( ! $known ) {
			$errors[] = self::error( $index, 'kind', 'unknown' );
		}

		if ( array_key_exists( 'type', $row ) && ( ! is_string( $row['type'] ) || ! in_array( $row['type'], Mapping::TYPES, true ) ) ) {
			$errors[] = self::error( $index, 'type', 'unknown' );
		}

		if ( ! $known ) {
			return $errors;
		}

		$detail = 'field' === $kind
			? self::sources_errors( $row['sources'] ?? null, array_key_exists( 'sources', $row ) )
			: self::values_errors( $row['values'] ?? null, array_key_exists( 'values', $row ) );
		foreach ( $detail as $code ) {
			$errors[] = self::error( $index, 'field' === $kind ? 'sources' : 'values', $code );
		}

		if ( 'field' === $kind && array() === $detail ) {
			foreach ( self::confirmation_errors( $row ) as $error ) {
				$errors[] = self::error( $index, $error[0], $error[1] );
			}
		}

		if ( array_key_exists( 'options', $row ) ) {
			foreach ( self::options_errors( $row['options'], 'field' === $kind ) as $code ) {
				$errors[] = self::error( $index, 'options', $code );
			}
		}

		return $errors;
	}

	/**
	 * Error of the output key of a row.
	 *
	 * @param mixed              $key     The key.
	 * @param bool               $present Whether the row has the field.
	 * @param array<string,true> $keys    Output keys already used, updated by the call.
	 * @return string Error code, or an empty string.
	 */
	private static function key_error( $key, bool $present, array &$keys ): string {
		if ( ! $present ) {
			return 'required';
		}

		if ( '' === $key ) {
			return 'empty';
		}

		if ( ! is_string( $key ) || 1 !== preg_match( self::KEY_PATTERN, $key ) || ctype_digit( $key ) ) {
			return 'format';
		}

		if ( strlen( $key ) > Mapping::KEY_MAX_LENGTH ) {
			return 'too_long';
		}

		if ( isset( $keys[ $key ] ) ) {
			return 'duplicate';
		}

		$keys[ $key ] = true;

		return '';
	}

	/**
	 * Errors of the sources of a `field` row: a map of post types (or `*`) to a chain of identifiers.
	 *
	 * @param mixed $sources Value of the field.
	 * @param bool  $present Whether the row has the field.
	 * @return list<string> Error codes, in order.
	 */
	private static function sources_errors( $sources, bool $present ): array {
		if ( ! $present ) {
			return array( 'required' );
		}

		if ( ! self::is_post_type_map( $sources ) ) {
			return array( 'format' );
		}

		$codes = array();
		foreach ( $sources as $chain ) {
			if ( ! is_array( $chain ) || ( array() !== $chain && ! array_is_list( $chain ) ) ) {
				$codes[] = 'format';
			} elseif ( array() === $chain ) {
				$codes[] = 'empty';
			} elseif ( count( $chain ) > self::MAX_CHAIN ) {
				$codes[] = 'too_many';
			} else {
				foreach ( $chain as $source ) {
					$code = self::source_error( $source );
					if ( '' !== $code ) {
						$codes[] = $code;
					}
				}
			}
		}

		return $codes;
	}

	/**
	 * Errors of the confirmations of a `field` row whose sources are well formed.
	 *
	 * `confirmed` lists, once each, sources of the same row; every sensitive source of the row must be
	 * in it. Confirming a source that is not sensitive costs nothing and is accepted.
	 *
	 * @param array<mixed> $row The row.
	 * @return list<array{0: string, 1: string}> Field and code of every error.
	 */
	private static function confirmation_errors( array $row ): array {
		$sources = array();
		foreach ( (array) $row['sources'] as $chain ) {
			foreach ( (array) $chain as $source ) {
				$sources[ (string) $source ] = true;
			}
		}

		$errors    = array();
		$confirmed = $row['confirmed'] ?? array();
		if ( ! self::is_confirmation_list( $confirmed, $sources ) ) {
			$errors[]  = array( 'confirmed', 'format' );
			$confirmed = array();
		}

		foreach ( array_keys( $sources ) as $source ) {
			if ( Sensitive_Keys::is_sensitive_source( $source ) && ! in_array( $source, (array) $confirmed, true ) ) {
				$errors[] = array( 'sources', 'sensitive' );
			}
		}

		return $errors;
	}

	/**
	 * Whether a value is a list of distinct sources of the row.
	 *
	 * @param mixed              $confirmed Value of `confirmed`.
	 * @param array<string,true> $sources   Sources of the row.
	 * @return bool
	 */
	private static function is_confirmation_list( $confirmed, array $sources ): bool {
		if ( ! is_array( $confirmed ) || ! array_is_list( $confirmed ) ) {
			return false;
		}

		foreach ( $confirmed as $source ) {
			if ( ! is_string( $source ) || ! isset( $sources[ $source ] ) ) {
				return false;
			}
		}

		return count( $confirmed ) === count( array_unique( $confirmed ) );
	}

	/**
	 * Error of a source identifier (`group.name`).
	 *
	 * @param mixed $source The identifier.
	 * @return string Error code, or an empty string.
	 */
	private static function source_error( $source ): string {
		if ( ! is_string( $source ) || 1 !== preg_match( self::SOURCE_PATTERN, $source, $match ) ) {
			return 'format';
		}

		return in_array( $match[1], Mapping::SOURCE_GROUPS, true ) ? '' : 'unknown';
	}

	/**
	 * Errors of the values of a `fixed` row: a map of post types (or `*`) to a text, number or boolean.
	 *
	 * @param mixed $values  Value of the field.
	 * @param bool  $present Whether the row has the field.
	 * @return list<string> Error codes, in order.
	 */
	private static function values_errors( $values, bool $present ): array {
		if ( ! $present ) {
			return array( 'required' );
		}

		if ( ! self::is_post_type_map( $values ) ) {
			return array( 'format' );
		}

		$codes = array();
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				$codes[] = 'format';
			} elseif ( is_string( $value ) && strlen( $value ) > self::VALUE_MAX_LENGTH ) {
				$codes[] = 'too_long';
			}
		}

		return $codes;
	}

	/**
	 * Errors of the options of a row: a small map of plain settings; only a `field` row can have some.
	 *
	 * @param mixed $options    Value of the field.
	 * @param bool  $can_have   Whether the kind of row accepts options.
	 * @return list<string> Error codes, in order.
	 */
	private static function options_errors( $options, bool $can_have ): array {
		if ( ! is_array( $options ) || ( array() !== $options && array_is_list( $options ) ) ) {
			return array( 'format' );
		}

		if ( array() === $options ) {
			return array();
		}

		if ( ! $can_have ) {
			return array( 'not_allowed' );
		}

		if ( count( $options ) > self::MAX_OPTIONS ) {
			return array( 'too_many' );
		}

		$codes = array();
		foreach ( $options as $name => $value ) {
			if ( 1 !== preg_match( self::OPTION_PATTERN, (string) $name ) || ! is_scalar( $value ) ) {
				$codes[] = 'format';
			} elseif ( is_string( $value ) && strlen( $value ) > self::OPTION_MAX_LENGTH ) {
				$codes[] = 'too_long';
			}
		}

		return $codes;
	}

	/**
	 * Whether a value is a non-empty map whose keys are post type keys or `*`.
	 *
	 * @param mixed $map The value.
	 * @return bool
	 */
	private static function is_post_type_map( $map ): bool {
		if ( ! is_array( $map ) || array() === $map || array_is_list( $map ) ) {
			return false;
		}

		foreach ( array_keys( $map ) as $key ) {
			if ( 1 !== preg_match( self::POST_TYPE_PATTERN, (string) $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Rewrites a map of post types with text keys and normalized entries.
	 *
	 * @param mixed                  $map   The map.
	 * @param callable(mixed): mixed $entry Normalizes an entry.
	 * @return array<string, mixed>
	 */
	private static function normalize_map( $map, callable $entry ): array {
		$result = array();

		foreach ( (array) $map as $key => $value ) {
			$result[ (string) $key ] = $entry( $value );
		}

		return $result;
	}

	/**
	 * An error of the mapping.
	 *
	 * @param int    $row   Row number, or `MAPPING_ROW`.
	 * @param string $field Field name, empty when the error concerns the row or the mapping.
	 * @param string $code  Error code.
	 * @return array{row: int, field: string, code: string}
	 */
	private static function error( int $row, string $field, string $code ): array {
		return array(
			'row'   => $row,
			'field' => $field,
			'code'  => $code,
		);
	}
}

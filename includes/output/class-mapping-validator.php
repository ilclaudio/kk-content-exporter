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
 * - `duplicate`: an output key used by an earlier row, or a text matched by an earlier case of a table;
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
	 * Maximum number of cases of the table of a `lookup` row, and of texts compared by `one_of` in a `condition`
	 * row.
	 */
	public const MAX_CASES = 100;

	/**
	 * Fields every row has, in canonical order.
	 */
	private const COMMON_FIELDS = array( 'key', 'kind', 'type' );

	/**
	 * Fields of each kind of row, after the common ones, in canonical order.
	 */
	private const KIND_FIELDS = array(
		'field'     => array( 'sources', 'confirmed', 'options' ),
		'fixed'     => array( 'values', 'options' ),
		'template'  => array( 'templates', 'confirmed' ),
		'lookup'    => array( 'inputs', 'cases', 'default', 'confirmed' ),
		'condition' => array( 'inputs', 'operator', 'matches', 'then', 'else', 'confirmed' ),
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
			} elseif ( 'template' === $kind ) {
				$out['templates'] = self::normalize_map( $row['templates'] ?? array(), static fn( $template ) => (string) $template );
				if ( array() !== ( $row['confirmed'] ?? array() ) ) {
					$out['confirmed'] = array_values( (array) $row['confirmed'] );
				}
			} elseif ( 'lookup' === $kind ) {
				$out['inputs'] = self::normalize_map( $row['inputs'] ?? array(), static fn( $source ) => (string) $source );
				$out['cases']  = array();
				foreach ( (array) ( $row['cases'] ?? array() ) as $case ) {
					$out['cases'][] = array(
						'match'  => is_array( $case ) ? $case['match'] ?? '' : '',
						'output' => is_array( $case ) ? $case['output'] ?? array() : array(),
					);
				}
				if ( array_key_exists( 'default', $row ) ) {
					$out['default'] = $row['default'];
				}
				if ( array() !== ( $row['confirmed'] ?? array() ) ) {
					$out['confirmed'] = array_values( (array) $row['confirmed'] );
				}
			} elseif ( 'condition' === $kind ) {
				$out['inputs']   = self::normalize_map( $row['inputs'] ?? array(), static fn( $source ) => (string) $source );
				$out['operator'] = $row['operator'] ?? '';
				foreach ( array( 'matches', 'then', 'else' ) as $field ) {
					if ( array_key_exists( $field, $row ) ) {
						$out[ $field ] = $row[ $field ];
					}
				}
				if ( array() !== ( $row['confirmed'] ?? array() ) ) {
					$out['confirmed'] = array_values( (array) $row['confirmed'] );
				}
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

		if ( 'template' === $kind && is_string( $row['type'] ?? null ) && in_array( $row['type'], Mapping::TYPES, true ) && ! in_array( $row['type'], Mapping::TEMPLATE_TYPES, true ) ) {
			$errors[] = self::error( $index, 'type', 'not_allowed' );
		}

		$detail = match ( $kind ) {
			'field'    => self::field_errors( 'sources', self::sources_errors( $row['sources'] ?? null, array_key_exists( 'sources', $row ) ) ),
			'template' => self::field_errors( 'templates', self::templates_errors( $row['templates'] ?? null, array_key_exists( 'templates', $row ) ) ),
			'lookup'    => self::lookup_errors( $row ),
			'condition' => self::condition_errors( $row ),
			default    => self::field_errors( 'values', self::values_errors( $row['values'] ?? null, array_key_exists( 'values', $row ) ) ),
		};
		foreach ( $detail as $error ) {
			$errors[] = self::error( $index, $error[0], $error[1] );
		}

		if ( 'fixed' !== $kind && array() === $detail ) {
			foreach ( self::confirmation_errors( $row, self::row_sources( $row ) ) as $error ) {
				$errors[] = self::error( $index, $error[0], $error[1] );
			}
		}

		if ( array_key_exists( 'options', $row ) && in_array( 'options', self::KIND_FIELDS[ $kind ], true ) ) {
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
	 * Errors of the templates of a `template` row: a map of post types (or `*`) to a template text.
	 *
	 * @param mixed $templates Value of the field.
	 * @param bool  $present   Whether the row has the field.
	 * @return list<string> Error codes, in order.
	 */
	private static function templates_errors( $templates, bool $present ): array {
		if ( ! $present ) {
			return array( 'required' );
		}

		if ( array() === $templates ) {
			return array( 'empty' );
		}

		if ( ! self::is_post_type_map( $templates ) ) {
			return array( 'format' );
		}

		$codes = array();
		foreach ( $templates as $template ) {
			if ( '' === $template ) {
				$codes[] = 'empty';
				continue;
			}

			$parsed = Template::parse( $template );
			if ( '' !== $parsed['error'] ) {
				$codes[] = $parsed['error'];
				continue;
			}

			foreach ( Template::sources( $parsed['parts'] ) as $source ) {
				$code = self::source_error( $source );
				if ( '' !== $code ) {
					$codes[] = $code;
				}
			}
		}

		return $codes;
	}

	/**
	 * Errors of the fields of a `lookup` row: inputs, cases and the optional default.
	 *
	 * @param array<mixed> $row The row.
	 * @return list<array{0: string, 1: string}> Field and code of every error.
	 */
	private static function lookup_errors( array $row ): array {
		$errors = self::field_errors( 'inputs', self::inputs_errors( $row['inputs'] ?? null, array_key_exists( 'inputs', $row ) ) );

		foreach ( self::cases_errors( $row['cases'] ?? null, array_key_exists( 'cases', $row ) ) as $code ) {
			$errors[] = array( 'cases', $code );
		}

		if ( array_key_exists( 'default', $row ) ) {
			$code = self::output_error( $row['default'], true );
			if ( '' !== $code ) {
				$errors[] = array( 'default', $code );
			}
		}

		return $errors;
	}

	/**
	 * Errors of the fields of a `condition` row: inputs, operator, the texts it compares and the outputs.
	 *
	 * @param array<mixed> $row The row.
	 * @return list<array{0: string, 1: string}> Field and code of every error.
	 */
	private static function condition_errors( array $row ): array {
		$errors = self::field_errors( 'inputs', self::inputs_errors( $row['inputs'] ?? null, array_key_exists( 'inputs', $row ) ) );

		$operator = $row['operator'] ?? null;
		if ( ! array_key_exists( 'operator', $row ) ) {
			$errors[] = array( 'operator', 'required' );
		} elseif ( ! is_string( $operator ) || ! in_array( $operator, Mapping::OPERATORS, true ) ) {
			$errors[] = array( 'operator', 'unknown' );
		} else {
			$code = self::matches_error( $operator, $row['matches'] ?? null, array_key_exists( 'matches', $row ) );
			if ( '' !== $code ) {
				$errors[] = array( 'matches', $code );
			}
		}

		foreach ( array( 'then', 'else' ) as $field ) {
			if ( array_key_exists( $field, $row ) ) {
				$code = self::output_error( $row[ $field ], true );
				if ( '' !== $code ) {
					$errors[] = array( $field, $code );
				}
			}
		}

		return $errors;
	}

	/**
	 * Error of the texts compared by a `condition` row: none for `empty` and `not_empty`, a list of one text
	 * for the others, a list of 1 to `MAX_CASES` distinct texts for `one_of`.
	 *
	 * @param string $operator Known operator.
	 * @param mixed  $matches  Value of the field.
	 * @param bool   $present  Whether the row has the field.
	 * @return string Error code, or an empty string.
	 */
	private static function matches_error( string $operator, $matches, bool $present ): string {
		if ( in_array( $operator, array( 'empty', 'not_empty' ), true ) ) {
			return $present ? 'not_allowed' : '';
		}

		if ( ! $present ) {
			return 'required';
		}

		if ( ! is_array( $matches ) || ! array_is_list( $matches ) ) {
			return 'format';
		}

		if ( array() === $matches ) {
			return 'empty';
		}

		if ( count( $matches ) > ( 'one_of' === $operator ? self::MAX_CASES : 1 ) ) {
			return 'too_many';
		}

		$seen = array();
		foreach ( $matches as $text ) {
			$code = self::match_error( $text, $seen );
			if ( '' !== $code ) {
				return $code;
			}
		}

		return '';
	}

	/**
	 * Errors of the inputs of a `lookup` or `condition` row: a map of post types (or `*`) to one source identifier.
	 *
	 * @param mixed $inputs  Value of the field.
	 * @param bool  $present Whether the row has the field.
	 * @return list<string> Error codes, in order.
	 */
	private static function inputs_errors( $inputs, bool $present ): array {
		if ( ! $present ) {
			return array( 'required' );
		}

		if ( array() === $inputs ) {
			return array( 'empty' );
		}

		if ( ! self::is_post_type_map( $inputs ) ) {
			return array( 'format' );
		}

		$codes = array();
		foreach ( $inputs as $source ) {
			$code = self::source_error( $source );
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Errors of the table of a `lookup` row: a non-empty list of cases `{ match, output }`. The text to match
	 * is not empty, has no spaces at the ends (the input is compared without them) and is used once.
	 *
	 * @param mixed $cases   Value of the field.
	 * @param bool  $present Whether the row has the field.
	 * @return list<string> Error codes, in order.
	 */
	private static function cases_errors( $cases, bool $present ): array {
		if ( ! $present ) {
			return array( 'required' );
		}

		if ( ! is_array( $cases ) || ! array_is_list( $cases ) ) {
			return array( 'format' );
		}

		if ( array() === $cases ) {
			return array( 'empty' );
		}

		if ( count( $cases ) > self::MAX_CASES ) {
			return array( 'too_many' );
		}

		$codes = array();
		$seen  = array();
		foreach ( $cases as $case ) {
			if ( ! is_array( $case ) || array( 'match', 'output' ) !== self::sorted_keys( $case ) ) {
				$codes[] = 'format';
				continue;
			}

			$code = self::match_error( $case['match'], $seen );
			if ( '' === $code ) {
				$code = self::output_error( $case['output'], false );
			}
			if ( '' !== $code ) {
				$codes[] = $code;
			}
		}

		return $codes;
	}

	/**
	 * Error of the text that a case of a `lookup` row matches, or of a text compared by a `condition` row.
	 *
	 * @param mixed              $text The text.
	 * @param array<string,true> $seen Texts of the earlier cases, updated by the call.
	 * @return string Error code, or an empty string.
	 */
	private static function match_error( $text, array &$seen ): string {
		if ( ! is_string( $text ) ) {
			return 'format';
		}

		if ( '' === $text ) {
			return 'empty';
		}

		if ( trim( $text ) !== $text ) {
			return 'format';
		}

		if ( strlen( $text ) > self::VALUE_MAX_LENGTH ) {
			return 'too_long';
		}

		if ( isset( $seen[ $text ] ) ) {
			return 'duplicate';
		}

		$seen[ $text ] = true;

		return '';
	}

	/**
	 * Error of an output of a `lookup` or `condition` row: an object with one field, `fixed` (a text, number or
	 * boolean) or `source` (a source identifier); the default of a table and the outputs of a condition can
	 * also be `input` (`true`: the input as it is).
	 *
	 * @param mixed $output      The output.
	 * @param bool  $allow_input Whether the output can be the input (not the cases of a table).
	 * @return string Error code, or an empty string.
	 */
	private static function output_error( $output, bool $allow_input ): string {
		if ( ! is_array( $output ) || 1 !== count( $output ) ) {
			return 'format';
		}

		$name  = (string) array_key_first( $output );
		$value = reset( $output );

		if ( 'fixed' === $name ) {
			if ( ! is_scalar( $value ) ) {
				return 'format';
			}

			return is_string( $value ) && strlen( $value ) > self::VALUE_MAX_LENGTH ? 'too_long' : '';
		}

		if ( 'source' === $name ) {
			return self::source_error( $value );
		}

		return $allow_input && 'input' === $name && true === $value ? '' : 'format';
	}

	/**
	 * Keys of an array, as texts, sorted.
	 *
	 * @param array<mixed> $value The array.
	 * @return list<string>
	 */
	private static function sorted_keys( array $value ): array {
		$keys = array_map( 'strval', array_keys( $value ) );
		sort( $keys );

		return $keys;
	}

	/**
	 * The error codes of one field, with the name of the field.
	 *
	 * @param string   $field Field name.
	 * @param string[] $codes Error codes.
	 * @return list<array{0: string, 1: string}>
	 *
	 * @phpstan-param list<string> $codes
	 */
	private static function field_errors( string $field, array $codes ): array {
		return array_map( static fn( string $code ): array => array( $field, $code ), $codes );
	}

	/**
	 * Sources a well-formed `field`, `template`, `lookup` or `condition` row reads, with the field that holds
	 * each one: every source of every chain, of every placeholder of every template, or the inputs and the
	 * sources of the outputs (cases and default of a table, `then` and `else` of a condition).
	 *
	 * @param array<mixed> $row The row.
	 * @return array<string, string> Source => field where it is first used.
	 */
	private static function row_sources( array $row ): array {
		$sources = array();

		if ( 'template' === $row['kind'] ) {
			foreach ( (array) $row['templates'] as $template ) {
				foreach ( Template::sources( Template::parse( $template )['parts'] ) as $source ) {
					$sources[ $source ] ??= 'templates';
				}
			}

			return $sources;
		}

		if ( 'lookup' === $row['kind'] ) {
			foreach ( (array) $row['inputs'] as $source ) {
				$sources[ (string) $source ] ??= 'inputs';
			}
			foreach ( (array) $row['cases'] as $case ) {
				if ( isset( $case['output']['source'] ) ) {
					$sources[ (string) $case['output']['source'] ] ??= 'cases';
				}
			}
			if ( isset( $row['default']['source'] ) ) {
				$sources[ (string) $row['default']['source'] ] ??= 'default';
			}

			return $sources;
		}

		if ( 'condition' === $row['kind'] ) {
			foreach ( (array) $row['inputs'] as $source ) {
				$sources[ (string) $source ] ??= 'inputs';
			}
			foreach ( array( 'then', 'else' ) as $field ) {
				if ( isset( $row[ $field ]['source'] ) ) {
					$sources[ (string) $row[ $field ]['source'] ] ??= $field;
				}
			}

			return $sources;
		}

		foreach ( (array) $row['sources'] as $chain ) {
			foreach ( (array) $chain as $source ) {
				$sources[ (string) $source ] ??= 'sources';
			}
		}

		return $sources;
	}

	/**
	 * Errors of the confirmations of a row (any kind but `fixed`) whose sources are well formed.
	 *
	 * `confirmed` lists, once each, sources of the same row; every sensitive source of the row must be
	 * in it. Confirming a source that is not sensitive costs nothing and is accepted.
	 *
	 * @param array<mixed>         $row     The row.
	 * @param array<string,string> $sources Sources of the row, with the field that holds each one.
	 * @return list<array{0: string, 1: string}> Field and code of every error.
	 */
	private static function confirmation_errors( array $row, array $sources ): array {
		$errors    = array();
		$confirmed = $row['confirmed'] ?? array();
		if ( ! self::is_confirmation_list( $confirmed, $sources ) ) {
			$errors[]  = array( 'confirmed', 'format' );
			$confirmed = array();
		}

		foreach ( $sources as $source => $field ) {
			if ( Sensitive_Keys::is_sensitive_source( (string) $source ) && ! in_array( (string) $source, (array) $confirmed, true ) ) {
				$errors[] = array( $field, 'sensitive' );
			}
		}

		return $errors;
	}

	/**
	 * Whether a value is a list of distinct sources of the row.
	 *
	 * @param mixed                $confirmed Value of `confirmed`.
	 * @param array<string,string> $sources   Sources of the row.
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
		if ( ! is_string( $source ) || 1 !== preg_match( Mapping::SOURCE_PATTERN, $source, $match ) ) {
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

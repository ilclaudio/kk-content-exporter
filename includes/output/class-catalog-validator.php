<?php
/**
 * Check of a mapping against the catalog of its feed.
 *
 * @package KKCE
 */

namespace KKCE\Output;

use KKCE\Sources\Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * Full check of a mapping for a feed: the format (`Mapping_Validator`), then the sources against the catalog
 * of the post types of the feed. The preview and the save use this one function, `validate_text()`, on the
 * JSON text they receive.
 *
 * Catalog rules (decisions of 2026-11-13):
 * - every source must exist: in a map of post types, the entry of a post type of the feed uses the sources of
 *   that post type and the common ones; the general entry `*` and the outputs, which apply to every post type,
 *   use any source of the feed. An entry for a post type that the feed no longer reads is kept and not checked;
 * - a list cannot be the input of a lookup table, nor of a condition that compares texts (`equals`,
 *   `not_equals`, `one_of`);
 * - an option turned on (a value that is not empty: `false`, `0`, `"0"` and `""` are off) must be accepted by a
 *   source of the row; the options of a row with an entry that is not checked are not checked either.
 * - the saved form of the mapping (`\uXXXX` for the characters that are not ASCII, as `wp_json_encode()`
 *   writes it) must stay within `Mapping::MAX_BYTES`, otherwise the meta sanitizer would store nothing.
 *
 * Errors use the shape of `Mapping_Validator::validate()`: codes `unknown` (source not in the catalog), `list`
 * and `not_allowed` (option), on the field that uses the source, once per field.
 *
 * Pure logic: no WordPress function is called.
 */
final class Catalog_Validator {

	/**
	 * Operators of a condition that compare texts, so they cannot read a list.
	 */
	private const TEXT_OPERATORS = array( 'equals', 'not_equals', 'one_of' );

	/**
	 * Checks the JSON text of a mapping for a feed: the preview and the save of the feed editor.
	 *
	 * @param mixed    $text    JSON text received.
	 * @param Catalog  $catalog Catalog of the post types of the feed.
	 * @param callable $encode  Encoder of the saved form (`wp_json_encode`), so this class calls no WordPress
	 *                          function.
	 * @return array{mapping: array{version: int, rows: list<array<string, mixed>>}|null, errors: list<array{row: int, field: string, code: string}>}
	 *         The canonical mapping with no errors, or no mapping and every error found (`json` for a text
	 *         that cannot be read, `too_large` for a text or a saved form over the limit).
	 *
	 * @phpstan-param callable(mixed): (string|false) $encode
	 */
	public static function validate_text( $text, Catalog $catalog, callable $encode ): array {
		$too_large = is_string( $text ) && strlen( $text ) > Mapping::MAX_BYTES;
		$decoded   = Mapping::decode( $text );
		if ( null === $decoded ) {
			return self::failure( array( self::mapping_error( $too_large ? 'too_large' : 'json' ) ) );
		}

		$errors = self::validate( $decoded, $catalog );
		if ( array() !== $errors ) {
			return self::failure( $errors );
		}

		$mapping = Mapping_Validator::normalize( $decoded );
		$saved   = $encode( $mapping );
		if ( ! is_string( $saved ) || strlen( $saved ) > Mapping::MAX_BYTES ) {
			return self::failure( array( self::mapping_error( 'too_large' ) ) );
		}

		return array(
			'mapping' => $mapping,
			'errors'  => array(),
		);
	}

	/**
	 * Result of a refused text.
	 *
	 * @param list<array{row: int, field: string, code: string}> $errors Errors.
	 * @return array{mapping: null, errors: list<array{row: int, field: string, code: string}>}
	 */
	private static function failure( array $errors ): array {
		return array(
			'mapping' => null,
			'errors'  => $errors,
		);
	}

	/**
	 * Error of the whole mapping.
	 *
	 * @param string $code Error code.
	 * @return array{row: int, field: string, code: string}
	 */
	private static function mapping_error( string $code ): array {
		return array(
			'row'   => Mapping_Validator::MAPPING_ROW,
			'field' => '',
			'code'  => $code,
		);
	}

	/**
	 * Checks a decoded mapping for a feed.
	 *
	 * @param mixed   $mapping Decoded mapping (see `Mapping::decode()`).
	 * @param Catalog $catalog Catalog of the post types of the feed.
	 * @return list<array{row: int, field: string, code: string}> Every error found, in order of row; an
	 *                                                              empty list for a valid mapping.
	 */
	public static function validate( $mapping, Catalog $catalog ): array {
		$errors = Mapping_Validator::validate( $mapping );
		if ( array() !== $errors || ! is_array( $mapping ) ) {
			return $errors;
		}

		foreach ( Mapping_Validator::normalize( $mapping )['rows'] as $index => $row ) {
			$found = array();
			foreach ( self::row_errors( $row, $catalog ) as $error ) {
				$found[ $error[0] . ':' . $error[1] ] = $error;
			}
			foreach ( $found as $error ) {
				$errors[] = array(
					'row'   => $index,
					'field' => $error[0],
					'code'  => $error[1],
				);
			}
		}

		return $errors;
	}

	/**
	 * Errors of one row of a valid mapping.
	 *
	 * @param array<string, mixed> $row     Row in canonical form.
	 * @param Catalog              $catalog Catalog of the feed.
	 * @return list<array{0: string, 1: string}> Field and code of every error, in order.
	 */
	private static function row_errors( array $row, Catalog $catalog ): array {
		$errors = array();

		foreach ( self::scoped_sources( $row ) as list( $field, $scope, $source ) ) {
			$types = self::declared_types( $source, $scope, $catalog );
			if ( null === $types ) {
				continue;
			}

			if ( array() === $types ) {
				$errors[] = array( $field, 'unknown' );
			} elseif ( 'inputs' === $field && in_array( 'list', $types, true ) && self::compares_texts( $row ) ) {
				$errors[] = array( $field, 'list' );
			}
		}

		if ( 'field' === $row['kind'] && ! self::options_accepted( $row, $catalog ) ) {
			$errors[] = array( 'options', 'not_allowed' );
		}

		return $errors;
	}

	/**
	 * Sources a row reads, with the field that holds each one and its post type: the key of a map of post
	 * types, or `*` for the outputs, which apply to every post type.
	 *
	 * @param array<string, mixed> $row Row in canonical form.
	 * @return list<array{0: string, 1: string, 2: string}> Field, post type (or `*`) and source.
	 */
	private static function scoped_sources( array $row ): array {
		$sources = array();

		$maps = array(
			'field'     => 'sources',
			'template'  => 'templates',
			'lookup'    => 'inputs',
			'condition' => 'inputs',
		);
		$name = $maps[ $row['kind'] ] ?? '';
		foreach ( '' === $name ? array() : (array) $row[ $name ] as $scope => $entry ) {
			$entries = match ( $row['kind'] ) {
				'field'    => (array) $entry,
				'template' => Template::sources( Template::parse( $entry )['parts'] ),
				default    => array( $entry ),
			};
			foreach ( $entries as $source ) {
				$sources[] = array( $name, (string) $scope, (string) $source );
			}
		}

		$outputs = array();
		if ( 'lookup' === $row['kind'] ) {
			foreach ( (array) $row['cases'] as $case ) {
				$outputs[] = array( 'cases', $case['output'] ?? null );
			}
			$outputs[] = array( 'default', $row['default'] ?? null );
		} elseif ( 'condition' === $row['kind'] ) {
			$outputs[] = array( 'then', $row['then'] ?? null );
			$outputs[] = array( 'else', $row['else'] ?? null );
		}
		foreach ( $outputs as list( $field, $output ) ) {
			if ( is_array( $output ) && is_string( $output['source'] ?? null ) ) {
				$sources[] = array( $field, '*', $output['source'] );
			}
		}

		return $sources;
	}

	/**
	 * Declared types of a source for a post type of the feed, or for any of them with `*`.
	 *
	 * @param string  $source  Source identifier.
	 * @param string  $scope   Post type, or `*`.
	 * @param Catalog $catalog Catalog of the feed.
	 * @return list<string>|null The types (empty: the source does not exist), or null when the post type is
	 *                           not read by the feed and the source is not checked.
	 */
	private static function declared_types( string $source, string $scope, Catalog $catalog ): ?array {
		if ( '*' !== $scope && ! in_array( $scope, $catalog->post_types(), true ) ) {
			return null;
		}

		$types = array();
		foreach ( $catalog->sources() as $candidate ) {
			if ( $candidate->id === $source && ( '*' === $scope || $candidate->is_common() || $candidate->post_type === $scope ) ) {
				$types[] = $candidate->type;
			}
		}

		return $types;
	}

	/**
	 * Whether the inputs of a row are compared with texts: a lookup table, or a condition with a text check.
	 *
	 * @param array<string, mixed> $row Row in canonical form.
	 * @return bool
	 */
	private static function compares_texts( array $row ): bool {
		return 'lookup' === $row['kind'] || ( 'condition' === $row['kind'] && in_array( $row['operator'], self::TEXT_OPERATORS, true ) );
	}

	/**
	 * Whether every option turned on in a `field` row is accepted by one of its sources.
	 *
	 * @param array<string, mixed> $row     Row in canonical form.
	 * @param Catalog              $catalog Catalog of the feed.
	 * @return bool
	 */
	private static function options_accepted( array $row, Catalog $catalog ): bool {
		$accepted = array();
		foreach ( self::scoped_sources( $row ) as list( , $scope, $source ) ) {
			if ( null === self::declared_types( $source, $scope, $catalog ) ) {
				// An entry for a post type the feed no longer reads is not checked: its options are not either.
				return true;
			}
			foreach ( $catalog->sources() as $candidate ) {
				if ( $candidate->id === $source ) {
					$accepted = array_merge( $accepted, $candidate->options );
				}
			}
		}

		foreach ( (array) ( $row['options'] ?? array() ) as $name => $value ) {
			if ( ! empty( $value ) && ! in_array( (string) $name, $accepted, true ) ) {
				return false;
			}
		}

		return true;
	}
}

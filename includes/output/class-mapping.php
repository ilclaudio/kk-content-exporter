<?php
/**
 * Format of the mapping of a feed: vocabulary, limits and reading.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * The mapping says which JSON keys a feed exports and where each value comes from.
 *
 * It is stored as JSON text with a version number:
 *
 *     { "version": 1, "rows": [ { "key": "title", "kind": "field", "type": "string", "missing": "omit",
 *                                  "sources": { "*": [ "core.title" ] } } ] }
 *
 * A row is "output key + value rule": `kind` says which rule it is, `type` the expected type of the
 * output and `missing` what to do when the rule gives no value. This class holds the vocabulary and
 * reads the text; `Mapping_Validator` checks a mapping and gives its canonical form. Pure logic: no
 * WordPress function is called, so everything here is tested without WordPress.
 */
final class Mapping {

	/**
	 * Version of the format written today. A mapping with another version is refused.
	 */
	public const VERSION = 1;

	/**
	 * Maximum number of rows of a mapping.
	 */
	public const MAX_ROWS = 100;

	/**
	 * Maximum length of the output key of a row.
	 */
	public const KEY_MAX_LENGTH = 64;

	/**
	 * Maximum size of the JSON text of a mapping, in bytes: of the submitted text and of the stored one.
	 */
	public const MAX_BYTES = 262144;

	/**
	 * Maximum nesting of the JSON text: the real mapping needs six levels.
	 */
	public const MAX_DEPTH = 8;

	/**
	 * Rule kinds that exist today. `template`, `lookup` and `condition` come with phase 4.
	 */
	public const KINDS = array( 'field', 'fixed' );

	/**
	 * Expected types of the output of a row. `auto` uses the declared type of the source.
	 */
	public const TYPES = array( 'auto', 'string', 'html', 'number', 'boolean', 'datetime', 'url', 'list' );

	/**
	 * What to do when a rule gives no value: leave the key out (default) or write `null`.
	 */
	public const MISSING_POLICIES = array( 'omit', 'null' );

	/**
	 * Groups of source identifiers (`group.name`): the first part says who provides the value.
	 */
	public const SOURCE_GROUPS = array( 'core', 'meta', 'taxonomy', 'image', 'language', 'acf', 'cmb2' );

	/**
	 * A mapping with no rows, in the current version.
	 *
	 * @return array{version: int, rows: list<array<string, mixed>>}
	 */
	public static function blank(): array {
		return array(
			'version' => self::VERSION,
			'rows'    => array(),
		);
	}

	/**
	 * Reads the JSON text of a mapping.
	 *
	 * Only the text is read: whether the content is a valid mapping is decided by
	 * `Mapping_Validator`. A JSON object `{}` and a list `[]` both give an empty array.
	 *
	 * @param mixed $json Stored or submitted text.
	 * @return array<mixed>|null The decoded JSON object or list, or null when the value is not a string,
	 *                           is empty, is larger than the limit, is nested too deep, is not valid
	 *                           JSON or is a scalar.
	 */
	public static function decode( $json ): ?array {
		if ( ! is_string( $json ) || '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			return null;
		}

		$data = json_decode( $json, true, self::MAX_DEPTH );

		return is_array( $data ) ? $data : null;
	}

	/**
	 * Proposes an output key for a source: the name of the source, made valid.
	 *
	 * `core.title` gives `title`, `meta._price` gives `price`. Characters that are not allowed in a key
	 * become `_`, underscores, dots and dashes at the ends are removed, and the result is cut to the
	 * maximum length.
	 *
	 * @param string $source Source identifier (`group.name`).
	 * @return string The proposed key, or an empty string when none can be proposed (no group, no name,
	 *                or a name made only of digits).
	 */
	public static function suggest_key( string $source ): string {
		$parts = explode( '.', $source, 2 );
		if ( 2 !== count( $parts ) ) {
			return '';
		}

		$key = preg_replace( '/[^A-Za-z0-9_.-]/', '_', $parts[1] );
		$key = trim( substr( trim( (string) $key, '_.-' ), 0, self::KEY_MAX_LENGTH ), '_.-' );

		if ( '' === $key || ctype_digit( $key ) ) {
			return '';
		}

		return $key;
	}
}

<?php
/**
 * Normalization of the exported values to the output types of the mapping.
 *
 * @package KKCE
 */

namespace KKCE\Output;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a value to the output type of its row, so that a key always has the same JSON type.
 *
 * - `string`: plain text, without tags and with the HTML entities decoded (WordPress may store a title
 *   as `A &amp; B`); numbers as text, booleans as `true`/`false`, lists joined with `, `;
 * - `html`: text and numbers as they are;
 * - `number`: integers and decimals; numeric text is converted, a decimal comma is not guessed;
 * - `boolean`: booleans, `1`/`0` and the words `true`, `false`, `yes`, `no`, `on`, `off`;
 * - `datetime`: a date without time (`YYYY-MM-DD`) stays as it is; a date and time becomes UTC ISO 8601
 *   (`YYYY-MM-DDTHH:MM:SSZ`); a time without offset is read as UTC (the core dates are GMT), an offset is
 *   converted, a Unix timestamp (integer) is accepted;
 * - `url`: absolute `http` and `https` addresses; a path that starts with `/` is made absolute with the
 *   origin of the site; any other scheme (`javascript:`, `mailto:`) is refused;
 * - `list`: a list of plain values; a plain value becomes a list of one.
 *
 * `0`, `"0"` and `false` are values. An empty value (`""` or `[]`) stays empty for text, HTML and lists,
 * and gives no value, without error, for the other types. A value that cannot be converted gives no value
 * and the error `not_convertible`. Pure logic: no WordPress function is called.
 */
final class Normalizer {

	/**
	 * Error: the value cannot be converted to the type of the row.
	 */
	public const ERROR_NOT_CONVERTIBLE = 'not_convertible';

	/**
	 * Words accepted as booleans.
	 */
	private const BOOLEANS = array(
		'1'     => true,
		'0'     => false,
		'true'  => true,
		'false' => false,
		'yes'   => true,
		'no'    => false,
		'on'    => true,
		'off'   => false,
	);

	/**
	 * Normalizes a value.
	 *
	 * @param mixed  $value  Value given by the rule.
	 * @param string $type   Output type (`auto` must be resolved by the caller).
	 * @param string $origin Origin of the site (`https://example.org`), to make root-relative URLs absolute.
	 * @return array{present: bool, value: mixed, error: string} The value, or no value with an error code
	 *                                                           (empty when the value was simply empty).
	 */
	public static function normalize( $value, string $type, string $origin = '' ): array {
		if ( null === $value ) {
			return self::absent( '' );
		}

		if ( '' === $value || array() === $value ) {
			return match ( $type ) {
				'string', 'html' => self::present( '' ),
				'list'           => self::present( array() ),
				default          => self::absent( '' ),
			};
		}

		$result = match ( $type ) {
			'string'   => self::to_string( $value ),
			'html'     => self::to_html( $value ),
			'number'   => self::to_number( $value ),
			'boolean'  => self::to_boolean( $value ),
			'datetime' => self::to_datetime( $value ),
			'url'      => self::to_url( $value, $origin ),
			'list'     => self::to_list( $value ),
			default    => null,
		};

		return null === $result ? self::absent( self::ERROR_NOT_CONVERTIBLE ) : self::present( $result[0] );
	}

	/**
	 * Type of a row with `auto`: the declared type shared by all its sources, otherwise text. A source of
	 * unknown type counts as text.
	 *
	 * @param array<int, string|null> $types Declared types of the sources of the row.
	 * @return string
	 */
	public static function common_type( array $types ): string {
		$unique = array_unique( array_map( static fn( $type ): string => $type ?? 'string', $types ) );

		return 1 === count( $unique ) ? (string) reset( $unique ) : 'string';
	}

	/**
	 * JSON type of a fixed value.
	 *
	 * @param mixed $value Fixed value (text, number or boolean).
	 * @return string
	 */
	public static function type_of_value( $value ): string {
		if ( is_bool( $value ) ) {
			return 'boolean';
		}

		return is_int( $value ) || is_float( $value ) ? 'number' : 'string';
	}

	/**
	 * Plain text.
	 *
	 * @param mixed $value Value.
	 * @return array{0: string}|null
	 */
	private static function to_string( $value ): ?array {
		if ( is_bool( $value ) ) {
			return array( $value ? 'true' : 'false' );
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return array( (string) $value );
		}

		if ( is_string( $value ) ) {
			return array( html_entity_decode( self::strip_tags( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		}

		if ( is_array( $value ) && array_is_list( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				$text = is_scalar( $item ) ? self::to_string( $item ) : null;
				if ( null === $text ) {
					return null;
				}
				$parts[] = $text[0];
			}

			return array( implode( ', ', $parts ) );
		}

		return null;
	}

	/**
	 * Text without HTML tags, as `wp_strip_all_tags()` does: `script` and `style` elements go with their
	 * content, then every tag. A `<` that does not open a tag (`a <3 b`) stays.
	 *
	 * @param string $html Text.
	 * @return string
	 */
	private static function strip_tags( string $html ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\1>@si', '', $html );

		// A tag starts with a letter, not whitespace or a comparison operand. Quoted attributes may
		// themselves contain `>`; comments are removed as a whole.
		return (string) preg_replace( '~<!--[\s\S]*?(?:-->|$)|</?[a-z][a-z0-9:-]*(?=[\s/>])(?:[^\s\'"<>]|\s|"[^"]*"|\'[^\']*\')*>~i', '', $text );
	}

	/**
	 * HTML text.
	 *
	 * @param mixed $value Value.
	 * @return array{0: string}|null
	 */
	private static function to_html( $value ): ?array {
		if ( is_string( $value ) ) {
			return array( $value );
		}

		return is_int( $value ) || is_float( $value ) ? array( (string) $value ) : null;
	}

	/**
	 * Number.
	 *
	 * @param mixed $value Value.
	 * @return array{0: int|float}|null
	 */
	private static function to_number( $value ): ?array {
		if ( is_int( $value ) ) {
			return array( $value );
		}

		if ( is_float( $value ) ) {
			return is_finite( $value ) ? array( $value ) : null;
		}

		if ( ! is_string( $value ) || ! is_numeric( trim( $value ) ) ) {
			return null;
		}

		$text    = trim( $value );
		$integer = filter_var( $text, FILTER_VALIDATE_INT );
		if ( false !== $integer ) {
			return array( $integer );
		}

		if ( ctype_digit( ltrim( $text, '+-' ) ) ) {
			// Validate again without leading zeros; a cast would clamp an out-of-range integer.
			$digits  = ltrim( ltrim( $text, '+-' ), '0' );
			$signed  = ( str_starts_with( $text, '-' ) ? '-' : '' ) . ( '' === $digits ? '0' : $digits );
			$integer = filter_var( $signed, FILTER_VALIDATE_INT );

			return false === $integer ? null : array( $integer );
		}

		$number = (float) $text;
		return is_finite( $number ) ? array( $number ) : null;
	}

	/**
	 * Boolean.
	 *
	 * @param mixed $value Value.
	 * @return array{0: bool}|null
	 */
	private static function to_boolean( $value ): ?array {
		if ( is_bool( $value ) ) {
			return array( $value );
		}

		if ( 1 === $value || 0 === $value ) {
			return array( 1 === $value );
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$word = strtolower( trim( $value ) );

		return array_key_exists( $word, self::BOOLEANS ) ? array( self::BOOLEANS[ $word ] ) : null;
	}

	/**
	 * Date, or date and time in UTC.
	 *
	 * @param mixed $value Value.
	 * @return array{0: string}|null
	 */
	private static function to_datetime( $value ): ?array {
		$utc = new DateTimeZone( 'UTC' );

		if ( is_int( $value ) ) {
			return array( ( new DateTimeImmutable( '@' . $value ) )->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ) );
		}

		if ( ! is_string( $value ) || str_contains( $value, "\0" ) ) {
			return null;
		}

		$text = trim( $value );

		$date = self::parse( '!Y-m-d', $text, $utc );
		if ( null !== $date ) {
			return array( $date->format( 'Y-m-d' ) );
		}

		foreach ( array( '!Y-m-d H:i:s', '!Y-m-d\TH:i:s', '!Y-m-d\TH:i:sP', '!Y-m-d\TH:i:s\Z' ) as $format ) {
			$moment = self::parse( $format, $text, $utc );
			if ( null !== $moment ) {
				return array( $moment->setTimezone( $utc )->format( 'Y-m-d\TH:i:s\Z' ) );
			}
		}

		return null;
	}

	/**
	 * Strict reading of a date: the text must match the format exactly, with a real date and time.
	 *
	 * @param string       $format Format of `DateTimeImmutable::createFromFormat()`.
	 * @param string       $text   Text.
	 * @param DateTimeZone $utc    Time zone used when the text has no offset.
	 * @return DateTimeImmutable|null
	 */
	private static function parse( string $format, string $text, DateTimeZone $utc ): ?DateTimeImmutable {
		$date = DateTimeImmutable::createFromFormat( $format, $text, $utc );
		if ( false === $date ) {
			return null;
		}

		// Refuses overflows that PHP would roll over (February 30, hour 25) and the zero date: the date
		// written back with the same format must be the text read.
		if ( $date->format( ltrim( $format, '!' ) ) !== $text || (int) $date->format( 'Y' ) < 1 ) {
			return null;
		}

		return $date;
	}

	/**
	 * Absolute http or https URL.
	 *
	 * @param mixed  $value  Value.
	 * @param string $origin Origin of the site.
	 * @return array{0: string}|null
	 */
	private static function to_url( $value, string $origin ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$url = trim( $value );
		if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
			if ( '' === $origin ) {
				return null;
			}
			$url = rtrim( $origin, '/' ) . $url;
		}

		if ( 1 !== preg_match( '#\Ahttps?://#i', $url ) || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return null;
		}

		return array( $url );
	}

	/**
	 * List of plain values.
	 *
	 * @param mixed $value Value.
	 * @return array{0: list<int|float|string|bool>}|null
	 */
	private static function to_list( $value ): ?array {
		if ( is_scalar( $value ) ) {
			return array( array( $value ) );
		}

		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}

		foreach ( $value as $item ) {
			if ( ! is_scalar( $item ) ) {
				return null;
			}
		}

		return array( $value );
	}

	/**
	 * Result with a value.
	 *
	 * @param mixed $value Value.
	 * @return array{present: bool, value: mixed, error: string}
	 */
	private static function present( $value ): array {
		return array(
			'present' => true,
			'value'   => $value,
			'error'   => '',
		);
	}

	/**
	 * Result without a value.
	 *
	 * @param string $error Error code, empty when the value was only empty.
	 * @return array{present: bool, value: mixed, error: string}
	 */
	private static function absent( string $error ): array {
		return array(
			'present' => false,
			'value'   => null,
			'error'   => $error,
		);
	}
}

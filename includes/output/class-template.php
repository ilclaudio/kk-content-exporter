<?php
/**
 * Reading of the text of a template rule.
 *
 * @package KKCE
 */

namespace KKCE\Output;

defined( 'ABSPATH' ) || exit;

/**
 * The text of a `template` row: text with placeholders of the catalog sources.
 *
 * Syntax (decisions of 2026-11-08 and 2026-11-11):
 * - `{core.title}`: the value of a source, written with its catalog identifier (`group.name`);
 * - `{meta.place|meta.city|"to be decided"}`: alternatives, separated by `|`; the first one with a value is
 *   used. An alternative is a source or a literal text between double quotes, which cannot contain `"`, `|`,
 *   `{` or `}`. Spaces around the alternatives are ignored;
 * - `{{` and `}}`: a literal brace;
 * - at most `MAX_LENGTH` characters and `MAX_PLACEHOLDERS` placeholders.
 *
 * Every part has the same shape: a text part has no alternatives, a placeholder has an empty text.
 *
 * Pure logic: no WordPress function is called. The text is read into parts; whether the source groups are
 * known and whether a source is sensitive is checked by `Mapping_Validator`, the values are computed by
 * `Rule_Evaluator`.
 *
 * @phpstan-type Alternative array{source: string}|array{literal: string}
 * @phpstan-type Part array{text: string, alternatives: list<Alternative>}
 */
final class Template {

	/**
	 * Most characters of a template.
	 */
	public const MAX_LENGTH = 1000;

	/**
	 * Most placeholders of a template.
	 */
	public const MAX_PLACEHOLDERS = 20;

	/**
	 * Format of a literal alternative.
	 */
	private const LITERAL_PATTERN = '/\A"([^"|{}]*)"\z/';

	/**
	 * Reads a template.
	 *
	 * @param mixed $template Text of the template.
	 * @return array{parts: list<array{text: string, alternatives: list<array<string, string>>}>, error: string} The parts in order, and an empty error;
	 *                                                                 or no parts and the error code (`format`,
	 *                                                                 `too_long`, `too_many`).
	 *
	 * @phpstan-return array{parts: list<Part>, error: string}
	 */
	public static function parse( $template ): array {
		if ( ! is_string( $template ) || 1 !== preg_match( '//u', $template ) ) {
			return self::failure( 'format' );
		}

		if ( mb_strlen( $template, 'UTF-8' ) > self::MAX_LENGTH ) {
			return self::failure( 'too_long' );
		}

		$parts        = array();
		$text         = '';
		$placeholders = 0;
		$length       = strlen( $template );
		$position     = 0;

		while ( $position < $length ) {
			$character = $template[ $position ];
			$next      = $template[ $position + 1 ] ?? '';

			if ( ( '{' === $character || '}' === $character ) && $next === $character ) {
				$text     .= $character;
				$position += 2;
				continue;
			}

			if ( '}' === $character ) {
				return self::failure( 'format' );
			}

			if ( '{' !== $character ) {
				$text .= $character;
				++$position;
				continue;
			}

			$end = strpos( $template, '}', $position + 1 );
			if ( false === $end ) {
				return self::failure( 'format' );
			}

			$alternatives = self::alternatives( substr( $template, $position + 1, $end - $position - 1 ) );
			if ( null === $alternatives ) {
				return self::failure( 'format' );
			}

			if ( ++$placeholders > self::MAX_PLACEHOLDERS ) {
				return self::failure( 'too_many' );
			}

			if ( '' !== $text ) {
				$parts[] = self::text_part( $text );
				$text    = '';
			}
			$parts[]  = self::placeholder_part( $alternatives );
			$position = $end + 1;
		}

		if ( '' !== $text ) {
			$parts[] = self::text_part( $text );
		}

		return array(
			'parts' => $parts,
			'error' => '',
		);
	}

	/**
	 * Sources of the placeholders, once each, in order of appearance.
	 *
	 * @param array<mixed> $parts Parts given by `parse()`.
	 * @return list<string>
	 */
	public static function sources( array $parts ): array {
		$sources = array();

		foreach ( $parts as $part ) {
			foreach ( ( is_array( $part ) && is_array( $part['alternatives'] ?? null ) ) ? $part['alternatives'] : array() as $alternative ) {
				if ( is_array( $alternative ) && isset( $alternative['source'] ) && is_string( $alternative['source'] ) && ! in_array( $alternative['source'], $sources, true ) ) {
					$sources[] = $alternative['source'];
				}
			}
		}

		return $sources;
	}

	/**
	 * Alternatives of a placeholder.
	 *
	 * @param string $inside Text between the braces.
	 * @return list<array<string, string>>|null The alternatives, or null when the placeholder is malformed.
	 *
	 * @phpstan-return list<Alternative>|null
	 */
	private static function alternatives( string $inside ): ?array {
		if ( str_contains( $inside, '{' ) ) {
			return null;
		}

		$alternatives = array();
		foreach ( explode( '|', $inside ) as $alternative ) {
			$alternative = trim( $alternative );

			if ( 1 === preg_match( self::LITERAL_PATTERN, $alternative, $match ) ) {
				$alternatives[] = array( 'literal' => $match[1] );
			} elseif ( 1 === preg_match( Mapping::SOURCE_PATTERN, $alternative ) ) {
				$alternatives[] = array( 'source' => $alternative );
			} else {
				return null;
			}
		}

		return $alternatives;
	}

	/**
	 * A part of plain text.
	 *
	 * @param string $text Text.
	 * @return array{text: string, alternatives: list<array<string, string>>}
	 *
	 * @phpstan-return Part
	 */
	private static function text_part( string $text ): array {
		return array(
			'text'         => $text,
			'alternatives' => array(),
		);
	}

	/**
	 * A placeholder part.
	 *
	 * @param array<int, array<string, string>> $alternatives Alternatives, in order.
	 * @return array{text: string, alternatives: list<array<string, string>>}
	 *
	 * @phpstan-param list<Alternative> $alternatives
	 * @phpstan-return Part
	 */
	private static function placeholder_part( array $alternatives ): array {
		return array(
			'text'         => '',
			'alternatives' => $alternatives,
		);
	}

	/**
	 * A template that cannot be used.
	 *
	 * @param string $error Error code.
	 * @return array{parts: list<array{text: string, alternatives: list<array<string, string>>}>, error: string}
	 *
	 * @phpstan-return array{parts: list<Part>, error: string}
	 */
	private static function failure( string $error ): array {
		return array(
			'parts' => array(),
			'error' => $error,
		);
	}
}

<?php
/**
 * Provider of the language of the site and of the items.
 *
 * @package KKCE
 */

namespace KKCE\Sources;

use WP_Post;
use WP_Term;

defined( 'ABSPATH' ) || exit;

/**
 * Declares the language sources and reads their values.
 *
 * - `language.site`: the language of the site chosen in the General settings, as a W3C code (`it-IT`), always offered.
 * - `language.code`, `language.locale`, `language.w3c`: the language of the item in three formats (`it`, `it_IT`,
 *   `it-IT`), three sources rather than an option of the row so that they work in every rule (templates, tables,
 *   conditions), decision of 2026-11-24.
 * - `language.translation_group`: an identifier shared by the translations of the same content, so that an importer
 *   can group them.
 *
 * The item sources come from Polylang, the only multilingual plugin supported (WPML is not): they are offered only
 * when Polylang is active and translates at least one post type of the feed, and they give null for an item without
 * a language (a post type Polylang does not translate, or Polylang turned off after the mapping was saved: the feed
 * keeps working). This is the only class that calls Polylang, always behind `function_exists()`. Its values are read
 * from the term cache, which the query of a page fills for every item at once (Polylang stores languages and
 * translation groups as terms). It also gives the endpoint what its optional `lang` parameter needs: the languages
 * of the site (`languages()`) and the term of a language with the post types Polylang does not translate
 * (`language_filter()`).
 */
final class Language_Provider {

	/**
	 * Fields of a Polylang language for the item sources: source => field of `pll_get_post_language()`.
	 */
	private const ITEM_FIELDS = array(
		'language.code'   => 'slug',
		'language.locale' => 'locale',
		'language.w3c'    => 'w3c',
	);

	/**
	 * Taxonomy in which Polylang groups the translations of a post.
	 */
	private const TRANSLATION_TAXONOMY = 'post_translations';

	/**
	 * Declared types of the language sources: all texts.
	 *
	 * @return array<string, string> Identifier => declared type.
	 */
	public static function types(): array {
		return array(
			'language.site'              => 'string',
			'language.code'              => 'string',
			'language.locale'            => 'string',
			'language.w3c'               => 'string',
			'language.translation_group' => 'string',
		);
	}

	/**
	 * Language sources for the post types of a feed, common to all of them.
	 *
	 * @param string[] $post_types Post types of the feed.
	 * @return list<Source>
	 *
	 * @phpstan-param list<string> $post_types
	 */
	public static function sources( array $post_types ): array {
		$sources = array( new Source( 'language.site', __( 'Site language', 'kk-content-exporter' ), '', 'string' ) );
		if ( ! self::translates_any( $post_types ) ) {
			return $sources;
		}

		$labels = array(
			'language.code'              => __( 'Language code (Polylang)', 'kk-content-exporter' ),
			'language.locale'            => __( 'Language locale (Polylang)', 'kk-content-exporter' ),
			'language.w3c'               => __( 'Language W3C code (Polylang)', 'kk-content-exporter' ),
			'language.translation_group' => __( 'Translation group (Polylang)', 'kk-content-exporter' ),
		);
		foreach ( $labels as $id => $label ) {
			$sources[] = new Source( $id, $label, '', 'string' );
		}

		return $sources;
	}

	/**
	 * Value of a language source for a post.
	 *
	 * @param WP_Post $post Post.
	 * @param string  $id   Source identifier.
	 * @return string|null The value, or null when the item has none, Polylang is not active, or the identifier is not
	 *                     a language source.
	 */
	public static function value( WP_Post $post, string $id ): ?string {
		if ( 'language.site' === $id ) {
			return self::site_language();
		}
		if ( 'language.translation_group' === $id ) {
			return self::translation_group( $post );
		}
		if ( ! isset( self::ITEM_FIELDS[ $id ] ) || ! function_exists( 'pll_get_post_language' ) ) {
			return null;
		}

		$value = pll_get_post_language( $post->ID, self::ITEM_FIELDS[ $id ] );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Languages of the site, for the `lang` parameter of a feed.
	 *
	 * @return list<string>|null Slugs of the Polylang languages (`it`, `en`), or null when Polylang is not active.
	 */
	public static function languages(): ?array {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return null;
		}

		return array_values( array_filter( pll_languages_list(), static fn( string $slug ): bool => '' !== $slug ) );
	}

	/**
	 * What the query of a feed needs to keep only the items of one language: the term of the language, and the post
	 * types of the feed that Polylang does not translate (their items have no language and are kept).
	 *
	 * @param string   $language   Slug of a language of `languages()`.
	 * @param string[] $post_types Post types of the feed.
	 * @return array{term_taxonomy_id: int, untranslated: list<string>}|null Null without Polylang or for an unknown
	 *                                                                         language.
	 *
	 * @phpstan-param list<string> $post_types
	 */
	public static function language_filter( string $language, array $post_types ): ?array {
		if ( ! in_array( $language, self::languages() ?? array(), true ) ) {
			return null;
		}

		$term = get_term_by( 'slug', $language, 'language' );
		if ( ! $term instanceof WP_Term ) {
			return null;
		}

		$untranslated = array();
		foreach ( $post_types as $post_type ) {
			if ( ! pll_is_translated_post_type( $post_type ) ) {
				$untranslated[] = $post_type;
			}
		}

		return array(
			'term_taxonomy_id' => $term->term_taxonomy_id,
			'untranslated'     => $untranslated,
		);
	}

	/**
	 * Whether Polylang is active and translates at least one of the post types.
	 *
	 * @param string[] $post_types Post types.
	 * @return bool
	 */
	private static function translates_any( array $post_types ): bool {
		if ( ! function_exists( 'pll_get_post_language' ) || ! function_exists( 'pll_is_translated_post_type' ) ) {
			return false;
		}

		foreach ( $post_types as $post_type ) {
			if ( pll_is_translated_post_type( $post_type ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Language of the site, as chosen in the General settings, in the W3C format of `get_bloginfo( 'language' )`.
	 * Read from the setting rather than from `get_locale()`, which multilingual plugins change during a request.
	 *
	 * @return string For example `it-IT`; `en-US` when no language is chosen (the default of WordPress).
	 */
	private static function site_language(): string {
		$locale = get_option( 'WPLANG' );
		if ( ! is_string( $locale ) || '' === $locale ) {
			$locale = 'en_US';
		}

		return str_replace( '_', '-', $locale );
	}

	/**
	 * Identifier of the group of the translations of a post: the name of its Polylang `post_translations` term.
	 *
	 * @param WP_Post $post Post.
	 * @return string|null Null without Polylang or when Polylang never grouped the post with a translation.
	 */
	private static function translation_group( WP_Post $post ): ?string {
		if ( ! function_exists( 'pll_get_post_language' ) || ! taxonomy_exists( self::TRANSLATION_TAXONOMY ) ) {
			return null;
		}

		$terms = get_the_terms( $post, self::TRANSLATION_TAXONOMY );
		if ( ! is_array( $terms ) || array() === $terms ) {
			return null;
		}

		$name = reset( $terms )->name;

		return '' === $name ? null : $name;
	}
}

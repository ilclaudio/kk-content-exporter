<?php
/**
 * PHPStan stubs of the Polylang functions the plugin calls (read by `scanFiles`, never executed).
 *
 * Copied from the signatures and documentation of Polylang 3.9.2 (`src/api.php`), the version of the integration
 * tests (`.wp-env.inttest.json`). Written by hand because `wpsyntex/polylang-stubs` has no 3.9 release (latest 3.7.3
 * on 2026-11-24). Update them with that version: add here only functions the code calls, always behind a
 * `function_exists()` check in `Sources\Language_Provider`.
 *
 * @package KKCE
 */

/**
 * Returns true if Polylang manages languages and translations for this post type.
 *
 * @param string $post_type Post type name.
 * @return bool
 */
function pll_is_translated_post_type( $post_type ) {}

/**
 * Returns the list of available languages.
 *
 * @param array{hide_empty?: bool, hide_default?: bool, fields?: string} $args Optional arguments: `hide_empty`,
 *                                                                              `hide_default`, `fields` (default
 *                                                                              `slug`).
 * @return string[]
 */
function pll_languages_list( $args = array() ) {}

/**
 * Returns the post language. Polylang also accepts `\OBJECT` for the language object: the plugin does not use it,
 * so it is left out of this stub.
 *
 * @param int    $post_id Post ID.
 * @param string $field   Optional, the language field to return, defaults to `'slug'`.
 * @return string|int|bool|list<non-empty-string>|false The requested field, `false` if the post has no language.
 *
 * @phpstan-return ( $field is 'slug' ? non-empty-string : string|int|bool|list<non-empty-string> )|false
 */
function pll_get_post_language( $post_id, $field = 'slug' ) {}

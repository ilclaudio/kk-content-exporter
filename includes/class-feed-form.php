<?php
/**
 * Pure processing of the values submitted in the feed editor.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Decides, for every field of the feed editor, which value is saved and which error is reported.
 *
 * Pure logic: it calls no WordPress function. The editor reads the request, builds the context
 * (suggested slug, allowed post types, current values, a callback that tells whether a slug is used
 * by another feed) and saves the result.
 *
 * Rules:
 * - a field with an invalid value keeps its current value and reports an error; the other fields are
 *   still saved;
 * - the access is never guessed: an unknown value keeps the current access;
 * - a feed can be active only if it has a usable slug and a source post type, otherwise it is saved
 *   disabled. When the submitted slug is refused, the stored slug is kept (never renamed) but it is
 *   checked with the same rules: a stored slug that is malformed, reserved or used by another feed
 *   cannot serve an active feed.
 *
 * @phpstan-type FormInput array{slug: string, post_type: string, access: string, active: bool, per_page: string}
 * @phpstan-type FormValues array{slug: string, post_type: string, access: string, active: bool, per_page: int}
 * @phpstan-type FormContext array{suggested_slug: string, slug_is_taken: callable(string): bool, allowed_post_types: list<string>, current: FormValues}
 */
final class Feed_Form {

	/**
	 * Error: the feed was left disabled because it has no slug or no source post type.
	 */
	public const ERROR_INCOMPLETE = 'incomplete';

	/**
	 * Processes the submitted values.
	 *
	 * @param array<string, mixed> $input   Submitted values.
	 * @param array<string, mixed> $context Suggested slug, a callback that tells whether a slug is used by
	 *                                      another feed, the selectable post types and the current values.
	 * @return array<string, mixed> Values to save (`values`), and the error code of every refused field (`errors`).
	 *
	 * @phpstan-param FormInput   $input
	 * @phpstan-param FormContext $context
	 * @phpstan-return array{values: FormValues, errors: array<string, string>}
	 */
	public static function process( array $input, array $context ): array {
		$current = $context['current'];
		$values  = $current;
		$errors  = array();

		$slug = trim( $input['slug'] );
		if ( '' === $slug ) {
			$slug = $context['suggested_slug'];
		}
		$code = Feed_Validator::validate_slug( $slug, array() );
		if ( Feed_Validator::VALID === $code && $context['slug_is_taken']( $slug ) ) {
			$code = Feed_Validator::ERROR_TAKEN;
		}
		if ( Feed_Validator::VALID === $code ) {
			$values['slug'] = $slug;
			$slug_usable    = true;
		} else {
			$errors['slug'] = $code;
			$slug_usable    = self::is_usable_slug( $current['slug'], $context['slug_is_taken'] );
		}

		if ( '' === $input['post_type'] ) {
			$values['post_type'] = '';
		} else {
			$code = Feed_Validator::validate_post_type( $input['post_type'], $context['allowed_post_types'] );
			if ( Feed_Validator::VALID === $code ) {
				$values['post_type'] = $input['post_type'];
			} else {
				$errors['post_type'] = $code;
			}
		}

		$code = Feed_Validator::validate_access( $input['access'] );
		if ( Feed_Validator::VALID === $code ) {
			$values['access'] = $input['access'];
		} else {
			$errors['access'] = $code;
		}

		$code = Feed_Validator::validate_per_page( $input['per_page'] );
		if ( Feed_Validator::VALID === $code ) {
			$values['per_page'] = Feed_Post_Type::sanitize_per_page( $input['per_page'] );
		} else {
			$errors['per_page'] = $code;
		}

		$values['active'] = false;
		if ( $input['active'] ) {
			if ( $slug_usable && '' !== $values['post_type'] ) {
				$values['active'] = true;
			} else {
				$errors['active'] = self::ERROR_INCOMPLETE;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Whether a stored slug can still serve an active feed: well formed, not reserved and not used by
	 * another feed.
	 *
	 * The stored slug is not always one that the editor accepted: WordPress gives the old slug back
	 * when a feed is restored from the trash (without checking drafts for duplicates), and generates
	 * one from the title when a published post has none.
	 *
	 * @param string                 $slug          Stored slug.
	 * @param callable(string): bool $slug_is_taken Whether another feed uses a slug.
	 * @return bool
	 */
	private static function is_usable_slug( string $slug, callable $slug_is_taken ): bool {
		return Feed_Validator::VALID === Feed_Validator::validate_slug( $slug, array() ) && ! $slug_is_taken( $slug );
	}
}

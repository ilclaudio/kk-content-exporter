<?php
/**
 * Global settings of the plugin.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * The global settings, stored in the single option `kkce_settings`.
 *
 * Same layout as `Feed_Post_Type`: the defaults, the safe reading of the stored value and the
 * processing of the settings form are pure (no WordPress call, tested without mocks); `get()` and
 * `sanitize_option()` are the thin layer that talks to WordPress.
 *
 * A stored value that is present but damaged never opens or deletes anything: the endpoints switch
 * goes off, the authentication goes on and the data removal goes off.
 *
 * @phpstan-type SettingsValues array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool}
 */
final class Settings {

	/**
	 * Name of the option.
	 */
	public const OPTION = 'kkce_settings';

	/**
	 * Default maximum of items per page. There is no upper limit: the administrator can raise it.
	 */
	public const DEFAULT_MAX_PER_PAGE = 100;

	/**
	 * The switches, with the value taken when the stored one is damaged (always the safe side).
	 */
	private const SWITCHES = array(
		'enabled'      => false,
		'require_auth' => true,
		'remove_data'  => false,
	);

	/**
	 * The defaults of the settings.
	 *
	 * @return array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool}
	 */
	public static function defaults(): array {
		return array(
			'enabled'      => true,
			'require_auth' => false,
			'max_per_page' => self::DEFAULT_MAX_PER_PAGE,
			'remove_data'  => false,
		);
	}

	/**
	 * Turns the stored option into complete, valid settings.
	 *
	 * @param mixed $stored Value of the option.
	 * @return array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool}
	 */
	public static function normalize( $stored ): array {
		$values = self::defaults();

		if ( ! is_array( $stored ) ) {
			return $values;
		}

		foreach ( self::SWITCHES as $key => $safe ) {
			if ( array_key_exists( $key, $stored ) ) {
				$values[ $key ] = self::read_switch( $stored[ $key ], $safe );
			}
		}

		if ( array_key_exists( 'max_per_page', $stored ) && Feed_Validator::VALID === Feed_Validator::validate_per_page( $stored['max_per_page'] ) ) {
			$values['max_per_page'] = (int) $stored['max_per_page'];
		}

		return $values;
	}

	/**
	 * Processes the submitted settings form.
	 *
	 * The input is read as a complete form: a checkbox that is not sent is off. A maximum that is not
	 * valid keeps the previous one and is reported; the other values are still saved.
	 *
	 * @param mixed                $input    Submitted values.
	 * @param array<string, mixed> $previous Settings in force before the save.
	 * @return array<string, mixed> Values to save (`values`) and the error code of every refused field (`errors`).
	 *
	 * @phpstan-param SettingsValues $previous
	 * @phpstan-return array{values: SettingsValues, errors: array<string, string>}
	 */
	public static function process( $input, array $previous ): array {
		$values = $previous;
		$errors = array();

		if ( ! is_array( $input ) ) {
			return array(
				'values' => $values,
				'errors' => $errors,
			);
		}

		foreach ( array_keys( self::SWITCHES ) as $key ) {
			$values[ $key ] = isset( $input[ $key ] ) && self::is_checked( $input[ $key ] );
		}

		if ( array_key_exists( 'max_per_page', $input ) ) {
			$code = Feed_Validator::validate_per_page( $input['max_per_page'] );

			if ( Feed_Validator::VALID === $code ) {
				$values['max_per_page'] = (int) $input['max_per_page'];
			} else {
				$errors['max_per_page'] = $code;
			}
		}

		return array(
			'values' => $values,
			'errors' => $errors,
		);
	}

	/**
	 * Current settings, always complete and valid.
	 *
	 * @return array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool}
	 */
	public static function get(): array {
		return self::normalize( get_option( self::OPTION, array() ) );
	}

	/**
	 * Sanitize callback of the option, used by the Settings API and by `update_option()`.
	 *
	 * @param mixed $input Submitted value.
	 * @return array{enabled: bool, require_auth: bool, max_per_page: int, remove_data: bool} The value to store.
	 */
	public static function sanitize_option( $input ): array {
		$result = self::process( $input, self::get() );

		foreach ( $result['errors'] as $field => $code ) {
			add_settings_error( self::OPTION, 'kkce_' . $field, self::error_message( $field, $code ), 'error' );
		}

		return $result['values'];
	}

	/**
	 * Message for the error of a field.
	 *
	 * @param string $field Field name.
	 * @param string $code  Error code.
	 * @return string
	 */
	private static function error_message( string $field, string $code ): string {
		if ( 'max_per_page' === $field && in_array( $code, array( Feed_Validator::ERROR_NOT_INTEGER, Feed_Validator::ERROR_TOO_SMALL ), true ) ) {
			return __( 'The maximum items per page must be a whole number of at least 1. The previous value was kept.', 'kk-content-exporter' );
		}

		return __( 'A setting was not valid and the previous value was kept.', 'kk-content-exporter' );
	}

	/**
	 * Reads a stored switch: a clean boolean is kept, anything else gives the safe value.
	 *
	 * @param mixed $value Stored value.
	 * @param bool  $safe  Value to take when the stored one is damaged.
	 * @return bool
	 */
	private static function read_switch( $value, bool $safe ): bool {
		if ( self::is_checked( $value ) ) {
			return true;
		}

		return in_array( $value, array( false, 0, '0', '' ), true ) ? false : $safe;
	}

	/**
	 * Whether a value stands for a checked box: `true`, `1` or `'1'`.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function is_checked( $value ): bool {
		return true === $value || 1 === $value || '1' === $value;
	}
}

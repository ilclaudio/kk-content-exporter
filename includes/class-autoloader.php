<?php
/**
 * Class autoloader of the plugin.
 *
 * @package KKCE
 */

namespace KKCE;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the classes of the `KKCE` namespace from `includes/`.
 *
 * The mapping follows the WordPress file naming convention: `KKCE\Feed_Repository` lives in
 * `includes/class-feed-repository.php` and `KKCE\Admin\Feed_Editor` in
 * `includes/admin/class-feed-editor.php`. Composer is a development tool only and is not
 * shipped with the plugin, so the plugin has its own loader.
 */
final class Autoloader {

	/**
	 * Namespace prefix handled by this loader.
	 */
	private const PREFIX = __NAMESPACE__ . '\\';

	/**
	 * Registers the loader with SPL.
	 *
	 * @return void
	 */
	public static function register(): void {
		spl_autoload_register( array( self::class, 'load' ) );
	}

	/**
	 * Loads the file of a class, when the class belongs to the plugin and the file exists.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return void
	 */
	public static function load( string $class_name ): void {
		$file = self::file_for( $class_name );

		if ( '' !== $file && is_readable( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Absolute path of the file that declares a class of the plugin.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string The path, or an empty string for a class outside the plugin namespace.
	 */
	public static function file_for( string $class_name ): string {
		$relative = self::relative_path_for( $class_name );

		return '' === $relative ? '' : dirname( __DIR__ ) . '/includes/' . $relative;
	}

	/**
	 * Path of the file of a class, relative to `includes/`.
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return string The relative path, or an empty string for a class outside the plugin namespace.
	 */
	public static function relative_path_for( string $class_name ): string {
		$class_name = ltrim( $class_name, '\\' );

		if ( ! str_starts_with( $class_name, self::PREFIX ) ) {
			return '';
		}

		$parts = explode( '\\', substr( $class_name, strlen( self::PREFIX ) ) );
		$class = array_pop( $parts );

		if ( '' === $class || 1 !== preg_match( '/\A[A-Za-z0-9_]+\z/', $class ) ) {
			return '';
		}

		$directories = array_map(
			static fn( string $part ): string => strtolower( str_replace( '_', '-', $part ) ),
			$parts
		);
		$file_name   = 'class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		return implode( '/', array_merge( $directories, array( $file_name ) ) );
	}
}

<?php
/**
 * Pure helpers of the release scripts (bin/build-zip.php, bin/svn-prepare.php).
 *
 * No WordPress, no network, no file writes: they are covered by KKCE_Release_Build_Unit_Test.
 *
 * @package KKCE
 */

/**
 * Slug of the plugin: name of the main file, of the folder inside the ZIP and of the SVN repository.
 */
const KKCE_RELEASE_SLUG = 'kk-content-exporter';

/**
 * Patterns of a .distignore file.
 *
 * @param string $content Content of the file.
 * @return list<string> Patterns, without comments and blank lines.
 */
function kkce_release_parse_distignore( string $content ): array {
	$patterns = array();
	foreach ( preg_split( '/\R/', $content ) ?: array() as $line ) {
		$line = trim( $line );
		if ( '' === $line || str_starts_with( $line, '#' ) ) {
			continue;
		}
		$patterns[] = $line;
	}
	return $patterns;
}

/**
 * Whether a path is excluded from the package.
 *
 * An anchored pattern (`/tests`) excludes that path and everything below it; an unanchored pattern
 * (`*.log`) is matched as a shell glob against every segment of the path.
 *
 * @param string       $relative Path relative to the plugin root, with forward slashes.
 * @param list<string> $patterns Patterns of .distignore.
 * @return bool
 */
function kkce_release_is_excluded( string $relative, array $patterns ): bool {
	$relative = trim( str_replace( '\\', '/', $relative ), '/' );
	$segments = explode( '/', $relative );

	foreach ( $patterns as $pattern ) {
		if ( str_starts_with( $pattern, '/' ) ) {
			$anchored = trim( $pattern, '/' );
			if ( $relative === $anchored || str_starts_with( $relative, $anchored . '/' ) ) {
				return true;
			}
			continue;
		}
		foreach ( $segments as $segment ) {
			if ( fnmatch( $pattern, $segment ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * Package paths that must never be shipped, whatever .distignore says (safety net).
 *
 * @param list<string> $files Paths relative to the package root.
 * @return list<string> The offending paths.
 */
function kkce_release_forbidden_files( array $files ): array {
	$forbidden = '#^(?:\.[^/]+|AGENTS|DEV|bin|build|tests|vendor|node_modules)(?:/|$)|^(?:AGENTS\.md|CLAUDE\.md|composer\.(?:json|lock)|phpcs\.xml(?:\.dist)?|phpstan\.neon(?:\.dist)?|phpunit[^/]*\.xml(?:\.dist)?)$|(?:^|/)wp-tests-config[^/]*\.php$#';

	return array_values( array_filter( $files, static fn( string $file ): bool => 1 === preg_match( $forbidden, $file ) ) );
}

/**
 * Files that every package must contain.
 *
 * @param list<string> $files Paths relative to the package root.
 * @return list<string> The missing paths.
 */
function kkce_release_missing_files( array $files ): array {
	$required = array( KKCE_RELEASE_SLUG . '.php', 'uninstall.php', 'readme.txt', 'LICENSE', 'includes/class-plugin.php', 'includes/class-autoloader.php', 'assets/js/feed-editor.js', 'assets/js/feed-mapping.js', 'assets/css/feed-mapping.css' );

	$missing = array_values( array_diff( $required, $files ) );
	if ( array() === preg_grep( '#^languages/' . preg_quote( KKCE_RELEASE_SLUG, '#' ) . '-[a-z]{2,3}_[A-Z]{2}\.mo$#', $files ) ) {
		$missing[] = 'languages/' . KKCE_RELEASE_SLUG . '-<locale>.mo';
	}
	return $missing;
}

/**
 * Value of a `Field: value` line (plugin header or readme.txt header).
 *
 * @param string $content File content.
 * @param string $field   Field name, for example `Version` or `Stable tag`.
 * @return string The value, or an empty string when the field is missing.
 */
function kkce_release_header_value( string $content, string $field ): string {
	if ( 1 !== preg_match( '/^[ \t\/*#@]*' . preg_quote( $field, '/' ) . ':[ \t]*(.+)$/mi', $content, $match ) ) {
		return '';
	}
	return trim( $match[1] );
}

/**
 * Release versions declared by the plugin files, by source.
 *
 * @param string $main_file Content of the main plugin file.
 * @param string $readme    Content of readme.txt.
 * @param string $version   Content of VERSION.txt.
 * @return array<string, string>
 */
function kkce_release_versions( string $main_file, string $readme, string $version ): array {
	$constant = preg_match( "/define\( 'KKCE_VERSION', '([^']+)' \);/", $main_file, $match ) ? $match[1] : '';

	return array(
		'VERSION.txt'           => trim( $version ),
		'plugin header'         => kkce_release_header_value( $main_file, 'Version' ),
		'KKCE_VERSION'          => $constant,
		'readme.txt Stable tag' => kkce_release_header_value( $readme, 'Stable tag' ),
	);
}

/**
 * Whether every source declares the same MAJOR.MINOR.PATCH version.
 *
 * @param array<string, string> $versions Versions by source.
 * @return bool
 */
function kkce_release_versions_agree( array $versions ): bool {
	$unique = array_unique( array_values( $versions ) );
	return 1 === count( $unique ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', (string) reset( $unique ) );
}

/**
 * Whether a release tag appears in the output of `svn list <url>/tags`.
 *
 * Folders are listed with a trailing slash (`1.0.0/`); lines may carry spaces or a carriage return.
 *
 * @param list<string> $listing Output lines of `svn list`.
 * @param string       $version Tag to look for.
 * @return bool
 */
function kkce_release_svn_tag_exists( array $listing, string $version ): bool {
	return in_array( $version . '/', array_map( 'trim', $listing ), true );
}

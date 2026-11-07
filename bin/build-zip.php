<?php
/**
 * Builds the release ZIP of the plugin: build/kk-content-exporter-<version>.zip.
 *
 * Usage: composer run build:zip [-- --allow-dirty] [-- --skip-checks]
 *
 * Steps: (1) unless --allow-dirty, the working tree must be clean, so the package matches a commit;
 * (2) unless --skip-checks, `composer run check` must pass; (3) the files not excluded by .distignore
 * are copied to build/kk-content-exporter/; (4) the package is verified: no development file,
 * required files present, versions aligned; (5) the ZIP is written with the folder
 * `kk-content-exporter/` at its root. Exit code 0 on success, 1 on any failure. Non-interactive.
 *
 * @package KKCE
 */

require_once __DIR__ . '/release-functions.php';

$kkce_root    = str_replace( '\\', '/', dirname( __DIR__ ) );
$kkce_options = array_slice( $argv, 1 );

/**
 * Prints an error and stops.
 *
 * @param string $message Error message.
 * @return never
 */
function kkce_build_fail( string $message ): never {
	fwrite( STDERR, "build:zip: {$message}\n" );
	exit( 1 );
}

/**
 * Removes a folder and its content.
 *
 * @param string $dir Absolute path.
 * @return void
 */
function kkce_build_remove_dir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $items as $item ) {
		$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $dir );
}

// 1. Clean working tree.
if ( ! in_array( '--allow-dirty', $kkce_options, true ) ) {
	exec( 'git -C ' . escapeshellarg( $kkce_root ) . ' status --porcelain 2>&1', $kkce_status, $kkce_code );
	if ( 0 !== $kkce_code ) {
		kkce_build_fail( 'cannot read the Git status (use --allow-dirty outside a Git checkout).' );
	}
	if ( array() !== $kkce_status ) {
		kkce_build_fail( "the working tree has uncommitted changes; commit them or use --allow-dirty:\n  " . implode( "\n  ", $kkce_status ) );
	}
}

// 2. Quality gate.
if ( ! in_array( '--skip-checks', $kkce_options, true ) ) {
	$kkce_composer = getenv( 'COMPOSER_BINARY' );
	$kkce_command  = false !== $kkce_composer && '' !== $kkce_composer
		? escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $kkce_composer )
		: 'composer';
	echo "build:zip: running composer run check...\n";
	passthru( $kkce_command . ' --working-dir=' . escapeshellarg( $kkce_root ) . ' run check', $kkce_code );
	if ( 0 !== $kkce_code ) {
		kkce_build_fail( 'composer run check failed.' );
	}
}

// 3. Select and copy the files.
$kkce_distignore = (string) @file_get_contents( $kkce_root . '/.distignore' );
if ( '' === $kkce_distignore ) {
	kkce_build_fail( '.distignore is missing or empty.' );
}
$kkce_patterns = kkce_release_parse_distignore( $kkce_distignore );

$kkce_iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $kkce_root, FilesystemIterator::SKIP_DOTS ),
		static function ( SplFileInfo $file ) use ( $kkce_root, $kkce_patterns ): bool {
			$relative = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( $kkce_root ) + 1 );
			return ! kkce_release_is_excluded( $relative, $kkce_patterns );
		}
	)
);
$kkce_files = array();
foreach ( $kkce_iterator as $kkce_file ) {
	if ( $kkce_file->isFile() ) {
		$kkce_files[] = substr( str_replace( '\\', '/', $kkce_file->getPathname() ), strlen( $kkce_root ) + 1 );
	}
}
sort( $kkce_files );

// 4. Verify the package.
$kkce_forbidden = kkce_release_forbidden_files( $kkce_files );
if ( array() !== $kkce_forbidden ) {
	kkce_build_fail( "development files would be shipped (add them to .distignore):\n  " . implode( "\n  ", $kkce_forbidden ) );
}
$kkce_missing = kkce_release_missing_files( $kkce_files );
if ( array() !== $kkce_missing ) {
	kkce_build_fail( "required files are missing:\n  " . implode( "\n  ", $kkce_missing ) );
}
$kkce_versions = kkce_release_versions(
	(string) file_get_contents( $kkce_root . '/' . KKCE_RELEASE_SLUG . '.php' ),
	(string) file_get_contents( $kkce_root . '/readme.txt' ),
	(string) file_get_contents( $kkce_root . '/VERSION.txt' )
);
if ( ! kkce_release_versions_agree( $kkce_versions ) ) {
	kkce_build_fail( 'the versions are not aligned: ' . json_encode( $kkce_versions ) );
}
$kkce_version = $kkce_versions['VERSION.txt'];

$kkce_build_dir = $kkce_root . '/build';
$kkce_stage     = $kkce_build_dir . '/' . KKCE_RELEASE_SLUG;
kkce_build_remove_dir( $kkce_stage );
foreach ( $kkce_files as $kkce_relative ) {
	$kkce_target = $kkce_stage . '/' . $kkce_relative;
	if ( ! is_dir( dirname( $kkce_target ) ) && ! mkdir( dirname( $kkce_target ), 0777, true ) ) {
		kkce_build_fail( 'cannot create ' . dirname( $kkce_target ) );
	}
	if ( ! copy( $kkce_root . '/' . $kkce_relative, $kkce_target ) ) {
		kkce_build_fail( "cannot copy {$kkce_relative}" );
	}
}

// 5. Write the ZIP.
$kkce_zip_path = $kkce_build_dir . '/' . KKCE_RELEASE_SLUG . '-' . $kkce_version . '.zip';
if ( is_file( $kkce_zip_path ) ) {
	unlink( $kkce_zip_path );
}
$kkce_zip = new ZipArchive();
if ( true !== $kkce_zip->open( $kkce_zip_path, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
	kkce_build_fail( "cannot create {$kkce_zip_path}" );
}
$kkce_zip->addEmptyDir( KKCE_RELEASE_SLUG );
foreach ( $kkce_files as $kkce_relative ) {
	$kkce_zip->addFile( $kkce_stage . '/' . $kkce_relative, KKCE_RELEASE_SLUG . '/' . $kkce_relative );
}
if ( ! $kkce_zip->close() ) {
	kkce_build_fail( "cannot write {$kkce_zip_path}" );
}

printf( "build:zip: %d files, version %s\n  folder: %s\n  zip:    %s (%d bytes)\n", count( $kkce_files ), $kkce_version, $kkce_stage, $kkce_zip_path, (int) filesize( $kkce_zip_path ) );
foreach ( $kkce_files as $kkce_relative ) {
	echo "  - {$kkce_relative}\n";
}
exit( 0 );

<?php
/**
 * PHP syntax check (`php -l`) of every first-party PHP file of the plugin.
 *
 * Usage: php bin/php-lint.php [file ...]
 * Without arguments it walks the plugin directory, skipping dependencies, caches and private
 * folders. Exit code 0 when every file is valid, 1 otherwise. Non-interactive (CI-ready).
 *
 * @package KKCE
 */

$kkce_root     = dirname( __DIR__ );
$kkce_excluded = array( '.git', 'vendor', 'node_modules', 'DEV', 'build', '.phpunit.cache', 'tests/integration/.cache' );
$kkce_files    = array_slice( $argv, 1 );

if ( array() === $kkce_files ) {
	$kkce_iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $kkce_root, FilesystemIterator::SKIP_DOTS ),
			static function ( SplFileInfo $file ) use ( $kkce_root, $kkce_excluded ): bool {
				$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $kkce_root ) + 1 ) );
				if ( $file->isDir() ) {
					return ! in_array( $relative, $kkce_excluded, true );
				}
				return 'php' === strtolower( $file->getExtension() );
			}
		)
	);
	foreach ( $kkce_iterator as $kkce_file ) {
		$kkce_files[] = $kkce_file->getPathname();
	}
	sort( $kkce_files );
}

$kkce_failed = 0;
foreach ( $kkce_files as $kkce_file ) {
	$kkce_output = array();
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $kkce_file ) . ' 2>&1', $kkce_output, $kkce_status );
	if ( 0 !== $kkce_status ) {
		++$kkce_failed;
		fwrite( STDERR, implode( PHP_EOL, $kkce_output ) . PHP_EOL );
	}
}

printf( "php -l: %d file(s) checked, %d with errors.\n", count( $kkce_files ), $kkce_failed );
exit( 0 === $kkce_failed ? 0 : 1 );

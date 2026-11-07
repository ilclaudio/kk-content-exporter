<?php
/**
 * Prepares a WordPress.org SVN working copy for the release built by `composer run build:zip`.
 *
 * Usage: composer run svn:prepare [-- --apply] [-- --svn-url=<url>] [-- --dir=<path>]
 *
 * Without --apply (the default) it only prints the plan. With --apply it checks out or updates the
 * SVN repository in build/svn/ (or --dir), mirrors build/kk-content-exporter/ into trunk/ (adds new
 * files, removes deleted ones), copies trunk/ to tags/<version>/ and prints `svn status`.
 * It NEVER runs `svn commit`: the operator reviews the status and commits by hand.
 *
 * @package KKCE
 */

require_once __DIR__ . '/release-functions.php';

$kkce_root  = str_replace( '\\', '/', dirname( __DIR__ ) );
$kkce_apply = in_array( '--apply', $argv, true );
$kkce_url   = 'https://plugins.svn.wordpress.org/' . KKCE_RELEASE_SLUG;
$kkce_dir   = $kkce_root . '/build/svn';

foreach ( array_slice( $argv, 1 ) as $kkce_arg ) {
	if ( str_starts_with( $kkce_arg, '--svn-url=' ) ) {
		$kkce_url = substr( $kkce_arg, 10 );
	} elseif ( str_starts_with( $kkce_arg, '--dir=' ) ) {
		$kkce_dir = rtrim( str_replace( '\\', '/', substr( $kkce_arg, 6 ) ), '/' );
	}
}

/**
 * Prints an error and stops.
 *
 * @param string $message Error message.
 * @return never
 */
function kkce_svn_fail( string $message ): never {
	fwrite( STDERR, "svn:prepare: {$message}\n" );
	exit( 1 );
}

/**
 * Runs an svn command and stops on failure.
 *
 * @param list<string> $args Arguments of svn (each one is escaped).
 * @return list<string> Output lines.
 */
function kkce_svn( array $args ): array {
	$command = 'svn ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
	exec( $command, $output, $code );
	if ( 0 !== $code ) {
		kkce_svn_fail( "command failed: {$command}\n" . implode( "\n", $output ) );
	}
	return $output;
}

$kkce_stage = $kkce_root . '/build/' . KKCE_RELEASE_SLUG;
if ( ! is_file( $kkce_stage . '/' . KKCE_RELEASE_SLUG . '.php' ) ) {
	kkce_svn_fail( 'build/' . KKCE_RELEASE_SLUG . '/ not found: run composer run build:zip first.' );
}
$kkce_versions = kkce_release_versions(
	(string) file_get_contents( $kkce_stage . '/' . KKCE_RELEASE_SLUG . '.php' ),
	(string) file_get_contents( $kkce_stage . '/readme.txt' ),
	(string) file_get_contents( $kkce_root . '/VERSION.txt' )
);
if ( ! kkce_release_versions_agree( $kkce_versions ) ) {
	kkce_svn_fail( 'the build does not match VERSION.txt: rebuild it. ' . json_encode( $kkce_versions ) );
}
$kkce_version = $kkce_versions['VERSION.txt'];

echo "svn:prepare plan (version {$kkce_version}):\n";
echo '  1. ' . ( is_dir( $kkce_dir . '/.svn' ) ? "svn update {$kkce_dir}" : "svn checkout {$kkce_url} {$kkce_dir} (trunk, tags list)" ) . "\n";
echo "  2. mirror build/" . KKCE_RELEASE_SLUG . "/ into {$kkce_dir}/trunk/ (svn add / svn rm)\n";
echo "  3. svn copy trunk tags/{$kkce_version}\n";
echo "  4. print svn status; the commit is yours: svn commit {$kkce_dir} -m \"Release {$kkce_version}\"\n";

if ( ! $kkce_apply ) {
	echo "Dry run: nothing done. Run again with --apply to prepare the working copy.\n";
	exit( 0 );
}

exec( 'svn --version --quiet 2>&1', $kkce_svn_version, $kkce_code );
if ( 0 !== $kkce_code ) {
	kkce_svn_fail( 'the svn command-line client is not available.' );
}

// The working copy holds tags/ at depth "empty", so a tag that exists only on the server is not visible
// locally: ask the server before touching anything.
if ( kkce_release_svn_tag_exists( kkce_svn( array( 'list', $kkce_url . '/tags' ) ), $kkce_version ) ) {
	kkce_svn_fail( "tags/{$kkce_version} already exists on {$kkce_url}: bump the version before releasing." );
}

if ( is_dir( $kkce_dir . '/.svn' ) ) {
	kkce_svn( array( 'update', '--quiet', $kkce_dir . '/trunk', $kkce_dir . '/tags' ) );
} else {
	kkce_svn( array( 'checkout', '--quiet', '--depth', 'immediates', $kkce_url, $kkce_dir ) );
	kkce_svn( array( 'update', '--quiet', '--set-depth', 'infinity', $kkce_dir . '/trunk' ) );
}

// A tag prepared by a previous run and not committed yet.
if ( file_exists( $kkce_dir . '/tags/' . $kkce_version ) ) {
	kkce_svn_fail( "tags/{$kkce_version} already exists in {$kkce_dir} (prepared but not committed): commit it or run svn revert -R {$kkce_dir}." );
}

// Mirror the build into trunk/: remove what is there (except .svn), then copy the build.
$kkce_trunk = $kkce_dir . '/trunk';
$kkce_items = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $kkce_trunk, FilesystemIterator::SKIP_DOTS ),
		static fn( SplFileInfo $file ): bool => '.svn' !== $file->getFilename()
	),
	RecursiveIteratorIterator::CHILD_FIRST
);
foreach ( $kkce_items as $kkce_item ) {
	$kkce_item->isDir() ? rmdir( $kkce_item->getPathname() ) : unlink( $kkce_item->getPathname() );
}
$kkce_source = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $kkce_stage, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
foreach ( $kkce_source as $kkce_item ) {
	$kkce_target = $kkce_trunk . '/' . substr( str_replace( '\\', '/', $kkce_item->getPathname() ), strlen( $kkce_stage ) + 1 );
	if ( $kkce_item->isDir() ) {
		is_dir( $kkce_target ) || mkdir( $kkce_target, 0777, true );
	} else {
		copy( $kkce_item->getPathname(), $kkce_target );
	}
}

kkce_svn( array( 'add', '--force', '--quiet', $kkce_trunk ) );
foreach ( kkce_svn( array( 'status', $kkce_trunk ) ) as $kkce_line ) {
	if ( str_starts_with( $kkce_line, '!' ) ) {
		kkce_svn( array( 'rm', '--quiet', trim( substr( $kkce_line, 8 ) ) ) );
	}
}
kkce_svn( array( 'copy', '--quiet', $kkce_trunk, $kkce_dir . '/tags/' . $kkce_version ) );

echo implode( "\n", kkce_svn( array( 'status', $kkce_dir ) ) ) . "\n";
echo "svn:prepare: working copy ready. Review it, then commit by hand:\n  svn commit {$kkce_dir} -m \"Release {$kkce_version}\"\n";
exit( 0 );

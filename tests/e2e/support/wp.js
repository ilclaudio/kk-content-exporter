/**
 * Helpers of the E2E suite that act on the wp-env development environment through WP-CLI.
 *
 * Arguments are passed as arrays to wp-env (never through a shell), so they behave the same on Windows.
 *
 * @package KKCE
 */

const { spawnSync } = require( 'node:child_process' );
const path = require( 'node:path' );

const ROOT = path.resolve( __dirname, '..', '..', '..' );
const WP_ENV_PACKAGE = require.resolve( '@wordpress/env/package.json' );
const WP_ENV = path.join( path.dirname( WP_ENV_PACKAGE ), require( WP_ENV_PACKAGE ).bin[ 'wp-env' ] );
const PLUGIN_DIR = 'wp-content/plugins/' + path.basename( ROOT );
const FIXTURES = PLUGIN_DIR + '/tests/e2e/support/fixtures.php';

/**
 * Runs WP-CLI in the CLI container of the development environment.
 *
 * @param {string[]} args Arguments of `wp`.
 * @return {string} Standard output, trimmed.
 */
function wp( args ) {
	const result = spawnSync( process.execPath, [ WP_ENV, 'run', '--config=.wp-env.e2etest.json', 'cli', '--', 'wp', ...args ], { cwd: ROOT, encoding: 'utf8' } );
	if ( 0 !== result.status ) {
		throw new Error( 'WP-CLI failed: wp ' + args.join( ' ' ) + '\n' + result.stderr );
	}
	return result.stdout.trim();
}

/**
 * Reads the JSON printed on the last line of an output (wp-env adds its own lines).
 *
 * @param {string} output Output of a command.
 * @return {Object} Decoded value.
 */
function lastJson( output ) {
	const lines = output.split( /\r?\n/ ).filter( ( line ) => line.trim().startsWith( '{' ) );
	return JSON.parse( lines[ lines.length - 1 ] );
}

/**
 * Creates the data of a test: a feed and published items, all with the `kkce-e2e-` prefix.
 *
 * @param {string} name    Short name of the test (letters, digits, dashes).
 * @param {Object} options Feed and items, optional `draft` (mapping text and errors) and administrator `locale`.
 * @return {{feed: number, slug: string, posts: number[], pages: number[], previous_locale?: string|null}} IDs and, when changed, the previous locale.
 */
function createFixtures( name, options = {} ) {
	const args = [ 'eval-file', FIXTURES, 'create', name, JSON.stringify( options ) ];
	if ( options.draft || Object.prototype.hasOwnProperty.call( options, 'locale' ) ) {
		args.unshift( '--user=admin' );
	}
	return lastJson( wp( args ) );
}

/**
 * Deletes every object of the suite (slugs that start with `kkce-e2e-`).
 *
 * @param {Object} options Optional administrator `locale` to restore; null removes the user override.
 * @return {number} Objects deleted.
 */
function cleanupFixtures( options = {} ) {
	const args = [ 'eval-file', FIXTURES, 'cleanup', JSON.stringify( options ) ];
	if ( Object.prototype.hasOwnProperty.call( options, 'locale' ) ) {
		args.unshift( '--user=admin' );
	}
	return lastJson( wp( args ) ).deleted;
}

module.exports = { wp, createFixtures, cleanupFixtures };

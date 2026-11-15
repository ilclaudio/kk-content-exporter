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
	const result = spawnSync( process.execPath, [ WP_ENV, 'run', 'cli', '--', 'wp', ...args ], { cwd: ROOT, encoding: 'utf8' } );
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
 * @param {Object} options `post_types` of the feed, `posts` and `pages` (title, excerpt, content).
 * @return {{feed: number, slug: string, posts: number[], pages: number[]}} IDs.
 */
function createFixtures( name, options = {} ) {
	return lastJson( wp( [ 'eval-file', FIXTURES, 'create', name, JSON.stringify( options ) ] ) );
}

/**
 * Deletes every object of the suite (slugs that start with `kkce-e2e-`).
 *
 * @return {number} Objects deleted.
 */
function cleanupFixtures() {
	return lastJson( wp( [ 'eval-file', FIXTURES, 'cleanup' ] ) ).deleted;
}

module.exports = { wp, createFixtures, cleanupFixtures };

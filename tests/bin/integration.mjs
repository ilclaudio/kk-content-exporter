/**
 * Runs the integration suite in the wp-env test environment (`.wp-env.inttest.json`, port 8891).
 *
 * Steps: start the environment (nothing happens when it is already running), create the dedicated
 * database `kkce_wordpress_test` in its MySQL server, then run PHPUnit inside the CLI container with the
 * configuration of tests/integration/wp-tests-config.php, twice: the `integration` suite without optional
 * plugins, then the `polylang` suite with Polylang loaded (`KKCE_TEST_POLYLANG=1`; a loaded plugin cannot be
 * unloaded, so each needs its own process). Both always run; the script fails when either fails. Extra
 * arguments go to PHPUnit in both runs, for example `npm run test:integration -- --filter Feed_Editor` (a run
 * where the filter matches nothing executes no test).
 *
 * The connection values and the WordPress folder are the defaults of tests/integration/wp-tests-config.php
 * (the public defaults of wp-env: the environment holds no real data). The guard of
 * tests/bootstrap-integration.php refuses any database other than `kkce_wordpress_test`.
 *
 * Commands run through tests/bin/tools.mjs: arguments as arrays, never through a shell, the same on Windows.
 *
 * @package KKCE
 */

import path from 'node:path';
import { requireDocker } from './docker.mjs';
import { bin, exec, root, run } from './tools.mjs';

const wpEnv = bin( '@wordpress/env', 'wp-env' );
const config = '--config=.wp-env.inttest.json';
const pluginDir = 'wp-content/plugins/' + path.basename( root );

requireDocker();

run(
	wpEnv,
	[ 'start', config ],
	'The wp-env test environment could not start. Is Docker Desktop running? See tests/integration/README.md.'
);

run(
	wpEnv,
	[ 'run', config, 'cli', 'wp', 'db', 'query', 'CREATE DATABASE IF NOT EXISTS kkce_wordpress_test' ],
	'The database kkce_wordpress_test could not be created in the wp-env test environment.'
);

/**
 * Runs one PHPUnit suite inside the CLI container.
 *
 * @param {string}   suite Name of the suite in phpunit-integration.xml.
 * @param {string[]} env   Extra environment variables, as `NAME=value`.
 * @return {number} Exit code of PHPUnit.
 */
function phpunit( suite, env ) {
	return exec(
		wpEnv,
		[
			'run',
			config,
			'cli',
			'--env-cwd=' + pluginDir,
			'--',
			'env',
			// wp-env defines its own test configuration: the one of the plugin is used instead.
			'WP_TESTS_CONFIG_FILE_PATH=/var/www/html/' + pluginDir + '/tests/integration/wp-tests-config.php',
			...env,
			'vendor/bin/phpunit',
			'-c',
			'phpunit-integration.xml',
			'--testsuite',
			suite,
			...process.argv.slice( 2 ),
		]
	);
}

const failed = [];
if ( 0 !== phpunit( 'integration', [] ) ) {
	failed.push( 'integration' );
}
console.log( '\nSuite with Polylang (tests/integration/polylang):' );
if ( 0 !== phpunit( 'polylang', [ 'KKCE_TEST_POLYLANG=1' ] ) ) {
	failed.push( 'polylang' );
}
if ( failed.length ) {
	console.error( '\nThe integration suite failed: ' + failed.join( ', ' ) + '.' );
	process.exit( 1 );
}

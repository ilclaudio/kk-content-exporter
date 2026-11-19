/**
 * Runs the integration suite in the wp-env test environment (`.wp-env.inttest.json`, port 8891).
 *
 * Steps: start the environment (nothing happens when it is already running), create the dedicated
 * database `kkce_wordpress_test` in its MySQL server, then run PHPUnit inside the CLI container with the
 * configuration of tests/integration/wp-tests-config.php. Extra arguments go to PHPUnit, for example
 * `npm run test:integration -- --filter Feed_Editor`.
 *
 * The connection values and the WordPress folder are the defaults of tests/integration/wp-tests-config.php
 * (the public defaults of wp-env: the environment holds no real data). The guard of
 * tests/bootstrap-integration.php refuses any database other than `kkce_wordpress_test`.
 *
 * Arguments are passed as arrays, never through a shell, so the script behaves the same on Windows.
 *
 * @package KKCE
 */

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';
import { requireDocker } from './docker.mjs';

const require = createRequire( import.meta.url );
const root = path.resolve( import.meta.dirname, '..', '..' );
// The executable is declared in the `bin` field of the package, which does not export it as a module.
const wpEnvPackage = require.resolve( '@wordpress/env/package.json' );
const wpEnv = path.join( path.dirname( wpEnvPackage ), require( wpEnvPackage ).bin[ 'wp-env' ] );
const config = '--config=.wp-env.inttest.json';
const pluginDir = 'wp-content/plugins/' + path.basename( root );

/**
 * Runs wp-env with the given arguments and stops the script when it fails.
 *
 * @param {string[]} args    Arguments of wp-env.
 * @param {string}   message Message shown when the command fails.
 */
function wpEnvRun( args, message ) {
	const result = spawnSync( process.execPath, [ wpEnv, ...args ], { cwd: root, stdio: 'inherit' } );
	if ( 0 !== result.status ) {
		console.error( '\n' + message );
		process.exit( result.status || 1 );
	}
}

requireDocker();

wpEnvRun(
	[ 'start', config ],
	'The wp-env test environment could not start. Is Docker Desktop running? See tests/integration/README.md.'
);

wpEnvRun(
	[ 'run', config, 'cli', 'wp', 'db', 'query', 'CREATE DATABASE IF NOT EXISTS kkce_wordpress_test' ],
	'The database kkce_wordpress_test could not be created in the wp-env test environment.'
);

wpEnvRun(
	[
		'run',
		config,
		'cli',
		'--env-cwd=' + pluginDir,
		'--',
		'env',
		// wp-env defines its own test configuration: the one of the plugin is used instead.
		'WP_TESTS_CONFIG_FILE_PATH=/var/www/html/' + pluginDir + '/tests/integration/wp-tests-config.php',
		'vendor/bin/phpunit',
		'-c',
		'phpunit-integration.xml',
		...process.argv.slice( 2 ),
	],
	'The integration suite failed.'
);

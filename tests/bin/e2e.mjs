/**
 * Runs the end-to-end suite: starts the wp-env development environment (`.wp-env.json`, port 8890; nothing
 * happens when it is already running), then runs Playwright. Extra arguments go to Playwright, for example
 * `composer run test:e2e -- -g "previewed"`; `--headed` and `--ui` are passed by the `test:e2e:headed` and `test:e2e:ui`
 * scripts.
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

/**
 * Path of the executable that a package declares in its `bin` field.
 *
 * @param {string} name    Package name.
 * @param {string} command Name of the executable.
 * @return {string} Absolute path.
 */
function bin( name, command ) {
	const manifest = require.resolve( name + '/package.json' );
	const declared = require( manifest ).bin;
	return path.join( path.dirname( manifest ), 'string' === typeof declared ? declared : declared[ command ] );
}

/**
 * Runs a Node executable and stops the script when it fails.
 *
 * @param {string}   file    Executable.
 * @param {string[]} args    Arguments.
 * @param {string}   message Message shown when it fails.
 */
function run( file, args, message ) {
	const result = spawnSync( process.execPath, [ file, ...args ], { cwd: root, stdio: 'inherit' } );
	if ( 0 !== result.status ) {
		console.error( '\n' + message );
		process.exit( result.status || 1 );
	}
}

requireDocker();

run(
	bin( '@wordpress/env', 'wp-env' ),
	[ 'start' ],
	'The wp-env development environment could not start. Is Docker Desktop running? See tests/e2e/README.md.'
);

run( bin( '@playwright/test', 'playwright' ), [ 'test', ...process.argv.slice( 2 ) ], 'The end-to-end suite failed.' );

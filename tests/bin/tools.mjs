/**
 * Shared helpers of the Node scripts of the test tools: the root of the plugin, the executables of the npm
 * packages, and how a command runs.
 *
 * Commands run with the Node executable that runs the script and with their arguments as an array, never through
 * a shell, so the scripts behave the same on Windows; their output goes to the terminal as it is.
 *
 * @package KKCE
 */

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire( import.meta.url );

/**
 * Root folder of the plugin.
 */
export const root = path.resolve( import.meta.dirname, '..', '..' );

/**
 * Path of the executable that a package declares in its `bin` field (the package does not export it as a module).
 *
 * @param {string} name    Package name.
 * @param {string} command Name of the executable.
 * @return {string} Absolute path.
 */
export function bin( name, command ) {
	const manifest = require.resolve( name + '/package.json' );
	const declared = require( manifest ).bin;
	return path.join( path.dirname( manifest ), 'string' === typeof declared ? declared : declared[ command ] );
}

/**
 * Runs a Node executable from the root of the plugin.
 *
 * @param {string}   file Executable.
 * @param {string[]} args Arguments.
 * @return {number} Exit code (1 when the process did not end normally).
 */
export function exec( file, args ) {
	const result = spawnSync( process.execPath, [ file, ...args ], { cwd: root, stdio: 'inherit' } );
	return null === result.status ? 1 : result.status;
}

/**
 * Runs a Node executable and stops the script, with a message, when it fails.
 *
 * @param {string}   file    Executable.
 * @param {string[]} args    Arguments.
 * @param {string}   message Message shown when it fails.
 */
export function run( file, args, message ) {
	const status = exec( file, args );
	if ( 0 !== status ) {
		console.error( '\n' + message );
		process.exit( status );
	}
}

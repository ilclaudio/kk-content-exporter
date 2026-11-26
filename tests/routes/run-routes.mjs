/**
 * Runs the requests of an Insomnia collection (export format 4) and checks their HTTP status.
 *
 * Generic: the site address, the slugs and the credentials come from a local configuration file that is
 * never committed (`routes.config.json`, see `routes.config.example.json`). Its values replace the
 * variables `{{ _.name }}` of the collection; a variable missing from the file falls back to the base
 * environment of the collection, except `base_url`, which must be in the file. A request that needs an
 * empty variable is skipped, so the authenticated requests run only when their credentials are set.
 *
 * The expected status is read from the name of the request: the status codes between the last
 * parentheses ("Missing feed (404)", "Authenticated feed (200 or 403)"), 200 when there is none.
 * Credentials are sent as HTTP Basic authentication and never printed.
 *
 * Usage:
 *   node tests/routes/run-routes.mjs [--config <file>] [--collection <file>] [--filter <text>]
 *
 * Exit code 0 when every request that ran gave an expected status, 1 otherwise.
 *
 * @package KKCE
 */

import { readFileSync, existsSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname( fileURLToPath( import.meta.url ) );

/**
 * Reads the command-line options.
 *
 * @return {{config: string, collection: string|null, filter: string}} Options.
 */
function readOptions() {
	const options = { config: resolve( here, 'routes.config.json' ), collection: null, filter: '' };
	const args = process.argv.slice( 2 );

	for ( let i = 0; i < args.length; i++ ) {
		const value = args[ i + 1 ];
		switch ( args[ i ] ) {
			case '--config':
				options.config = resolve( value );
				i++;
				break;
			case '--collection':
				options.collection = resolve( value );
				i++;
				break;
			case '--filter':
				options.filter = String( value ?? '' ).toLowerCase();
				i++;
				break;
			default:
				fail( `Unknown option: ${ args[ i ] }` );
		}
	}

	return options;
}

/**
 * Stops the script with a message.
 *
 * @param {string} message Message.
 */
function fail( message ) {
	console.error( message );
	process.exit( 1 );
}

/**
 * Reads a JSON file.
 *
 * The message of a JSON error of Node quotes a piece of the text near the error: for a file that holds credentials
 * (the configuration, with the Application Passwords) only the position is printed, never the text.
 *
 * @param {string}  file      Path.
 * @param {string}  what      What the file is, for the error message.
 * @param {boolean} sensitive Whether the file can hold credentials.
 * @return {any} Decoded content.
 */
function readJson( file, what, sensitive = false ) {
	if ( ! existsSync( file ) ) {
		fail( `${ what } not found: ${ file }` );
	}
	const text = readFileSync( file, 'utf8' );
	try {
		return JSON.parse( text );
	} catch ( error ) {
		if ( ! sensitive ) {
			fail( `${ what } is not valid JSON: ${ file } (${ error.message })` );
		}
		const match = /position (\d+)/.exec( String( error.message ) );
		const where = match ? ', ' + position( text, Number( match[ 1 ] ) ) : '';
		fail( `${ what } is not valid JSON: ${ file }${ where }. Check the quotes and the commas there.` );
	}
}

/**
 * Line and column of a character of a text, counted from 1.
 *
 * @param {string} text   Text.
 * @param {number} offset Index of the character.
 * @return {string} For example `line 3, column 25`.
 */
function position( text, offset ) {
	const before = text.slice( 0, offset ).split( '\n' );
	return `line ${ before.length }, column ${ before[ before.length - 1 ].length + 1 }`;
}

/**
 * Replaces the variables of a text. Supports `{{ _.name }}` and the filter `{{ _.name | upper }}`.
 *
 * @param {string}             text      Text with variables.
 * @param {Record<string,any>} variables Values.
 * @param {Set<string>}        missing   Receives the names of the empty variables.
 * @return {string} Text with the values.
 */
function render( text, variables, missing ) {
	return String( text ?? '' ).replace( /\{\{\s*_\.([A-Za-z0-9_]+)\s*(?:\|\s*(upper|lower)\s*)?\}\}/g, ( match, name, filter ) => {
		const value = variables[ name ];
		if ( undefined === value || null === value || '' === String( value ) ) {
			missing.add( name );
			return '';
		}
		const text = String( value );
		if ( 'upper' === filter ) {
			return text.toUpperCase();
		}
		return 'lower' === filter ? text.toLowerCase() : text;
	} );
}

/**
 * Expected status codes of a request, from its name.
 *
 * @param {string} name Name of the request.
 * @return {number[]} Expected codes.
 */
function expectedStatuses( name ) {
	const groups = [ ...String( name ).matchAll( /\(([^()]*)\)/g ) ].map( ( match ) => match[ 1 ] );
	for ( const group of groups.reverse() ) {
		const codes = [ ...group.matchAll( /\b([1-5]\d\d)\b/g ) ].map( ( match ) => Number( match[ 1 ] ) );
		if ( codes.length ) {
			return codes;
		}
	}
	return [ 200 ];
}

/**
 * Requests of the collection, folder by folder, in the order of the export file.
 *
 * @param {any[]} resources Resources of the export.
 * @return {{request: any, folder: string}[]} Ordered requests.
 */
function orderedRequests( resources ) {
	const byParent = new Map();
	for ( const resource of resources ) {
		if ( 'request' === resource._type || 'request_group' === resource._type ) {
			const list = byParent.get( resource.parentId ) ?? [];
			list.push( resource );
			byParent.set( resource.parentId, list );
		}
	}

	const result = [];
	const visit = ( parentId, folder ) => {
		for ( const child of byParent.get( parentId ) ?? [] ) {
			if ( 'request_group' === child._type ) {
				visit( child._id, child.name );
			} else {
				result.push( { request: child, folder } );
			}
		}
	};

	const workspace = resources.find( ( resource ) => 'workspace' === resource._type );
	visit( workspace?._id, '' );

	return result;
}

/**
 * Builds the fetch options of a request.
 *
 * @param {any}                request   Request of the collection.
 * @param {Record<string,any>} variables Values.
 * @return {{url: string, init: RequestInit, missing: Set<string>}} URL, options and missing variables.
 */
function prepare( request, variables ) {
	const missing = new Set();
	const url = new URL( render( request.url, variables, missing ) || 'http://invalid.invalid' );

	for ( const parameter of request.parameters ?? [] ) {
		if ( ! parameter.disabled ) {
			url.searchParams.append( render( parameter.name, variables, missing ), render( parameter.value, variables, missing ) );
		}
	}

	const headers = {};
	for ( const header of request.headers ?? [] ) {
		if ( ! header.disabled && header.name ) {
			headers[ render( header.name, variables, missing ) ] = render( header.value, variables, missing );
		}
	}

	const auth = request.authentication ?? {};
	if ( 'basic' === auth.type && ! auth.disabled ) {
		const user = render( auth.username, variables, missing );
		const password = render( auth.password, variables, missing );
		headers.Authorization = 'Basic ' + Buffer.from( `${ user }:${ password }` ).toString( 'base64' );
	}

	const init = { method: request.method || 'GET', headers, redirect: 'manual' };
	if ( request.body?.text && ! [ 'GET', 'HEAD' ].includes( init.method ) ) {
		init.body = render( request.body.text, variables, missing );
		if ( request.body.mimeType && ! headers[ 'Content-Type' ] ) {
			headers[ 'Content-Type' ] = request.body.mimeType;
		}
	}

	return { url: url.toString(), init, missing };
}

/**
 * Error code of a WordPress REST error response, when the body is one.
 *
 * @param {string} body Response body.
 * @return {string} The code, or an empty string.
 */
function errorCode( body ) {
	try {
		const data = JSON.parse( body );
		return data && 'object' === typeof data && 'string' === typeof data.code ? data.code : '';
	} catch {
		return '';
	}
}

const options = readOptions();
if ( ! existsSync( options.config ) ) {
	fail(
		`Configuration file not found: ${ options.config }\n` +
		`Copy ${ resolve( here, 'routes.config.example.json' ) } to that path and fill it in (see tests/routes/README.md).`
	);
}
const config = readJson( options.config, 'Configuration file', true );
const collectionFile = options.collection ?? ( config.collection ? resolve( dirname( options.config ), config.collection ) : resolve( here, 'Insomnia_Feed_Endpoint.json' ) );
const collection = readJson( collectionFile, 'Collection' );
const resources = Array.isArray( collection.resources ) ? collection.resources : fail( 'The collection has no resources: is it an Insomnia export (format 4)?' );

const workspace = resources.find( ( resource ) => 'workspace' === resource._type );
const base = resources.find( ( resource ) => 'environment' === resource._type && resource.parentId === workspace?._id );
const fileVariables = config.variables && 'object' === typeof config.variables ? config.variables : {};
if ( ! fileVariables.base_url ) {
	fail( `Set "variables.base_url" (address and port of the site, for example http://localhost:8890) in ${ options.config }.` );
}
const variables = { ...( base?.data ?? {} ), ...fileVariables };
variables.base_url = String( variables.base_url ).replace( /\/+$/, '' );

console.log( `Collection: ${ collectionFile }` );
console.log( `Site:       ${ variables.base_url }\n` );

const totals = { pass: 0, fail: 0, skip: 0 };
let currentFolder = null;

for ( const { request, folder } of orderedRequests( resources ) ) {
	const label = `${ folder } / ${ request.name }`;
	if ( options.filter && ! label.toLowerCase().includes( options.filter ) ) {
		continue;
	}
	if ( folder !== currentFolder ) {
		console.log( folder || '(no folder)' );
		currentFolder = folder;
	}

	const expected = expectedStatuses( request.name );
	const { url, init, missing } = prepare( request, variables );

	if ( missing.size ) {
		totals.skip++;
		console.log( `  SKIP  ${ request.name }  (empty: ${ [ ...missing ].join( ', ' ) })` );
		continue;
	}

	try {
		const response = await fetch( url, init );
		const body = await response.text();
		const ok = expected.includes( response.status );
		const code = errorCode( body );
		totals[ ok ? 'pass' : 'fail' ]++;
		console.log( `  ${ ok ? 'PASS' : 'FAIL' }  ${ response.status }${ code ? ' ' + code : '' }  ${ request.name }${ ok ? '' : `  (expected ${ expected.join( ' or ' ) })` }` );
		if ( ! ok ) {
			console.log( `        ${ init.method } ${ url }` );
		}
	} catch ( error ) {
		totals.fail++;
		console.log( `  FAIL  ${ request.name }  (${ error.cause?.code ?? error.message })` );
	}
}

console.log( `\n${ totals.pass } passed, ${ totals.fail } failed, ${ totals.skip } skipped.` );
process.exit( totals.fail ? 1 : 0 );

/**
 * Global setup of the E2E suite: checks that the wp-env development environment answers, removes the data
 * left by an interrupted run and signs in the administrator once (the session is reused by every test).
 *
 * @package KKCE
 */

const { chromium } = require( '@playwright/test' );
const { cleanupFixtures } = require( './wp' );
const { AUTH_FILE } = require( './paths' );

/**
 * @param {import('@playwright/test').FullConfig} config Configuration.
 */
module.exports = async function globalSetup( config ) {
	const { baseURL } = config.projects[ 0 ].use;

	try {
		await fetch( baseURL + '/wp-login.php' );
	} catch ( error ) {
		throw new Error( 'The wp-env development environment does not answer at ' + baseURL + ': run "composer run env:start" (Docker Desktop must be running).' );
	}

	cleanupFixtures();

	const browser = await chromium.launch();
	const page = await browser.newPage( { baseURL } );
	await page.goto( '/wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( 'admin' );
	await page.getByLabel( 'Password', { exact: true } ).fill( 'password' );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( /wp-admin/ );
	await page.context().storageState( { path: AUTH_FILE } );
	await browser.close();
};

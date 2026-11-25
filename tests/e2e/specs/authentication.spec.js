/**
 * Cookie and nonce failures in the mapping preview, including WordPress's nonce refresh middleware.
 * HTTP authentication challenges are separate from JavaScript dialogs, so Chromium's Fetch domain
 * observes them and cancels any challenge rather than submitting credentials.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, preview } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'the preview refreshes an invalid nonce and reports a lost session without HTTP authentication challenges', async ( { page, context } ) => {
	const data = createFixtures( 'authentication', {
		post_types: [ 'post' ],
		posts: [ { title: 'E2E authentication' } ],
		rows: [ { key: 'title', kind: 'field', sources: { '*': [ 'core.title' ] } } ],
	} );
	const box = await openFeed( page, data.feed );
	const panel = preview( box );
	await expect( panel ).toContainText( '"title": "E2E authentication"' );
	const cookies = await context.cookies();
	const challenges = [];
	const client = await context.newCDPSession( page );
	client.on( 'Fetch.requestPaused', async ( event ) => {
		await client.send( 'Fetch.continueRequest', { requestId: event.requestId } );
	} );
	client.on( 'Fetch.authRequired', async ( event ) => {
		challenges.push( event.authChallenge.scheme );
		await client.send( 'Fetch.continueWithAuth', {
			requestId: event.requestId,
			authChallengeResponse: { response: 'CancelAuth' },
		} );
	} );
	await client.send( 'Fetch.enable', { patterns: [ { urlPattern: '*kkce-admin*' } ], handleAuthRequests: true } );
	const refresh = panel.getByRole( 'button', { name: 'Refresh', exact: true } );
	const isPreview = ( response ) => response.url().includes( '/kkce-admin/v1/preview' );
	try {
		// With a live session, core replaces an invalid nonce and retries the original request.
		await page.evaluate( () => {
			wp.apiFetch.nonceMiddleware.nonce = 'deliberately-invalid';
		} );
		const refused = page.waitForResponse( ( response ) => isPreview( response ) && 403 === response.status() );
		const retried = page.waitForResponse( ( response ) => isPreview( response ) && 200 === response.status() );
		await refresh.click();
		expect( ( await ( await refused ).json() ).code ).toBe( 'rest_cookie_invalid_nonce' );
		await retried;
		await expect( panel.locator( '.spinner' ) ).not.toHaveClass( /is-active/ );
		await expect( panel.locator( '.kkce-preview-json' ) ).toContainText( 'E2E authentication' );

		// The loaded page retains its nonce after the session cookie disappears.
		await context.clearCookies();
		const expired = page.waitForResponse( isPreview );
		await refresh.click();
		const response = await expired;
		expect( response.status() ).toBe( 403 );
		expect( ( await response.allHeaders() )[ 'www-authenticate' ] ).toBeUndefined();
		await expect( panel.locator( '.kkce-preview-message' ) ).toHaveText( 'Cookie check failed' );
		await expect( panel.locator( '.kkce-preview-json' ) ).toHaveCount( 0 );
		expect( challenges ).toEqual( [] );

		// Restoring the session lets the same editor recover with Refresh, without losing its mapping.
		await context.addCookies( cookies );
		const recovered = page.waitForResponse( ( answer ) => isPreview( answer ) && 200 === answer.status() );
		await refresh.click();
		await recovered;
		await expect( panel.locator( '.kkce-preview-json' ) ).toContainText( 'E2E authentication' );
		await expect( panel.locator( '.kkce-preview-message' ) ).toBeHidden();
		expect( challenges ).toEqual( [] );
	} finally {
		await client.send( 'Fetch.disable' );
		await client.detach();
	}
} );

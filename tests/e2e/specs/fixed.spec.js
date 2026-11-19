/**
 * Rule "Fixed value": text controls, a post type exception and their persistence. Conversions are tested in PHP.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'fixed text and a post type exception survive saving', async ( { page } ) => {
	const data = createFixtures( 'fixed', {
		post_types: [ 'post', 'page' ],
		posts: [ { title: 'E2E fixed post' } ],
		pages: [ { title: 'E2E fixed page' } ],
	} );
	const box = await openFeed( page, data.feed );

	const lang = await addRow( box, 'Fixed value', 'lang' );
	await lang.getByLabel( 'Output type' ).selectOption( { label: 'Text' } );
	await lang.getByLabel( 'Value (For all post types)' ).fill( 'it' );
	await lang.getByLabel( 'Different for' ).selectOption( { label: 'Pages' } );
	await lang.getByRole( 'button', { name: 'Add exception' } ).click();
	await lang.getByLabel( 'Value (For Pages)' ).fill( 'en' );

	await expect( preview( box ) ).toContainText( '"lang": "it"' );
	await expect( preview( box ) ).toContainText( '"lang": "en"' );

	await saveFeed( page );
	const saved = box.getByRole( 'group', { name: /: lang$/ } );
	await expect( saved.getByLabel( 'Value (For all post types)' ) ).toHaveValue( 'it' );
	await expect( saved.getByLabel( 'Value (For Pages)' ) ).toHaveValue( 'en' );
	await expect( saved.getByLabel( 'Output type' ) ).toHaveValue( 'string' );
} );

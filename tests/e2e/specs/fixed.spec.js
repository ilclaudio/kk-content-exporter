/**
 * Rule "Fixed value": a general value, an exception for a post type and the output types.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'fixed values per post type, converted to their output type', async ( { page } ) => {
	const data = createFixtures( 'fixed', {
		post_types: [ 'post', 'page' ],
		posts: [ { title: 'E2E fixed post' } ],
		pages: [ { title: 'E2E fixed page' } ],
	} );
	const box = await openFeed( page, data.feed );

	const lang = await addRow( box, 'Fixed value', 'lang' );
	await lang.getByLabel( 'Value (For all post types)' ).fill( 'it' );
	await lang.getByLabel( 'Different for' ).selectOption( { label: 'Pages' } );
	await lang.getByRole( 'button', { name: 'Add exception' } ).click();
	await lang.getByLabel( 'Value (For Pages)' ).fill( 'en' );

	const answer = await addRow( box, 'Fixed value', 'answer' );
	await answer.getByLabel( 'Output type' ).selectOption( { label: 'Number' } );
	await answer.getByLabel( 'Value (For all post types)' ).fill( '42' );

	const flag = await addRow( box, 'Fixed value', 'featured' );
	await flag.getByLabel( 'Output type' ).selectOption( { label: 'True or false' } );
	await flag.getByLabel( 'Value (For all post types)' ).fill( 'yes' );

	await expect( preview( box ) ).toContainText( '"lang": "it"' );
	await expect( preview( box ) ).toContainText( '"lang": "en"' );
	await expect( preview( box ) ).toContainText( '"answer": 42' );
	await expect( preview( box ) ).toContainText( '"featured": true' );

	await saveFeed( page );
	await page.reload();
	await expect( box.getByRole( 'group', { name: /: lang$/ } ).getByLabel( 'Value (For Pages)' ) ).toHaveValue( 'en' );
	await expect( preview( box ) ).toContainText( '"answer": 42' );
} );

/**
 * Rule "Lookup table": lines added, moved and removed, a default, the exact comparison.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a table translates the post type and falls back to its default', async ( { page } ) => {
	const data = createFixtures( 'lookup', {
		post_types: [ 'post', 'page' ],
		posts: [ { title: 'E2E lookup post' } ],
		pages: [ { title: 'E2E lookup page' } ],
	} );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Lookup table', 'section' );
	await row.getByLabel( 'Field to read (For all post types)' ).selectOption( 'core.type' );

	const matches = row.getByLabel( 'If the value is' );
	const outputs = row.getByLabel( 'Fixed text' );
	await matches.nth( 0 ).fill( 'Post' );
	await outputs.nth( 0 ).fill( 'Capital letters count' );
	await row.getByRole( 'button', { name: 'Add line' } ).click();
	await matches.nth( 1 ).fill( 'post' );
	await outputs.nth( 1 ).fill( 'News' );
	await row.getByRole( 'button', { name: 'Add line' } ).click();
	await matches.nth( 2 ).fill( 'event' );
	await outputs.nth( 2 ).fill( 'Event' );
	await row.getByLabel( 'When no line matches' ).selectOption( { label: 'The value read, unchanged' } );

	// "Post" does not match "post" (exact comparison); the page matches no line and keeps its value.
	await expect( preview( box ) ).toContainText( '"section": "News"' );
	await expect( preview( box ) ).toContainText( '"section": "page"' );

	await row.getByRole( 'button', { name: 'Move up: line 2' } ).click();
	await expect( matches.nth( 0 ) ).toHaveValue( 'post' );
	await row.getByRole( 'button', { name: 'Remove line 3' } ).click();
	await expect( matches ).toHaveCount( 2 );

	await saveFeed( page );
	await page.reload();
	const saved = box.getByRole( 'group', { name: /: section$/ } );
	await expect( saved.getByLabel( 'If the value is' ) ).toHaveCount( 2 );
	await expect( saved.getByLabel( 'If the value is' ).nth( 0 ) ).toHaveValue( 'post' );
	await expect( saved.getByLabel( 'If the value is' ).nth( 1 ) ).toHaveValue( 'Post' );
	await expect( saved.getByLabel( 'When no line matches' ) ).toHaveValue( 'input' );
} );

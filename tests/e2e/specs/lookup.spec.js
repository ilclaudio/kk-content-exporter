/**
 * Rule "Lookup table": lines added, moved and removed, with their outputs and default kept on save.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'lookup lines and the default survive editing and saving', async ( { page } ) => {
	const data = createFixtures( 'lookup', {
		post_types: [ 'post' ],
		posts: [ { title: 'E2E lookup post' } ],
	} );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Lookup table', 'section' );
	// The row started as a Field: its help and its link to the guide follow the rule chosen.
	await expect( row.getByText( 'Translates the value of a field with a table' ) ).toBeVisible();
	const guide = row.getByRole( 'link', { name: 'Read the guide (opens in a new tab)' } );
	await expect( guide ).toHaveAttribute( 'href', /[?&]page=kkce-guide&tab=lookup$/ );
	await expect( guide ).toHaveAttribute( 'target', '_blank' );
	await row.getByLabel( 'Field to read (For all post types)' ).selectOption( 'core.type' );

	const matches = row.getByLabel( 'If the value is' );
	const outputs = row.getByLabel( 'Fixed text' );
	await matches.nth( 0 ).fill( 'event' );
	await outputs.nth( 0 ).fill( 'Events' );
	await row.getByRole( 'button', { name: 'Add line' } ).click();
	await matches.nth( 1 ).fill( 'post' );
	await outputs.nth( 1 ).fill( 'News' );
	await row.getByRole( 'button', { name: 'Add line' } ).click();
	await matches.nth( 2 ).fill( 'page' );
	await outputs.nth( 2 ).fill( 'Pages' );
	await row.getByLabel( 'When no line matches' ).selectOption( { label: 'The value read, unchanged' } );

	await row.getByRole( 'button', { name: 'Move up: line 2' } ).click();
	await expect( matches.nth( 0 ) ).toHaveValue( 'post' );
	await row.getByRole( 'button', { name: 'Remove line 3' } ).click();
	await expect( matches ).toHaveCount( 2 );
	await expect( preview( box ) ).toContainText( '"section": "News"' );

	await saveFeed( page );
	const saved = box.getByRole( 'group', { name: /: section$/ } );
	await expect( saved.getByLabel( 'If the value is' ) ).toHaveCount( 2 );
	await expect( saved.getByLabel( 'If the value is' ).nth( 0 ) ).toHaveValue( 'post' );
	await expect( saved.getByLabel( 'If the value is' ).nth( 1 ) ).toHaveValue( 'event' );
	await expect( saved.getByLabel( 'Fixed text' ).nth( 0 ) ).toHaveValue( 'News' );
	await expect( saved.getByLabel( 'Fixed text' ).nth( 1 ) ).toHaveValue( 'Events' );
	await expect( saved.getByLabel( 'When no line matches' ) ).toHaveValue( 'input' );
} );

/**
 * Rows of the mapping: added, moved, removed, saved in their order.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'rows keep the order chosen with the move and remove buttons', async ( { page } ) => {
	const data = createFixtures( 'rows', { post_types: [ 'post' ], posts: [ { title: 'E2E rows' } ] } );
	const box = await openFeed( page, data.feed );

	for ( const key of [ 'first', 'second', 'third' ] ) {
		const row = await addRow( box, 'Fixed value', key );
		await row.getByLabel( 'Value (For all post types)' ).fill( key );
	}

	await box.getByRole( 'button', { name: 'Move up: row 3' } ).click();
	await expect( box.getByRole( 'group', { name: 'Row 2: third' } ) ).toBeVisible();
	await box.getByRole( 'button', { name: 'Remove row 1' } ).click();
	await expect( box.getByRole( 'group', { name: /^Row \d+/ } ) ).toHaveCount( 2 );

	await saveFeed( page );
	await page.reload();
	await expect( box.getByRole( 'group', { name: /^Row \d+/ } ) ).toHaveCount( 2 );
	await expect( box.getByRole( 'group', { name: 'Row 1: third' } ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 2: second' } ) ).toBeVisible();
} );

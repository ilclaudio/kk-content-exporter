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

	// Saved rows start closed: the header sums them up, the closed controls are hidden.
	const third = box.getByRole( 'group', { name: 'Row 1: third' } );
	const toggle = third.getByRole( 'button', { name: /^third ← "third"/ } );
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
	await expect( third.getByLabel( 'Value (For all post types)' ) ).toBeHidden();

	// Opening and closing a row, or all of them, changes nothing of the feed: leaving does not warn.
	const dialogs = [];
	page.on( 'dialog', async ( dialog ) => {
		dialogs.push( dialog.type() );
		await dialog.accept();
	} );
	await toggle.click();
	await expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
	await expect( third.getByLabel( 'Value (For all post types)' ) ).toHaveValue( 'third' );
	await toggle.click();
	await expect( third.getByLabel( 'Value (For all post types)' ) ).toBeHidden();
	await box.getByRole( 'button', { name: 'Expand all' } ).click();
	await expect( box.getByLabel( 'Value (For all post types)' ) ).toHaveCount( 2 );
	await expect( box.getByLabel( 'Value (For all post types)' ).last() ).toBeVisible();
	await box.getByRole( 'button', { name: 'Collapse all' } ).click();
	await expect( box.getByLabel( 'Value (For all post types)' ).first() ).toBeHidden();
	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed' );
	expect( dialogs ).toEqual( [] );
} );

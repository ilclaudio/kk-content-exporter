/**
 * Post meta sources: only what a browser shows. Which keys are offered, the values and the check of a key
 * are covered by the unit and integration tests.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, openRows, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'hidden meta keys are listed apart and a key typed by hand is kept after a reload', async ( { page } ) => {
	const data = createFixtures( 'meta', {
		post_types: [ 'post' ],
		posts: [ { title: 'E2E meta post', meta: { e2e_place: 'Pisa', _e2e_note: 'Hidden note' } } ],
	} );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Field', 'typed' );
	const source = row.getByLabel( 'Field', { exact: true } );
	await expect( source.locator( 'optgroup[label^="Fields of"] option[value="meta.e2e_place"]' ) ).toHaveCount( 1 );
	await expect( source.locator( 'optgroup[label^="Hidden fields of"] option[value="meta._e2e_note"]' ) ).toHaveCount( 1 );

	// A key that is not in the lists: chosen from the list itself, typed below the field, checked by the server.
	await source.selectOption( { label: 'Other post meta key…' } );
	await expect( source ).toHaveValue( '' );
	await expect( source ).toBeFocused();
	await row.getByLabel( 'Post meta key' ).fill( '_edit_lock' );
	await row.getByRole( 'button', { name: 'Use this key' } ).click();
	await expect( row.getByText( 'This key cannot be used: _edit_lock.', { exact: false } ) ).toBeVisible();
	await expect( source ).toHaveValue( '' );
	await row.getByLabel( 'Post meta key' ).fill( 'e2e_typed_by_hand' );
	await row.getByRole( 'button', { name: 'Use this key' } ).click();
	await expect( source ).toHaveValue( 'meta.e2e_typed_by_hand' );
	await expect( row.getByLabel( 'Post meta key' ) ).toHaveCount( 0 );

	await saveFeed( page );
	await page.reload();
	await openRows( box );
	await expect( box.getByRole( 'group', { name: 'Row 1: typed' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'meta.e2e_typed_by_hand' );
} );

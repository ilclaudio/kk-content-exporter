/**
 * Post meta sources: only what a browser shows. Which keys are offered, the values and the check of a key
 * are covered by the unit and integration tests.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, saveFeed } = require( '../support/editor' );

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

	await box.getByLabel( 'Use another post meta key' ).fill( 'e2e_typed_by_hand' );
	await box.getByRole( 'button', { name: 'Add field' } ).click();
	await expect( box.getByText( 'Field added: e2e_typed_by_hand' ) ).toBeVisible();
	await source.selectOption( 'meta.e2e_typed_by_hand' );

	await saveFeed( page );
	await page.reload();
	await expect( box.getByRole( 'group', { name: 'Row 1: typed' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'meta.e2e_typed_by_hand' );
} );

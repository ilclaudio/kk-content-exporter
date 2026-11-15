/**
 * The Italian translation of the Mapping box is loaded, for the texts printed by PHP and for the controls
 * built by the script (JSON translations).
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { wp, createFixtures, cleanupFixtures } = require( '../support/wp' );

test.beforeEach( () => {
	wp( [ 'user', 'meta', 'update', 'admin', 'locale', 'it_IT' ] );
} );

test.afterEach( () => {
	wp( [ 'user', 'meta', 'delete', 'admin', 'locale' ] );
	cleanupFixtures();
} );

test( 'the Mapping box speaks Italian when the administrator does', async ( { page } ) => {
	const data = createFixtures( 'italian', { post_types: [ 'post' ], posts: [ { title: 'E2E italiano' } ] } );

	await page.goto( '/wp-admin/post.php?post=' + data.feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );

	// Printed by PHP.
	await expect( box.getByText( 'Come funzionano le righe' ) ).toBeVisible();
	// Built by the script.
	await box.getByRole( 'button', { name: 'Aggiungi riga' } ).click();
	const row = box.getByRole( 'group', { name: /^Riga 1/ } );
	await expect( row.getByLabel( 'Chiave JSON' ) ).toBeVisible();
	await expect( row.getByLabel( 'Regola' ) ).toBeVisible();
	await expect( row.getByLabel( 'Tipo di uscita' ) ).toBeVisible();
} );

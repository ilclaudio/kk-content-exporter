/**
 * First E2E test: a Field row is built in the Mapping box, previewed on a published post, saved and
 * still there after a reload; the boxes of the plugin pass the accessibility check.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { expectAccessible } = require( '../support/axe' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a Field row is previewed, saved and kept after a reload', async ( { page } ) => {
	const data = createFixtures( 'smoke', { post_types: [ 'post' ], posts: [ { title: 'E2E smoke title' } ] } );

	await page.goto( '/wp-admin/post.php?post=' + data.feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );

	await box.getByRole( 'button', { name: 'Add row' } ).click();
	const row = box.getByRole( 'group', { name: /^Row 1/ } );
	await row.getByLabel( 'Field', { exact: true } ).selectOption( 'core.title' );
	await expect( row.getByLabel( 'JSON key' ) ).toHaveValue( 'title' );

	await expect( box.locator( '.kkce-preview-json' ).first() ).toContainText( '"title": "E2E smoke title"' );
	await expectAccessible( page, [ '#kkce_feed_settings', '#kkce_feed_mapping' ] );

	await page.getByRole( 'button', { name: 'Update' } ).click();
	await expect( page.locator( '#message' ) ).toContainText( 'updated' );

	await page.reload();
	await expect( box.getByRole( 'group', { name: 'Row 1: title' } ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 1: title' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.title' );
} );

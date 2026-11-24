/**
 * Helpers for the feed editor and its Mapping box.
 *
 * Elements are found by role and label, scoped to the boxes of the plugin; a CSS class is used only to
 * scope a search to a part of the box that has no role of its own.
 *
 * @package KKCE
 */

const { expect } = require( '@playwright/test' );

/**
 * Opens the editor of a feed and gives its Mapping box.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {number}                          feed ID of the feed.
 * @return {Promise<import('@playwright/test').Locator>} The Mapping box.
 */
async function openFeed( page, feed ) {
	await page.goto( '/wp-admin/post.php?post=' + feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );
	await expect( box.getByRole( 'button', { name: 'Add row' } ) ).toBeVisible();
	return box;
}

/**
 * Adds a row and gives it.
 *
 * @param {import('@playwright/test').Locator} box  Mapping box.
 * @param {string}                             rule Label of the rule to choose (the new row is a Field).
 * @param {string}                             key  JSON key to type.
 * @return {Promise<import('@playwright/test').Locator>} The row (its group, named "Row N: key").
 */
async function addRow( box, rule, key ) {
	const before = await box.getByRole( 'group', { name: /^Row \d+/ } ).count();
	await box.getByRole( 'button', { name: 'Add row' } ).click();
	const position = before + 1;
	let row = box.getByRole( 'group', { name: new RegExp( '^Row ' + position + '\\b' ) } );
	if ( 'Field' !== rule ) {
		await row.getByLabel( 'Rule' ).selectOption( { label: rule } );
	}
	await row.getByLabel( 'JSON key' ).fill( key );
	row = box.getByRole( 'group', { name: 'Row ' + position + ': ' + key } );
	await expect( row ).toBeVisible();
	return row;
}

/**
 * Opens the rows of the box: saved rows start closed. "Expand all" when there are two rows or more, otherwise
 * the header of the closed row.
 *
 * @param {import('@playwright/test').Locator} box Mapping box.
 */
async function openRows( box ) {
	const all = box.getByRole( 'button', { name: 'Expand all' } );
	if ( await all.isVisible() ) {
		await all.click();
		return;
	}
	const closed = box.locator( '.kkce-mapping-toggle[aria-expanded="false"]' );
	for ( let left = await closed.count(); left > 0; left-- ) {
		await closed.first().click();
	}
}

/**
 * The preview of the box, once it shows the JSON of the items.
 *
 * @param {import('@playwright/test').Locator} box Mapping box.
 * @return {import('@playwright/test').Locator} The preview section.
 */
function preview( box ) {
	return box.getByRole( 'region', { name: 'Preview' } );
}

/**
 * Saves the feed and waits for the notice of WordPress.
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function saveFeed( page ) {
	await page.getByRole( 'button', { name: 'Update' } ).click();
	await expect( page.locator( '#message' ) ).toContainText( 'updated' );
}

module.exports = { openFeed, addRow, openRows, preview, saveFeed };

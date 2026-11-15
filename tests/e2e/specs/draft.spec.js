/**
 * A refused mapping comes back in the editor with its errors: incomplete rows stay editable, and a row of
 * an unexpected shape does not stop the editor.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures, setRefusedDraft } = require( '../support/wp' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a refused draft keeps incomplete rows editable and survives rows of an unexpected shape', async ( { page } ) => {
	const data = createFixtures( 'draft', {
		post_types: [ 'post' ],
		posts: [ { title: 'E2E draft' } ],
		rows: [ { key: 'saved', kind: 'fixed', type: 'string', values: { '*': 'x' } } ],
	} );
	// As the editor sends them: an empty map for an unfinished Fixed value and Template.
	const refused = {
		version: 1,
		rows: [
			{ key: 'empty', kind: 'fixed', type: 'string', values: {} },
			{ key: 'unfinished', kind: 'template', type: 'string', templates: {} },
			{ key: 'odd', kind: 'lookup', type: 'string', inputs: null, cases: 'x' },
		],
	};
	setRefusedDraft( data.feed, JSON.stringify( refused ), [ { row: 0, field: 'values', code: 'empty' } ] );

	await page.goto( '/wp-admin/post.php?post=' + data.feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );

	await expect( box.getByText( 'This mapping was not saved' ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 1: empty' } ).getByLabel( 'Value (For all post types)' ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 2: unfinished' } ).getByLabel( 'Template (For all post types)', { exact: true } ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 3: odd' } ) ).toContainText( 'cannot be edited here' );
} );

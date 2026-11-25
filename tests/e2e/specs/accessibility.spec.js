/**
 * Accessibility check (axe-core, WCAG 2.x A and AA) of the elements of the plugin: the feed editor with a
 * row of every rule, the list of the feeds, the settings page, the guide.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { expectAccessible } = require( '../support/axe' );

test.afterEach( () => {
	cleanupFixtures();
} );

const ROWS = [
	{ key: 'title', kind: 'field', type: 'string', sources: { '*': [ 'core.title', 'core.slug' ] }, max_length: 80 },
	{ key: 'lang', kind: 'fixed', type: 'string', values: { '*': 'it', page: 'en' } },
	{ key: 'label', kind: 'template', type: 'string', templates: { '*': '{core.title}[ – {core.excerpt}]' } },
	{ key: 'section', kind: 'lookup', type: 'string', inputs: { '*': 'core.type' }, cases: [ { match: 'post', output: { fixed: 'News' } }, { match: 'page', output: { source: 'core.title' } } ], default: { input: true } },
	{ key: 'summary', kind: 'condition', type: 'auto', inputs: { '*': 'core.excerpt' }, operator: 'one_of', matches: [ 'a', 'b' ], then: { source: 'core.title' }, else: { input: true } },
];

test( 'the feed editor with a row of every rule', async ( { page } ) => {
	const data = createFixtures( 'axe-editor', { post_types: [ 'post', 'page' ], posts: [ { title: 'E2E axe' } ], pages: [ { title: 'E2E axe page' } ], rows: ROWS } );

	await page.goto( '/wp-admin/post.php?post=' + data.feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );
	await expect( box.getByRole( 'group', { name: /^Row \d+/ } ) ).toHaveCount( ROWS.length );
	await expect( box.getByRole( 'region', { name: 'Preview' } ) ).toContainText( '"section"' );

	// Saved rows start closed: open them, so the check sees the controls of every rule and the lookup table.
	await box.getByRole( 'button', { name: 'Expand all' } ).click();
	await expect( box.getByRole( 'table' ) ).toBeVisible();

	await expectAccessible( page, [ '#kkce_feed_settings', '#kkce_feed_mapping' ] );
} );

test( 'the list of the feeds, the settings page and the guide', async ( { page } ) => {
	createFixtures( 'axe-list', { post_types: [ 'post' ], rows: ROWS.slice( 0, 1 ) } );

	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed' );
	await expect( page.getByRole( 'link', { name: 'E2E axe-list', exact: true } ) ).toBeVisible();
	await expectAccessible( page, [ '.column-kkce_endpoint', '.column-kkce_source', '.column-kkce_access', '.column-kkce_status' ] );

	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed&page=kkce-settings' );
	await expect( page.getByRole( 'heading', { name: 'Content Exporter settings' } ) ).toBeVisible();
	await expectAccessible( page, [ '#wpbody-content .wrap' ] );

	// The tab with the most elements: lists, two examples with their tables and JSON.
	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed&page=kkce-guide&tab=condition' );
	await expect( page.getByRole( 'link', { name: 'Condition' } ) ).toHaveAttribute( 'aria-current', 'page' );
	await expectAccessible( page, [ '#wpbody-content .wrap' ] );
} );

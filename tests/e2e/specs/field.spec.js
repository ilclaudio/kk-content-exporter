/**
 * Rules "Field" and "Fixed value": a fallback chain and fixed text with post type exceptions, previewed
 * on a post and a page, saved together and read back.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, openRows, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'field fallbacks and fixed values keep post type exceptions after saving', async ( { page } ) => {
	const data = createFixtures( 'field', {
		post_types: [ 'post', 'page' ],
		posts: [ { title: 'E2E field post', excerpt: '' } ],
		pages: [ { title: 'E2E field page' } ],
	} );
	const box = await openFeed( page, data.feed );

	await box.getByRole( 'button', { name: 'Add row' } ).click();
	const row = box.getByRole( 'group', { name: /^Row \d+/ } ).last();
	const general = row.getByRole( 'group', { name: 'For all post types' } );
	await general.getByLabel( 'Field', { exact: true } ).selectOption( 'core.title' );
	await expect( row.getByLabel( 'JSON key' ) ).toHaveValue( 'title' );
	await row.getByLabel( 'JSON key' ).fill( 'summary' );
	await general.getByLabel( 'Field', { exact: true } ).selectOption( 'core.excerpt' );
	await general.getByRole( 'button', { name: /^Add fallback/ } ).click();
	await general.getByLabel( 'Otherwise, field 2' ).selectOption( 'core.title' );

	// Exceptions on request: a button first, the picker only after it; Cancel closes it again.
	await expect( row.getByLabel( 'Different for' ) ).toHaveCount( 0 );
	await row.getByRole( 'button', { name: 'Add exception for a post type' } ).click();
	await expect( row.getByLabel( 'Different for' ) ).toBeFocused();
	await row.getByRole( 'button', { name: 'Cancel' } ).click();
	await expect( row.getByLabel( 'Different for' ) ).toHaveCount( 0 );
	await expect( row.getByRole( 'button', { name: 'Add exception for a post type' } ) ).toBeFocused();
	await row.getByRole( 'button', { name: 'Add exception for a post type' } ).click();
	await row.getByLabel( 'Different for' ).selectOption( { label: 'Pages' } );
	await row.getByRole( 'button', { name: 'Add exception', exact: true } ).click();
	await row.getByRole( 'group', { name: 'For Pages' } ).getByLabel( 'Field', { exact: true } ).selectOption( 'core.slug' );

	// The options of the excerpt are advanced options: a closed section whose title sums up what is set.
	const advanced = row.locator( 'details.kkce-mapping-advanced' );
	await expect( advanced.locator( 'summary' ) ).toHaveText( 'Advanced options' );
	await advanced.locator( 'summary' ).click();
	await row.getByLabel( 'Raw content' ).check();
	await expect( advanced.locator( 'summary' ) ).toHaveText( 'Advanced options (raw content)' );
	await expect( row.getByLabel( 'Run the shortcodes' ) ).toBeDisabled();

	// The post has no excerpt: its title is used. The page reads its slug.
	await expect( preview( box ) ).toContainText( '"summary": "E2E field post"' );
	await expect( preview( box ) ).toContainText( '"summary": "kkce-e2e-field-page-0"' );

	// Regression: a field that only the posts offer (their categories) is a field of the feed, so the preview of
	// the pages shows the value as missing instead of an error of the mapping.
	const category = await addRow( box, 'Field', 'category' );
	await category.getByRole( 'group', { name: 'For all post types' } ).getByLabel( 'Field', { exact: true } ).selectOption( 'taxonomy.category.first' );
	await category.getByLabel( 'JSON key' ).fill( 'category' );
	await expect( preview( box ) ).toContainText( '"category": "Uncategorized"' );
	await expect( preview( box ) ).toContainText( '"category": null' );
	await expect( preview( box ).getByText( 'The preview is not up to date' ) ).toHaveCount( 0 );
	await category.getByRole( 'button', { name: /^Remove row/ } ).click();

	// Compact preview: a panel per post type, open, whose title names the type and the item; the JSON has a
	// limited height until it is expanded, and can be copied (or selected, where the browser cannot copy).
	const posts = preview( box ).locator( 'details.kkce-preview-type', { has: page.locator( 'summary', { hasText: /^Posts — E2E field post \(ID \d+\)$/ } ) } );
	await expect( posts ).toHaveAttribute( 'open', '' );
	await expect( preview( box ).locator( 'summary', { hasText: /^Pages — / } ) ).toBeVisible();
	const expand = posts.getByRole( 'button', { name: 'Expand (Posts)' } );
	await expect( expand ).toHaveAttribute( 'aria-expanded', 'false' );
	await expand.click();
	await expect( posts.getByRole( 'button', { name: 'Collapse (Posts)' } ) ).toHaveAttribute( 'aria-expanded', 'true' );
	await posts.getByRole( 'button', { name: 'Copy JSON (Posts)' } ).click();
	await expect( posts.getByRole( 'status' ) ).toHaveText( /^(JSON copied\.|.*The JSON is selected: press Ctrl\+C)/ );
	await posts.locator( 'summary' ).click();
	await expect( posts ).not.toHaveAttribute( 'open', '' );
	await posts.locator( 'summary' ).click();

	const lang = await addRow( box, 'Fixed value', 'lang' );
	await lang.getByLabel( 'Output type' ).selectOption( { label: 'Text' } );
	await lang.getByLabel( 'Value (For all post types)' ).fill( 'it' );
	await lang.getByRole( 'button', { name: 'Add exception for a post type' } ).click();
	await lang.getByLabel( 'Different for' ).selectOption( { label: 'Pages' } );
	await lang.getByRole( 'button', { name: 'Add exception', exact: true } ).click();
	await lang.getByLabel( 'Value (For Pages)' ).fill( 'en' );

	await expect( preview( box ) ).toContainText( '"lang": "it"' );
	await expect( preview( box ) ).toContainText( '"lang": "en"' );

	await saveFeed( page );
	await page.reload();
	// Saved rows start closed.
	await openRows( box );

	const saved = box.getByRole( 'group', { name: /: summary$/ } );
	await expect( saved.getByRole( 'group', { name: 'For all post types' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.excerpt' );
	await expect( saved.getByLabel( 'Otherwise, field 2' ) ).toHaveValue( 'core.title' );
	await expect( saved.getByRole( 'group', { name: 'For Pages' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.slug' );
	await expect( saved.locator( 'details.kkce-mapping-advanced' ) ).not.toHaveAttribute( 'open', '' );
	await expect( saved.locator( 'details.kkce-mapping-advanced summary' ) ).toHaveText( 'Advanced options (raw content)' );
	await expect( preview( box ) ).toContainText( '"summary": "kkce-e2e-field-page-0"' );

	const savedLang = box.getByRole( 'group', { name: /: lang$/ } );
	await expect( savedLang.getByLabel( 'Value (For all post types)' ) ).toHaveValue( 'it' );
	await expect( savedLang.getByLabel( 'Value (For Pages)' ) ).toHaveValue( 'en' );
	await expect( savedLang.getByLabel( 'Output type' ) ).toHaveValue( 'string' );
} );

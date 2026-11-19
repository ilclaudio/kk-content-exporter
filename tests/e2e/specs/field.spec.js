/**
 * Rule "Field": a chain with a fallback and an exception for a post type, previewed on a post and a page,
 * saved and read back.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a chain falls back to the next field and a post type can read other fields', async ( { page } ) => {
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

	await row.getByLabel( 'Different for' ).selectOption( { label: 'Pages' } );
	await row.getByRole( 'button', { name: 'Add exception' } ).click();
	await row.getByRole( 'group', { name: 'For Pages' } ).getByLabel( 'Field', { exact: true } ).selectOption( 'core.slug' );

	// The post has no excerpt: its title is used. The page reads its slug.
	await expect( preview( box ) ).toContainText( '"summary": "E2E field post"' );
	await expect( preview( box ) ).toContainText( '"summary": "kkce-e2e-field-page-0"' );

	await saveFeed( page );
	await page.reload();

	const saved = box.getByRole( 'group', { name: /: summary$/ } );
	await expect( saved.getByRole( 'group', { name: 'For all post types' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.excerpt' );
	await expect( saved.getByLabel( 'Otherwise, field 2' ) ).toHaveValue( 'core.title' );
	await expect( saved.getByRole( 'group', { name: 'For Pages' } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.slug' );
	await expect( preview( box ) ).toContainText( '"summary": "kkce-e2e-field-page-0"' );
} );

/**
 * Rule "Condition": the checks, their values and the two outputs.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a check chooses between two outputs', async ( { page } ) => {
	const data = createFixtures( 'condition', {
		post_types: [ 'post' ],
		posts: [ { title: 'Breaking: storm hits the city', excerpt: '' } ],
	} );
	const box = await openFeed( page, data.feed );

	// The excerpt, or the title when the excerpt is empty.
	const summary = await addRow( box, 'Condition', 'summary' );
	await summary.getByLabel( 'Field to read (For all post types)' ).selectOption( 'core.excerpt' );
	await summary.getByLabel( 'The field' ).selectOption( { label: 'is empty' } );
	await summary.getByLabel( 'If true, write' ).selectOption( { label: 'Field' } );
	await summary.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'If true, write' ) } ).getByLabel( 'Field', { exact: true } ).selectOption( 'core.title' );
	await summary.getByLabel( 'Otherwise, write' ).selectOption( { label: 'The value read, unchanged' } );
	await expect( preview( box ) ).toContainText( '"summary": "Breaking: storm hits the city"' );

	// "contains" looks for a text inside the value.
	const breaking = await addRow( box, 'Condition', 'breaking' );
	await breaking.getByLabel( 'Field to read (For all post types)' ).selectOption( 'core.title' );
	await breaking.getByLabel( 'The field' ).selectOption( { label: 'contains' } );
	await breaking.getByLabel( 'Value', { exact: true } ).fill( 'Breaking' );
	await breaking.getByLabel( 'If true, write' ).selectOption( { label: 'Fixed text' } );
	await breaking.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'If true, write' ) } ).getByLabel( 'Fixed text' ).fill( 'yes' );
	await breaking.getByLabel( 'Otherwise, write' ).selectOption( { label: 'Fixed text' } );
	await breaking.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'Otherwise, write' ) } ).getByLabel( 'Fixed text' ).fill( 'no' );
	await expect( preview( box ) ).toContainText( '"breaking": "yes"' );

	// "is one of" with one value per line: the title is not among them.
	await breaking.getByLabel( 'The field' ).selectOption( { label: 'is one of' } );
	await breaking.getByLabel( 'Values, one per line' ).fill( 'News\nSport' );
	await expect( preview( box ) ).toContainText( '"breaking": "no"' );

	await saveFeed( page );
	await page.reload();
	const saved = box.getByRole( 'group', { name: /: breaking$/ } );
	await expect( saved.getByLabel( 'The field' ) ).toHaveValue( 'one_of' );
	await expect( saved.getByLabel( 'Values, one per line' ) ).toHaveValue( 'News\nSport' );
	await expect( preview( box ) ).toContainText( '"summary": "Breaking: storm hits the city"' );
} );

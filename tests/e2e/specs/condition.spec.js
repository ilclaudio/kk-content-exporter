/**
 * Rule "Condition": changing the comparison controls and preserving its outputs on save.
 * Operator semantics are covered by the unit and integration suites.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'condition controls switch between checks and keep their outputs on save', async ( { page } ) => {
	const data = createFixtures( 'condition', {
		post_types: [ 'post' ],
		posts: [ { title: 'Breaking: storm hits the city', excerpt: '' } ],
	} );
	const box = await openFeed( page, data.feed );

	const summary = await addRow( box, 'Condition', 'summary' );
	await summary.getByLabel( 'Field to read (For all post types)' ).selectOption( 'core.title' );
	await expect( summary.getByLabel( 'Value', { exact: true } ) ).toHaveCount( 0 );
	await summary.getByLabel( 'The field' ).selectOption( { label: 'contains' } );
	await summary.getByLabel( 'Value', { exact: true } ).fill( 'Breaking' );

	await summary.getByLabel( 'If true, write' ).selectOption( { label: 'Field' } );
	await summary.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'If true, write' ) } ).getByLabel( 'Field', { exact: true } ).selectOption( 'core.title' );
	await summary.getByLabel( 'Otherwise, write' ).selectOption( { label: 'Fixed text' } );
	await summary.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'Otherwise, write' ) } ).getByLabel( 'Fixed text' ).fill( 'Other' );

	await summary.getByLabel( 'The field' ).selectOption( { label: 'is one of' } );
	await summary.getByLabel( 'Values, one per line' ).fill( 'News\nSport' );
	await expect( summary.getByLabel( 'Value', { exact: true } ) ).toHaveCount( 0 );
	await expect( preview( box ) ).toContainText( '"summary": "Other"' );

	await saveFeed( page );
	const saved = box.getByRole( 'group', { name: /: summary$/ } );
	await expect( saved.getByLabel( 'The field' ) ).toHaveValue( 'one_of' );
	await expect( saved.getByLabel( 'Values, one per line' ) ).toHaveValue( 'News\nSport' );
	await expect( saved.getByLabel( 'If true, write' ) ).toHaveValue( 'source' );
	await expect( saved.locator( '.kkce-mapping-default' ).filter( { has: page.getByLabel( 'If true, write' ) } ).getByLabel( 'Field', { exact: true } ) ).toHaveValue( 'core.title' );
	await expect( saved.getByLabel( 'Otherwise, write' ) ).toHaveValue( 'fixed' );
	await expect( saved.getByLabel( 'Fixed text' ) ).toHaveValue( 'Other' );
} );

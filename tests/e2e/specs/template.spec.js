/**
 * Rule "Template": fields between braces, fallbacks, an optional part, the maximum length; the preview of
 * an item chosen from the list.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, preview, saveFeed } = require( '../support/editor' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'an optional part is written only when its field has a value', async ( { page } ) => {
	const data = createFixtures( 'template', {
		post_types: [ 'post' ],
		posts: [
			{ title: 'E2E with excerpt', excerpt: 'Short excerpt' },
			{ title: 'E2E without excerpt', excerpt: '' },
		],
	} );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Template', 'label' );
	await row.getByLabel( 'Template (For all post types)', { exact: true } ).fill( '{core.title}[ – {core.excerpt}] ({core.excerpt|"n.d."})' );

	// The latest post has no excerpt: the optional part is left out, the fallback is used.
	await expect( preview( box ) ).toContainText( '"label": "E2E without excerpt (n.d.)"' );

	await preview( box ).getByLabel( 'Item of Posts' ).selectOption( String( data.posts[ 0 ] ) );
	await expect( preview( box ) ).toContainText( '"label": "E2E with excerpt – Short excerpt (Short excerpt)"' );

	await saveFeed( page );
	await page.reload();
	await expect( box.getByRole( 'group', { name: /: label$/ } ).getByLabel( 'Template (For all post types)', { exact: true } ) ).toHaveValue( '{core.title}[ – {core.excerpt}] ({core.excerpt|"n.d."})' );
} );

test( 'a sensitive field typed in a template asks for its confirmation at once', async ( { page } ) => {
	const data = createFixtures( 'template-sensitive', { post_types: [ 'post' ], posts: [ { title: 'E2E sensitive' } ] } );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Template', 'author' );
	const template = row.getByLabel( 'Template (For all post types)', { exact: true } );
	await template.fill( 'By {core.author.url}' );

	const confirm = row.getByRole( 'checkbox', { name: 'I understand, publish this value (Author archive URL)' } );
	await expect( confirm ).toBeVisible();
	await expect( template ).toBeFocused();
	await confirm.check();
	await expect( preview( box ) ).toContainText( '"author": "By http' );

	await template.fill( 'By {core.title}' );
	await expect( confirm ).toHaveCount( 0 );
} );

test( 'the text length limit is serialized only for text and kept on save', async ( { page } ) => {
	const data = createFixtures( 'maxlength', {
		post_types: [ 'post' ],
		posts: [ { title: 'Concerto di musica classica al teatro' } ],
	} );
	const box = await openFeed( page, data.feed );

	const row = await addRow( box, 'Field', 'short' );
	await row.getByLabel( 'Field', { exact: true } ).selectOption( 'core.title' );
	await row.getByLabel( 'Output type' ).selectOption( { label: 'Text' } );
	await row.getByLabel( 'Maximum length' ).fill( '20' );
	// Check the browser's submitted mapping; the truncation algorithm is covered by PHP tests.
	const serializedRow = async () => JSON.parse( await box.locator( 'input[name="kkce_feed[mapping]"]' ).inputValue() ).rows.find( ( item ) => 'short' === item.key );
	expect( await serializedRow() ).toHaveProperty( 'max_length', 20 );

	await row.getByLabel( 'Output type' ).selectOption( { label: 'HTML' } );
	await expect( row.getByLabel( 'Maximum length' ) ).toHaveCount( 0 );
	expect( await serializedRow() ).not.toHaveProperty( 'max_length' );

	await row.getByLabel( 'Output type' ).selectOption( { label: 'Text' } );
	await expect( row.getByLabel( 'Maximum length' ) ).toHaveValue( '20' );
	expect( await serializedRow() ).toHaveProperty( 'max_length', 20 );
	await saveFeed( page );
	await expect( box.getByRole( 'group', { name: /: short$/ } ).getByLabel( 'Maximum length' ) ).toHaveValue( '20' );
} );

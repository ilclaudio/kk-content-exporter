/**
 * Rule "Template": fields between braces, fallbacks, an optional part, the maximum length; the preview of
 * an item chosen from the list.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );
const { openFeed, addRow, openRows, preview, saveFeed } = require( '../support/editor' );

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
	// Saved rows start closed.
	await openRows( box );
	await expect( box.getByRole( 'group', { name: /: label$/ } ).getByLabel( 'Template (For all post types)', { exact: true } ) ).toHaveValue( '{core.title}[ – {core.excerpt}] ({core.excerpt|"n.d."})' );

	// A preview-only selection must not warn on leaving; editing the mapping still must.
	const dialogs = [];
	page.on( 'dialog', async ( dialog ) => {
		dialogs.push( dialog.type() );
		await dialog.accept();
	} );
	await preview( box ).getByLabel( 'Item of Posts' ).selectOption( String( data.posts[ 0 ] ) );
	await expect( preview( box ) ).toContainText( '"label": "E2E with excerpt – Short excerpt (Short excerpt)"' );
	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed' );
	expect( dialogs ).toEqual( [] );

	const reopened = await openFeed( page, data.feed );
	await openRows( reopened );
	await reopened.getByLabel( 'Template (For all post types)', { exact: true } ).fill( '{core.title} changed' );
	await page.goto( '/wp-admin/edit.php?post_type=kkce_feed' );
	expect( dialogs ).toEqual( [ 'beforeunload' ] );
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
	// The author of the item is `admin`, whose archive URL shows the login name: the preview says so.
	const notice = preview( box ).getByText( 'In this item the author archive URL contains the login name of the author' );
	await expect( notice ).toBeVisible();

	await template.fill( 'By {core.title}' );
	await expect( confirm ).toHaveCount( 0 );
	await expect( notice ).toHaveCount( 0 );
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
	// The limit is an advanced option: its section starts closed and its title sums up what is set.
	const advanced = row.locator( 'details.kkce-mapping-advanced' );
	await expect( row.getByLabel( 'Maximum length' ) ).toBeHidden();
	await advanced.locator( 'summary' ).click();
	await row.getByLabel( 'Maximum length' ).fill( '20' );
	await expect( advanced.locator( 'summary' ) ).toHaveText( 'Advanced options (maximum length 20)' );
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
	const saved = box.getByRole( 'group', { name: /: short$/ } );
	// After the reload the section is closed again, and its title still says what is set.
	await expect( saved.locator( 'details.kkce-mapping-advanced' ) ).not.toHaveAttribute( 'open', '' );
	await expect( saved.locator( 'details.kkce-mapping-advanced summary' ) ).toHaveText( 'Advanced options (maximum length 20)' );
	await expect( saved.getByLabel( 'Maximum length' ) ).toHaveValue( '20' );
} );

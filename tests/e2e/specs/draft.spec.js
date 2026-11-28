/**
 * A refused mapping comes back in the editor with its errors: incomplete rows stay editable, and a row of
 * an unexpected shape does not stop the editor.
 *
 * @package KKCE
 */

const { test, expect } = require( '@playwright/test' );
const { createFixtures, cleanupFixtures } = require( '../support/wp' );

test.afterEach( () => {
	cleanupFixtures();
} );

test( 'a refused draft keeps incomplete rows editable and survives rows of an unexpected shape', async ( { page } ) => {
	const pageErrors = [];
	page.on( 'pageerror', error => pageErrors.push( error.message ) );
	// JSON can carry objects where the controls expect text. Such rows must stay uneditable, without
	// coercing their values to strings or reading their sources during initialization.
	const invalidText = { toString: null };
	const malformed = [
		{ key: 'bad-source', kind: 'field', sources: { '*': [ invalidText ] } },
		{ key: 'bad-value', kind: 'fixed', values: { '*': invalidText } },
		{ key: 'bad-template', kind: 'template', templates: { '*': invalidText } },
		{ key: 'bad-input', kind: 'lookup', inputs: { '*': invalidText }, cases: [] },
		{ key: 'bad-match', kind: 'lookup', inputs: {}, cases: [ { match: invalidText, output: {} } ] },
		{ key: 'bad-output', kind: 'lookup', inputs: {}, cases: [ { match: 'x', output: { source: invalidText } } ] },
		{ key: 'bad-default', kind: 'lookup', inputs: {}, cases: [], default: { fixed: invalidText } },
		{ key: 'bad-condition-input', kind: 'condition', inputs: { '*': invalidText }, operator: 'empty' },
		{ key: 'bad-matches', kind: 'condition', inputs: {}, operator: 'one_of', matches: [ invalidText ] },
		{ key: 'bad-then', kind: 'condition', inputs: {}, operator: 'empty', then: { source: invalidText } },
		{ key: 'bad-else', kind: 'condition', inputs: {}, operator: 'empty', else: { fixed: invalidText } },
		{ key: 'bad-options', kind: 'field', sources: {}, options: { raw: invalidText } },
		{ key: 'bad-limit', kind: 'field', sources: {}, max_length: invalidText },
		{ key: 'bad-type', kind: 'field', sources: {}, type: invalidText },
		{ key: invalidText, kind: 'field', sources: {} },
	];
	// As the editor sends them: an empty map for an unfinished Fixed value and Template.
	const refused = {
		version: 1,
		rows: [
			{ key: 'empty', kind: 'fixed', type: 'string', values: {} },
			{ key: 'unfinished', kind: 'template', type: 'string', templates: {} },
			{ key: 'odd', kind: 'lookup', type: 'string', inputs: null, cases: 'x' },
			...malformed,
			{ key: 'zero', kind: 'fixed', type: 'number', values: { '*': 0 } },
			{ key: 'false', kind: 'fixed', type: 'boolean', values: { '*': false } },
			{ key: 'html', kind: 'template', type: 'html', templates: { '*': '<b>{core.title}</b>' } },
		],
	};
	const data = createFixtures( 'draft', {
		post_types: [ 'post' ],
		posts: [ { title: 'E2E draft' } ],
		rows: [ { key: 'saved', kind: 'fixed', type: 'string', values: { '*': 'x' } } ],
		draft: { mapping: JSON.stringify( refused ), errors: [ { row: 0, field: 'values', code: 'empty' } ] },
	} );

	await page.goto( '/wp-admin/post.php?post=' + data.feed + '&action=edit' );
	const box = page.locator( '#kkce_feed_mapping' );

	await expect( box.getByText( 'This mapping was not saved' ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 1: empty' } ).getByLabel( 'Value (For all post types)' ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 2: unfinished' } ).getByLabel( 'Template (For all post types)', { exact: true } ) ).toBeVisible();
	await expect( box.getByRole( 'group', { name: 'Row 3: odd' } ) ).toContainText( 'cannot be edited here' );
	for ( const [ index, row ] of malformed.entries() ) {
		const name = 'string' === typeof row.key ? `Row ${ index + 4 }: ${ row.key }` : `Row ${ index + 4 } (no key)`;
		await expect( box.getByRole( 'group', { name, exact: true } ) ).toContainText( 'cannot be edited here' );
	}
	await expect( box.getByLabel( 'Value (For all post types)', { exact: true } ) ).toHaveCount( 3 );
	await expect( box.getByRole( 'group', { name: /: zero$/ } ).getByLabel( 'Value (For all post types)', { exact: true } ) ).toHaveValue( '0' );
	await expect( box.getByRole( 'group', { name: /: false$/ } ).getByLabel( 'Value (For all post types)', { exact: true } ) ).toHaveValue( 'false' );
	await expect( box.getByRole( 'group', { name: /: html$/ } ).getByLabel( 'Template (For all post types)', { exact: true } ) ).toHaveValue( '<b>{core.title}</b>' );
	const submitted = JSON.parse( await box.locator( 'input[name="kkce_feed[mapping]"]' ).inputValue() );
	expect( submitted.rows.slice( 3, 3 + malformed.length ) ).toEqual( malformed );
	// Fixing a malformed common field can make a row editable. Its empty general scope must then be prepared
	// before drawing the controls, just as for a row that was editable when the page opened.
	const badType = box.getByRole( 'group', { name: 'Row 17: bad-type', exact: true } );
	await badType.getByLabel( 'Output type', { exact: true } ).selectOption( 'string' );
	await expect( badType.getByLabel( 'Field', { exact: true } ) ).toBeVisible();
	const badKey = box.getByRole( 'group', { name: 'Row 18 (no key)', exact: true } );
	await badKey.getByLabel( 'JSON key', { exact: true } ).fill( 'recovered' );
	const recovered = box.getByRole( 'group', { name: 'Row 18: recovered', exact: true } );
	await recovered.getByLabel( 'Output type', { exact: true } ).selectOption( 'string' );
	await expect( recovered.getByLabel( 'Field', { exact: true } ) ).toBeVisible();
	expect( pageErrors ).toEqual( [] );
} );

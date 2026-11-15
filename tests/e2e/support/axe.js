/**
 * Accessibility check of the elements of the plugin with axe-core.
 *
 * Only the given parts of the page are analyzed (the boxes and screens of the plugin), so violations of
 * WordPress itself do not fail the suite. A violation found here is a defect of the plugin: fix it or
 * record it as an issue, never hide it with an exclusion.
 *
 * @package KKCE
 */

const { expect } = require( '@playwright/test' );
const { AxeBuilder } = require( '@axe-core/playwright' );

/**
 * Asserts that the given elements have no accessibility violation (WCAG 2.x A and AA rules).
 *
 * @param {import('@playwright/test').Page} page      Page.
 * @param {string[]}                        selectors CSS selectors of the elements of the plugin.
 */
async function expectAccessible( page, selectors ) {
	let builder = new AxeBuilder( { page } ).withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] );
	for ( const selector of selectors ) {
		builder = builder.include( selector );
	}
	const results = await builder.analyze();
	const summary = results.violations.map( ( violation ) => violation.id + ': ' + violation.help + ' (' + violation.nodes.map( ( node ) => node.target.join( ' ' ) ).join( ', ' ) + ')' );

	expect( summary, 'Accessibility violations' ).toEqual( [] );
}

module.exports = { expectAccessible };

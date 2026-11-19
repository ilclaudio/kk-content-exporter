/**
 * Playwright configuration of the E2E suite (tests/e2e). Runs against the wp-env development environment
 * (`.wp-env.e2etest.json`, http://localhost:8890), started with `npm run env:start`.
 *
 * One worker and no retries: tests are independent but share one WordPress, and a failure must be seen,
 * not hidden by a retry. Traces and screenshots are kept for failed tests only.
 *
 * @package KKCE
 */

const { defineConfig, devices } = require( '@playwright/test' );
const { AUTH_FILE } = require( './tests/e2e/support/paths' );

module.exports = defineConfig( {
	testDir: './tests/e2e/specs',
	outputDir: './test-results',
	fullyParallel: false,
	workers: 1,
	retries: 0,
	forbidOnly: !! process.env.CI,
	timeout: 60000,
	expect: { timeout: 10000 },
	reporter: [ [ 'list' ], [ 'html', { outputFolder: 'playwright-report', open: 'never' } ] ],
	globalSetup: require.resolve( './tests/e2e/support/global-setup.js' ),
	use: {
		baseURL: 'http://localhost:8890',
		storageState: AUTH_FILE,
		trace: 'retain-on-failure',
		screenshot: 'only-on-failure',
		video: 'off',
		locale: 'en-US',
	},
	projects: [ { name: 'chromium', use: { ...devices[ 'Desktop Chrome' ], viewport: { width: 1440, height: 900 } } } ],
} );

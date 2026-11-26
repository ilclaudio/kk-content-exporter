/**
 * Runs the end-to-end suite: starts the wp-env development environment (`.wp-env.e2etest.json`, port 8890; nothing
 * happens when it is already running), then runs Playwright. Extra arguments go to Playwright, for example
 * `composer run test:e2e -- -g "previewed"`; `--headed` and `--ui` are passed by the `test:e2e:headed` and `test:e2e:ui`
 * scripts.
 *
 * Commands run through tests/bin/tools.mjs: arguments as arrays, never through a shell, the same on Windows.
 *
 * @package KKCE
 */

import { requireDocker } from './docker.mjs';
import { bin, run } from './tools.mjs';

requireDocker();

run(
	bin( '@wordpress/env', 'wp-env' ),
	[ 'start', '--config=.wp-env.e2etest.json' ],
	'The wp-env development environment could not start. Is Docker Desktop running? See tests/e2e/README.md.'
);

run( bin( '@playwright/test', 'playwright' ), [ 'test', ...process.argv.slice( 2 ) ], 'The end-to-end suite failed.' );

# End-to-end tests

Playwright tests of the admin screens of the plugin, in Chromium, against a disposable WordPress started
with [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) (wp-env). They cover what the unit
and integration suites cannot see: the mapping editor in the browser, its preview, the save of a feed,
the translations of the script, the accessibility of the screens.

Before the first run: the tools of the root [`README.md`](../../README.md) (*Development setup*: `npm ci`
and `npx playwright install chromium`) and Docker Desktop running.

## Environment

`.wp-env.json` describes the development environment used by this suite: WordPress 7.1.4 and PHP 8.3
(fixed: change them on purpose), this folder mounted as the plugin, `WP_DEBUG` on with the log in the
container. It answers at **http://localhost:8890**, user `admin`, password `password` (public defaults of
wp-env, no real data). Local changes go in `.wp-env.override.json` (ignored by Git). The integration
suite uses a separate environment ([`../integration/README.md`](../integration/README.md)).

`composer run e2e` starts it when it is off (the first start downloads WordPress and the Docker images)
and leaves it on afterwards. To manage it by hand:

```sh
composer run env:start    # start, for example to open it in a browser
composer run env:stop     # stop; the data stays
composer run env:destroy  # delete containers, database and volumes; the next start reinstalls WordPress
composer run env:stop:all # stop this environment and the integration one
```

## Running

```sh
composer run e2e                                   # whole suite, headless (about 4 minutes)
composer run e2e -- tests/e2e/specs/smoke.spec.js  # one file
composer run e2e -- -g "previewed"                 # tests whose title matches
composer run e2e:headed                            # with a visible browser
composer run e2e:ui                                # Playwright UI mode: pick tests, step through them
composer run e2e:report                            # open the HTML report of the last run
```

Configuration: `playwright.config.js` (Chromium, one worker, no retries, 60 s per test). The commands go
through `tests/bin/e2e.mjs`, which starts the environment and then Playwright with the given arguments. The
global setup (`support/global-setup.js`) checks that the environment answers, deletes the data left by an
interrupted run and signs in the administrator once;
the session is kept in `.auth/` (ignored by Git) and reused by every test. The environment stays on
after the run.

## Test list

One row per test, with its file, its exact title and what it does. **Keep this list up to date:** add,
change or remove the row in the same change that adds, changes or removes a test. The contract test
`KKCE_E2E_Catalog_Unit_Test` (unit suite) fails when a test is missing from the list or a row matches no
test.

| File | Test | What it does |
|---|---|---|
| `smoke.spec.js` | `a Field row is previewed, saved and kept after a reload` | Adds a Field row on the title of a post-only feed, checks the proposed key `title` and the JSON of the preview, runs axe on the two boxes, saves the feed and finds the row again after the reload. |
| `field.spec.js` | `a chain falls back to the next field and a post type can read other fields` | Builds a chain "excerpt, otherwise title" for every post type and an exception that reads the slug for pages; the preview shows the title for a post without excerpt and the slug for the page; the chain and the exception are kept after save and reload. |
| `fixed.spec.js` | `fixed values per post type, converted to their output type` | Writes a fixed value `it` with the exception `en` for pages, a value `42` with the number type and `yes` with the true/false type; the preview shows `"it"`, `"en"`, `42` and `true`; the exception is kept after save and reload. |
| `template.spec.js` | `an optional part is written only when its field has a value` | Writes `{core.title}[ – {core.excerpt}] ({core.excerpt\|"n.d."})`; on the latest post (no excerpt) the optional part disappears and the fallback writes `n.d.`; choosing the other post in the preview shows both; the template is kept after save and reload. |
| `template.spec.js` | `a text output is cut at a whole word; the limit is offered only for text` | Sets a maximum length of 20 on the title with the text type: the preview cuts it at a whole word with `…`; the field disappears with the HTML type and comes back with its value; the limit is kept after save and reload. |
| `lookup.spec.js` | `a table translates the post type and falls back to its default` | Builds a table on the post type with the lines `Post`, `post` and `event` and the default "the value read": `post` gives `News` (`Post` does not match: exact comparison), the page keeps `page`; moves a line up and removes another; the order and the default are kept after save and reload. |
| `condition.spec.js` | `a check chooses between two outputs` | A row writes the title when the excerpt is empty, otherwise the excerpt; another one writes `yes` when the title contains `Breaking` and `no` otherwise, then switches to "is one of" with two lines that do not match and writes `no`; the check and its values are kept after save and reload. |
| `rows.spec.js` | `rows keep the order chosen with the move and remove buttons` | Adds three rows, moves the third up and removes the first; after save and reload there are two rows, in the chosen order. |
| `italian.spec.js` | `the Mapping box speaks Italian when the administrator does` | With the Italian language for the administrator, finds the help printed by PHP and the button, the row and its labels built by the script in Italian (JSON translations); the language is reset afterwards. |
| `accessibility.spec.js` | `the feed editor with a row of every rule` | Opens a feed with a saved row of every rule and its preview, then runs axe (WCAG 2.x A and AA) on the two boxes of the plugin. |
| `accessibility.spec.js` | `the list of the feeds and the settings page` | Runs axe on the columns of the plugin in the list of the feeds and on the settings page. |

## Writing a test

- **Data:** every test creates what it needs with `createFixtures( name, options )` and deletes it in
  `afterEach` with `cleanupFixtures()` (`support/wp.js`). The data is made by WP-CLI inside the container
  (`support/fixtures.php`): a feed with its post types and, if given, a saved mapping (`rows`), plus
  published posts and pages. Every object has a slug that starts with `kkce-e2e-`, and the cleanup
  deletes only those objects. `wp( [ ... ] )` runs any other WP-CLI command.
- **Editor:** `support/editor.js` opens a feed (`openFeed`), adds a row with a rule and a key (`addRow`),
  gives the preview (`preview`) and saves (`saveFeed`).
- **Selectors:** by role and label (`getByRole`, `getByLabel`), scoped to the boxes of the plugin
  (`#kkce_feed_settings`, `#kkce_feed_mapping`) or to a row (`getByRole( 'group', { name: 'Row 1: key' } )`);
  `{ exact: true }` when a label is part of another one. No `data-testid` in the plugin.
- **Waiting:** on visible states (`toHaveValue`, `toContainText`, `toBeVisible`), never on fixed times.
- **Accessibility:** `expectAccessible( page, selectors )` (`support/axe.js`) checks the WCAG 2.x A and
  AA rules on the given elements of the plugin only. A violation is a defect of the plugin: fix it or
  record it as an issue, never exclude it.
- **Stability:** one worker and no retries: an unstable test is fixed, not retried.

## When a test fails

The HTML report (`playwright-report/`) and, for the failed tests only, a trace and a screenshot
(`test-results/<test>/`) are kept; both folders are ignored by Git and left out of the ZIP. Open a trace
with `npx playwright show-trace test-results/<test>/trace.zip`, or run the test again with
`composer run e2e:headed` or `composer run e2e:ui`. The PHP log of WordPress is in the container:
`npx wp-env run cli -- tail wp-content/debug.log`.

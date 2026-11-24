# End-to-end tests

Playwright tests of the admin screens of the plugin, in Chromium, against a disposable WordPress started
with [`@wordpress/env`](https://www.npmjs.com/package/@wordpress/env) (wp-env). They cover what the unit
and integration suites cannot see: the mapping editor in the browser, its preview, the save of a feed,
the translations of the script, the accessibility of the screens.

End-to-end tests are the most expensive level of the project: write one only when strictly necessary, after
considering a unit and an integration test (*Order of the test levels* in `AGENTS/AI_BEHAVIOR.md`).

Before the first run: the tools of the root [`README.md`](../../README.md) (*Development setup*: `npm ci`
and `npx playwright install chromium`) and Docker Desktop running.

## Environment

`.wp-env.e2etest.json` describes the development environment used by this suite: WordPress 7.1.4 and PHP 8.3
(fixed: change them on purpose), this folder mounted as the plugin, `WP_DEBUG` on with the log in the
container. It answers at **http://localhost:8890**, user `admin`, password `password` (public defaults of
wp-env, no real data). Local changes go in `.wp-env.e2etest.override.json` (ignored by Git). The integration
suite uses a separate environment ([`../integration/README.md`](../integration/README.md)).

`composer run test:e2e` starts it when it is off (the first start downloads WordPress and the Docker images)
and leaves it on afterwards. To manage it by hand:

```sh
composer run env:start    # start, for example to open it in a browser
composer run env:stop     # stop; the data stays
composer run env:destroy  # delete containers, database and volumes; the next start reinstalls WordPress
composer run env:stop:all # stop this environment and the integration one
```

## Running

```sh
composer run test:e2e                                   # whole suite, headless
composer run test:e2e -- tests/e2e/specs/field.spec.js    # one file
composer run test:e2e -- -g "previewed"                 # tests whose title matches
composer run test:e2e:headed                            # with a visible browser
composer run test:e2e:ui                                # Playwright UI mode: pick tests, step through them
composer run test:e2e:report                            # open the HTML report of the last run
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
| `field.spec.js` | `field fallbacks and fixed values keep post type exceptions after saving` | Checks the proposed key `title`, builds a chain "excerpt, otherwise title" with a slug exception for pages (the picker of the post type appears only after "Add exception for a post type", takes the focus, and "Cancel" closes it), turns on "Raw content" in the closed section of the advanced options (its title then says "raw content" and shortcodes are disabled), and adds fixed text `it` with `en` for pages. Checks both rules in the preview and its compact panels (one per post type, open, titled with type and item; "Expand" with `aria-expanded`; "Copy JSON" gives a status message, copied or selected; a panel closes and opens), saves once and reloads, then checks the chain, both exceptions, fixed texts and text output type. |
| `template.spec.js` | `an optional part is written only when its field has a value` | Writes `{core.title}[ – {core.excerpt}] ({core.excerpt\|"n.d."})`; on the latest post (no excerpt) the optional part disappears and the fallback writes `n.d.`; choosing the other post in the preview shows both; the template is kept after save and reload. Choosing a preview item after reload does not warn on leaving, while an actual mapping edit still does. |
| `template.spec.js` | `a sensitive field typed in a template asks for its confirmation at once` | Types `{core.author.url}` in a template: the confirmation box of the author archive URL appears at once, without leaving the template; once checked the preview shows the URL; when the field is removed from the template the box goes away. |
| `meta.spec.js` | `hidden meta keys are listed apart and a key typed by hand is kept after a reload` | Checks that a meta key in use and a hidden one (leading underscore) are in separate groups; "Other post meta key…" at the end of the list of fields opens a form under it, leaves the list unchanged and focused; an internal key is refused with a message, a valid key is chosen in that list at once; saves the feed and finds the key chosen after the reload. |
| `template.spec.js` | `the text length limit is serialized only for text and kept on save` | Checks that the length control, in the closed section of the advanced options, and the submitted mapping include a limit only for Text; the title of the section says "maximum length 20"; switching to HTML hides and omits it, switching back restores it, and saving keeps it with the section closed and its title unchanged. |
| `lookup.spec.js` | `lookup lines and the default survive editing and saving` | Adds three lines, moves one up and removes another; checks a representative preview value and keeps both remaining pairs, their order and the default after saving. |
| `condition.spec.js` | `condition controls switch between checks and keep their outputs on save` | Switches from a check without a comparison value to a single value and then a multiline list; configures Field and Fixed text outputs, checks a representative preview value and keeps the comparison, values and outputs after saving. |
| `draft.spec.js` | `a refused draft keeps incomplete rows editable and survives rows of an unexpected shape` | Keeps a refused mapping for the administrator (unfinished Fixed value and Template rows sent with empty maps, and a lookup row of an impossible shape) and opens the editor: the "not saved" notice appears, the unfinished rows can still be edited, the odd row is shown as "cannot be edited here". |
| `rows.spec.js` | `rows keep the order chosen with the move and remove buttons` | Adds three rows, moves the third up and removes the first; after save and reload there are two rows, in the chosen order, closed: the header sums them up (`aria-expanded="false"`, controls hidden). Opens and closes one row, then all with "Expand all" and "Collapse all"; leaving the page then does not warn about unsaved changes. |
| `italian.spec.js` | `the Mapping box speaks Italian when the administrator does` | With the Italian language for the administrator, finds the help printed by PHP and the button, the row and its labels built by the script in Italian (JSON translations); the language is reset afterwards. |
| `accessibility.spec.js` | `the feed editor with a row of every rule` | Opens a feed with a saved row of every rule and its preview, then runs axe (WCAG 2.x A and AA) on the two boxes of the plugin. |
| `accessibility.spec.js` | `the list of the feeds and the settings page` | Runs axe on the columns of the plugin in the list of the feeds and on the settings page. |

## Writing a test

- **Data:** every test creates what it needs with `createFixtures( name, options )` and deletes it in
  `afterEach` with `cleanupFixtures()` (`support/wp.js`). The data is made by WP-CLI inside the container
  (`support/fixtures.php`): a feed with its post types and, if given, a saved mapping (`rows`), plus
  published posts and pages by the administrator. Optional `draft: { mapping, errors }` keeps a refused
  mapping for the administrator in the same call. Optional `locale` changes the administrator's language
  and returns `previous_locale`; pass it to `cleanupFixtures( { locale: data.previous_locale } )` to
  restore it during cleanup. These options avoid separate WP-CLI startups. Every object has a slug that
  starts with `kkce-e2e-`, and cleanup deletes only those objects. `wp( [ ... ] )` runs other WP-CLI commands.
- **Editor:** `support/editor.js` opens a feed (`openFeed`), adds a row with a rule and a key (`addRow`),
  gives the preview (`preview`) and saves (`saveFeed`). Saving submits the form and waits for the server's
  confirmation after navigation; an extra reload is usually redundant. The Field scenario retains an
  explicit reload check.
- **Selectors:** by role and label (`getByRole`, `getByLabel`), scoped to the boxes of the plugin
  (`#kkce_feed_settings`, `#kkce_feed_mapping`) or to a row (`getByRole( 'group', { name: 'Row 1: key' } )`);
  `{ exact: true }` when a label is part of another one. No `data-testid` in the plugin.
- **Waiting:** on visible states (`toHaveValue`, `toContainText`, `toBeVisible`), never on fixed times.
- **Accessibility:** `expectAccessible( page, selectors )` (`support/axe.js`) checks the WCAG 2.x A and
  AA rules on the given elements of the plugin only. A violation is a defect of the plugin: fix it or
  record it as an issue, never exclude it.
- **Stability:** one worker and no retries: an unstable test is fixed, not retried.
- **Scope:** keep browser controls, submitted data and representative preview checks here. Cover operator
  semantics, conversions and truncation edge cases in PHP tests instead of repeating them in the browser.

## When a test fails

The HTML report (`playwright-report/`) and, for the failed tests only, a trace and a screenshot
(`test-results/<test>/`) are kept; both folders are ignored by Git and left out of the ZIP. Open a trace
with `npx playwright show-trace test-results/<test>/trace.zip`, or run the test again with
`composer run test:e2e:headed` or `composer run test:e2e:ui`. The PHP log of WordPress is in the container:
`npx wp-env run --config=.wp-env.e2etest.json cli -- tail wp-content/debug.log`.

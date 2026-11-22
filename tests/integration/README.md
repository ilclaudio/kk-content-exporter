# Integration tests (WordPress test suite)

Tests that need a real WordPress: hooks firing, post types and meta, capabilities, queries, REST
routes, translations. They run on the WordPress PHPUnit test framework and on a **dedicated, empty
database `kkce_wordpress_test`**, which the framework empties at every run.

They run only in Docker, in the wp-env test environment: WordPress 7.1.4 with PHP 8.3, its own MySQL
server and database. The tools to install are in the root [`README.md`](../../README.md) (*Development
setup*).

**Safety:** `tests/bootstrap-integration.php` loads the configuration first and refuses to run when
`DB_NAME` is not `kkce_wordpress_test`, because the framework drops every table of the database it is
given. Never point it to the database of a site.

## Running

```sh
composer run test:integration                               # whole suite
composer run test:integration -- --filter KKCE_Feed_Editor  # arguments after -- reach PHPUnit
composer run test:all                                       # unit suite, then this one
composer run env:tests:stop                                 # stop the environment when done (env:stop:all stops both)
```

`tests/bin/integration.mjs` starts the environment of `.wp-env.inttest.json` (http://localhost:8891; the
first start downloads WordPress and the Docker images, then it takes about a minute; nothing happens when
it is already running), creates the database if it is missing and runs
`vendor/bin/phpunit -c phpunit-integration.xml` inside the `cli` container, in the folder of the plugin,
with the configuration of this folder. The WordPress test framework comes with wp-env (`WP_TESTS_DIR`).
The script first checks that Docker answers: without Docker Desktop running it stops at once and says
so (the end-to-end command does the same).

The environment is independent from the one of the end-to-end tests (`.wp-env.e2etest.json`, port 8890): own
containers, own database. Local changes to it go in `.wp-env.inttest.override.json` (ignored by Git).

## Configuration and environment variables

`wp-tests-config.php` (versioned, no credentials) has the defaults of the wp-env test environment; each
value can be changed with an environment variable, for example to run the suite in another container:

| Variable | Meaning | Default |
|---|---|---|
| `KKCE_TEST_DB_HOST` | Database host and port | `mysql` |
| `KKCE_TEST_DB_USER` | Database user | `root` |
| `KKCE_TEST_DB_PASSWORD` | Database password | `password` (public default of wp-env, no real data) |
| `KKCE_TEST_ABSPATH` | WordPress folder | `/var/www/html/` |
| `WP_TESTS_DIR` | Folder of the WordPress test framework | set by wp-env |
| `WP_TESTS_CONFIG_FILE_PATH` | Test configuration (must use `kkce_wordpress_test`) | this folder's `wp-tests-config.php`, passed by the script |

The configuration also points `WP_PLUGIN_DIR` to the parent folder of this plugin, so that
`plugin_basename()` returns `kk-content-exporter/kk-content-exporter.php` as on a real site.

## How a run behaves

Exit code `0` when everything passes, `1` otherwise. If a requirement is missing, the suite stops at once
with a message saying what is missing. Each test runs in a transaction that is rolled back, so a single
test does not depend on the others and leaves no data behind. Test uploads go to
`.phpunit.cache/integration-uploads/` inside this repository.

Some tests make one assertion per item that WordPress provides (for example one per REST route): the
number of assertions changes with the version of WordPress.

## What it covers

| File | What it checks |
|---|---|
| `KKCE_Activation_Integration_Test.php` | The export capability is granted to administrators on activation, idempotently, and kept on deactivation |
| `KKCE_Admin_Routes_Integration_Test.php` | The admin REST routes of the mapping editor: permissions, catalog, preview of published public data only, mapping errors |
| `KKCE_Content_Renderer_Integration_Test.php` | Content and excerpt as clean HTML: blocks, shortcodes only on request, raw text, no external request |
| `KKCE_Core_Provider_Integration_Test.php` | Values of the core fields of posts and pages: GMT dates, public author attributes only |
| `KKCE_Environment_Integration_Test.php` | The plugin is loaded, wired and translatable (bundled `it_IT` translation) |
| `KKCE_Exportable_Post_Types_Integration_Test.php` | The post types a feed can export: public ones only, `attachment` excluded, sorted by label |
| `KKCE_Feed_Editor_Integration_Test.php` | Saving a feed and its mapping: nonce, capability, validation, refused mapping kept as a draft, activation rules, warning for an enabled feed that is not published |
| `KKCE_Feed_Modified_Date_Integration_Test.php` | Which changes of an item make it appear again with `modified_after`: a save of the post does, a change of only meta, terms or featured image by code does not (known limit) |
| `KKCE_Feed_Query_Cost_Integration_Test.php` | A page of a feed costs the same database queries with 1 and with 5 items (distinct authors, meta, terms, permalinks with the category) |
| `KKCE_Feed_Routes_Integration_Test.php` | The endpoint of a feed: switch of the settings, 404 for feeds that cannot be served, Application Password and capability for feeds that need authentication (401, 403, same data as a public feed), published public data only, computed as a visitor, pages and their parameters, order by GMT modified date across daylight saving time and time zones, date filters (half-open interval, time zones, switches of the settings), isolation between feeds |
| `KKCE_Feed_List_Integration_Test.php` | Columns of the list of the feeds, escaped, with the mark of the feeds that export sensitive fields and of the enabled feeds that are not published |
| `KKCE_Feed_Meta_Integration_Test.php` | Configuration meta: registered, hidden from REST, sanitized, editable by administrators only |
| `KKCE_Feed_Post_Type_Integration_Test.php` | The post type `kkce_feed`: private, without REST routes, title only, administrators only |
| `KKCE_Feed_Url_Integration_Test.php` | The REST address of a feed, and no address for a slug that cannot serve a feed |
| `KKCE_Item_Exporter_Integration_Test.php` | An item is exported with the values a visitor would see; the current user is restored |
| `KKCE_Mapping_Box_Integration_Test.php` | The Mapping box: initial data, refused mapping shown again, script and style on the feed screens only |
| `KKCE_Post_Type_Filter_Integration_Test.php` | The filter of the post types appears only when the list is long |
| `KKCE_Settings_Page_Integration_Test.php` | The settings page: administrators only, sanitized values, the group of the date filters, diagnostics |
| `KKCE_Uninstaller_Integration_Test.php` | Nothing is removed unless the administrator chose it; then feeds, settings and capability go |

### Authentication response headers

The feed-route tests also include `test_served_feed_authentication_headers` (four cases). It uses
`WP_REST_Server::serve_request()` and captures the emitted status and headers, rather than relying
only on `rest_do_request()`. It requires a Basic `WWW-Authenticate` challenge on a 401 response and
checks that public success, authenticated success and a capability-related 403 do not add one.
Two further served-response tests cover anonymous admin requests and invalid Application Passwords
on public feeds. Focused filter tests check that core and foreign routes (including a foreign callback
inside the admin namespace), existing challenges and disabled Application Passwords remain unchanged.
Availability is enabled only within test fixtures and restored afterwards; production code respects
WordPress's existing availability decision.
This covers WordPress's response-serving pipeline, not headers added later by a web server or proxy.

The feed-route regressions `test_query_visibility_is_anonymous_for_items_and_totals` and
`test_query_exception_restores_the_authenticated_user` exercise user-dependent query restrictions,
empty-page counts, unrelated queries and user/filter restoration after failures in either query path.

## Adding a test

1. Create `tests/integration/KKCE_<Area>_Integration_Test.php` with a class of the same name extending
   `WP_UnitTestCase`; use `self::factory()` to create posts, users and terms.
2. Optional plugins (ACF, CMB2, Polylang) are not loaded yet: when the plan needs them, add them to the
   wp-env configuration and document it here.
3. Update the table above, and run the suite before proposing the change.

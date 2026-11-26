# ARCHITECTURE.md


## Architecture Notes

KK Content Exporter is a classic WordPress plugin (no blocks, no build step). PHP classes live in
the `KKCE` namespace under `includes/` and are loaded by the plugin's own autoloader; Composer is a
development tool only and is never shipped. The code contains the bootstrap, the private feed post type with its configuration meta, the
feed list, the feed editor (with the protection of the slug outside it), the settings page, the guide page, the uninstall cleanup, the mapping engine (format, validation,
core sources, evaluation, normalization, rendering, check against the catalog), the admin REST routes of the
mapping editor and the mapping editor with its save, the post meta, taxonomy, featured image and language sources (Polylang optional), and the feed endpoint with its pages, authentication, date filters and `lang` parameter: the features of 1.1.0 (meta dates, ACF and CMB2, duplication and export/import, the IDs-only mode, `ids`, the log of the export errors) are not implemented yet. Update this file in the same change that adds them.


## Key Directories and Files

| Path | Content |
|---|---|
| `kk-content-exporter.php` | Main file: plugin header, `ABSPATH` guard, constants, autoloader, lifecycle hooks |
| `uninstall.php` | Uninstall routine (guarded by `WP_UNINSTALL_PLUGIN`): loads the autoloader and calls `KKCE\Uninstaller::run()` |
| `includes/` | First-party classes of the `KKCE` namespace (`class-*.php`) |
| `includes/class-autoloader.php` | `KKCE\Autoloader`: maps `KKCE\<Sub>\<Class_Name>` to `includes/<sub>/class-<class-name>.php` |
| `includes/class-plugin.php` | `KKCE\Plugin`: registers the hooks, loads the text domain; `Plugin::REST_NAMESPACE` (`kkce/v1`) and `Plugin::feed_url()`, the only place that builds the address of a feed (empty for a slug that cannot serve a feed) |
| `includes/class-feed-post-type.php` | `KKCE\Feed_Post_Type`: registers the private post type `kkce_feed` (administrators only, no REST), its capabilities (all mapped to `manage_options`) and disables a feed restored from the trash; `protect_slug()` (on `wp_insert_post_data`, priority 9, before the editor): outside the authorized save of the editor the slug of a feed stays the saved one (a crafted Quick Edit request, code, another plugin), moving to the trash and restoring keep working, and a feed restored while another feed uses its slug gets no slug, the taken slug being kept in `Feed_Meta::META_TAKEN_SLUG` for the editor (rule: `Feed_Form::kept_slug()`); `slug_is_taken()` (another feed, any status but the trash, uses a slug: the one query, shared with the editor); `is_enabled_but_unpublished()` (enabled, post status not `publish`: the endpoint answers 404), used by the editor and the list |
| `includes/class-feed-meta.php` | `KKCE\Feed_Meta`: keys, registration (`register()`, on `init`) and sanitizers of the configuration meta of a feed (`_kkce_post_type`, multiple, one row per source post type; `_kkce_mapping`, JSON text; `_kkce_access`, `_kkce_active`, `_kkce_per_page`; `_kkce_taken_slug`, not registered: the slug a restored feed could not get back), the readers `post_types()` and `mapping()` and `save_mapping()` (goes through `wp_slash()`) |
| `includes/output/class-mapping.php` | `KKCE\Output\Mapping`: pure. Format of the mapping (version 1): vocabulary (kinds, output types, source groups; rows have no missing-value policy), limits, `decode()` of the JSON text, `suggest_key()`, and `row_sources()`, the one traversal of the sources of a well-formed row (field that holds each one, post type of the entry or `*` for the outputs, source), from which `Mapping_Validator` (confirmations of sensitive sources) and `Catalog_Validator` (check against the catalog, options) derive their views |
| `includes/output/class-template.php` | `KKCE\Output\Template`: pure. Reads the text of a `template` row into parts of one shape (`text`, `alternatives`, `section`): placeholders `{group.name}` with catalog identifiers, alternatives `{a|b|"literal"}`, literal braces `{{` `}}`, optional sections `[...]` (at least one placeholder, not nested, numbered from 1; `section` is 0 outside) with literal brackets `[[` `]]`; limits `MAX_LENGTH` (1,000 characters, counted as characters) and `MAX_PLACEHOLDERS` (20); errors `format`, `too_long`, `too_many`; `sources()` lists the sources of the placeholders |
| `includes/output/class-mapping-validator.php` | `KKCE\Output\Mapping_Validator`: pure. `validate()` lists every error of a decoded mapping (`row`, `field`, `code`), `normalize()` gives the canonical form. A `lookup` row has `inputs` (post type or `*` => one source), `cases` (1 to `MAX_CASES`, 100, of `{ match, output }`: texts not empty, without spaces at the ends, distinct; output `{ fixed }` or `{ source }`), an optional `default` (also `{ input: true }`; none = null) and `confirmed` (every sensitive input or output source). A `condition` row has `inputs` (as a table), `operator` (`Mapping::OPERATORS`), `matches` (none for `empty`/`not_empty`, one text for the others, 1 to `MAX_CASES` distinct texts for `one_of`, with the rules of the texts of a table), optional `then` and `else` (outputs of a table default; none = null) and `confirmed`. Every rule but `fixed` accepts `max_length` (whole number from 1 to `MAX_LENGTH_LIMIT`, 10,000), only for a plain text output: a template that is not HTML, otherwise the `string` type (`not_allowed` otherwise). Refuses a sensitive source that the row does not list in `confirmed`. Does not check that a source exists (the catalog does, at save time) |
| `includes/output/class-catalog-validator.php` | `KKCE\Output\Catalog_Validator`: pure. `validate()` is the full check of a mapping for a feed, used by the preview and the save: the format with `Mapping_Validator`, then, on a valid mapping, the sources against the `Catalog` of the post types of the feed. It asks every source to `Catalog::types_for()` and `Catalog::options_for()`, so a post meta key typed by hand is valid (see below). The entry of a post type uses the sources of that post type and the common ones; `*` and the outputs (cases, default, then, else) any source of the feed; an entry for a post type the feed no longer reads is kept and not checked. Codes: `unknown` (source not in the catalog), `list` (a list as the input of a lookup table or of a condition with `equals`, `not_equals`, `one_of`), `not_allowed` on `options` (an option turned on that no source of the row accepts); once per field. Both go through the private `check()`, which gives the errors and the canonical form in one pass (the mapping is normalized once). `validate_text()` is the entry point of the preview and of the save: `json` for an unreadable text, `too_large` for a text or a saved form (encoded by the given `wp_json_encode`, accents as `\uXXXX`) over `Mapping::MAX_BYTES`, then `validate()`; it gives the canonical mapping. An option is on when its value is not empty; the options of a row with an entry for a post type the feed no longer reads are not checked |
| `includes/output/class-sensitive-keys.php` | `KKCE\Output\Sensitive_Keys`: pure. Whether a name (or the name of a source `group.name`) looks sensitive: split into words, compared with a list of words and of adjacent pairs. Judges the name, never the value; `confirmed_sources()` lists the sensitive sources that a mapping confirms |
| `includes/output/class-rule-evaluator.php` | `KKCE\Output\Rule_Evaluator`: pure. The only place that computes the value of a mapping row (`field`: chain of the post type of the item, which replaces the general `*`, first present value wins, `0`/`false` are values, empty values pass on; `fixed`: value of the post type or `*`; `template`: template of the post type or `*`, every placeholder takes its first alternative with a value in standard text form by the declared type of its source, an optional section is left out when one of its placeholders has no value, no value when no placeholder has one, plain text unless the row asks for HTML; `lookup`: the input of the post type or `*`, in standard text form without spaces at the ends, compared exactly with the text of every case, the first match gives its output (fixed value, or a source: none when it does not exist, its empty form when empty), otherwise the default (none = no value, fixed, source, or the input as it is, empty values as `""` or `[]`); a missing, empty or unconfirmed input matches nothing, a list input gives no value; `auto` = common type of the outputs; `condition`: the input of the post type or `*` checked with one operator (`empty`, `not_empty` on the empty values of a chain, lists included; `equals`, `not_equals`, `one_of` on the standard text form, exact, a list giving no value; `contains`: an element of a list equal to the text, or the text inside a single value, capital letters included), a missing or unconfirmed input counting as an empty text, then the `then` or `else` output (missing = no value; fixed, source, or the input as it is, empty values as `""` or `[]`); other kinds give no value) and the object of an item (`evaluate_mapping()`: values normalized to the type of the row, a plain text cut to the `max_length` of the row, `auto` = common declared type of all the sources of the row, otherwise text; the key of every row is always written, in the order of the rows, with `null` when the row has no value: a source that does not exist, a value that cannot be converted, an unconfirmed sensitive source, an unknown kind; an empty text or list keeps `""` or `[]`). Never reads an unconfirmed sensitive source. Sources are read through a function given by the caller, with the options of the row (null = does not exist) |
| `includes/output/class-item-exporter.php` | `KKCE\Output\Item_Exporter`: exports one item with a mapping, reading every source once from the provider of its group (`core`: `Core_Provider`, `language`: `Language_Provider`, `taxonomy`: `Taxonomy_Provider`, `meta`: `Meta_Provider`); the declared types of the sources for `auto` are `Core_Provider::types()` and `Language_Provider::types()` (no labels), `Taxonomy_Provider::declared_type()` and `Meta_Provider::declared_types()` for every post type of the feed, given by the endpoint and the preview (`export( $post, $mapping, $post_types )`): a post meta declared with different types in the post types of the feed counts as text, so a key with `auto` has one JSON type in every item (registrations read once per item; text for the keys not registered); computes as an anonymous visitor (current user set to 0 for the whole item, always restored in `finally`) |
| `includes/output/class-normalizer.php` | `KKCE\Output\Normalizer`: pure. `truncate()` cuts a text to at most N characters, `…` included, at the last space of the second half without the final spaces and punctuation, otherwise exactly. Converts a value to the output type of its row (`string` plain text without tags and with entities decoded, `html`, `number`, `boolean`, `datetime` in UTC ISO 8601 or `YYYY-MM-DD`, `url` absolute http/https, root-relative made absolute with the origin of the site, `list`); a value that cannot be converted gives no value and `not_convertible`; `common_type()` resolves `auto` |
| `includes/output/class-content-renderer.php` | `KKCE\Output\Content_Renderer`: controlled rendering of content and excerpt, never the full `the_content` filter: `do_blocks()` (no block comments), `wpautop()` only for content without blocks (never for the excerpt), shortcodes only with the row option `shortcodes` (otherwise the registered ones are removed), no embed (oEmbed would be an external request), absolute URLs; `raw` gives the stored text |
| `includes/output/class-url-absolutizer.php` | `KKCE\Output\Url_Absolutizer`: relative `href`, `src` and `srcset` made absolute with `WP_HTML_Tag_Processor` and `WP_Http::make_absolute_url()` against the permalink of the content; absolute, protocol-relative, anchors and other schemes unchanged |
| `includes/sources/class-source.php` | `KKCE\Sources\Source`: pure value object describing a source (identifier `group.name`, label, post type or common, declared type, accepted options, whether it needs a confirmation) |
| `includes/sources/class-catalog.php` | `KKCE\Sources\Catalog`: pure. Sources of a feed for its post types (common ones once, then those of each post type), `post_types()`, `find()` of a source for a post type or `*`, `types_for()` (declared types of a source for a scope: the one place that says whether a mapping can use it; a selectable post meta key not in the list counts for every post type of the feed, as text, because the list is only a sample), `options_for()`, `suggested_rows()` (the `type` row in feeds with two or more post types; the `lang` and `translation_group` rows when the catalog has the language of the items, that is Polylang translates a post type of the feed) |
| `includes/sources/class-catalog-factory.php` | `KKCE\Sources\Catalog_Factory`: `for_post_types()` builds the `Catalog` of a feed from the providers (core and language, then for every post type its taxonomies and its post meta); `discover` (only the admin catalog, never the check of a mapping) also reads the keys in use, and `meta_keys` lists keys typed by the administrator; the only place that knows which providers exist |
| `includes/sources/class-exportable-post-types.php` | `KKCE\Sources\Exportable_Post_Types`: the one rule on the post types a feed can read. `selectable()` (public post types, `attachment` excluded, key => label sorted by label; the only call to `get_post_types()`) and `split()` (pure: requested post types against a list already read, into the exportable ones and the others, without duplicates, in the order of the request). Used by the feed editor and the admin routes |
| `includes/output/class-feed-response.php` | `KKCE\Output\Feed_Response`: pure. `build()` gives the fixed container of a feed response (`items` as JSON objects, `pagination` with `page`, `per_page`, `total`, `total_pages` (0 without items), `meta` with the slug of the feed and `generated_at` in UTC ISO 8601); `per_page()` gives the requested items per page, otherwise the value of the feed within the global maximum |
| `includes/rest/class-feed-routes.php` | `KKCE\Rest\Feed_Routes`: the public endpoint of the feeds, described in *Public Endpoints and Hooks* |
| `includes/rest/class-feed-access.php` | `KKCE\Rest\Feed_Access`: pure. `decide()` says who reads a feed: anybody when it is public and the settings do not require authentication; otherwise a request with an Application Password (`unauthenticated` without it) of a user with the capability of the feeds (`forbidden` without it) |
| `includes/rest/class-date-filters.php` | `KKCE\Rest\Date_Filters`: pure. The date filters of a feed (`FILTERS`: setting, column, bound), `parse()` of a date with a time zone into UTC (`Y-m-d H:i:s`, years 1000 to 9999), `check_value()` (switch, then form), `empty_interval()`, `date_query()` (clauses of a `date_query`, `after` inclusive, `before` exclusive) |
| `includes/rest/class-admin-routes.php` | `KKCE\Rest\Admin_Routes`: admin-only REST routes of the mapping editor in the namespace `kkce-admin/v1` (`GET /catalog`, `POST /preview`), described in *Public Endpoints and Hooks* |
| `includes/sources/class-core-provider.php` | `KKCE\Sources\Core_Provider`: the core sources common to every post type and their values; `TYPES` is the one list of the core sources (identifier => declared type), given by `types()` without labels and turned into `sources()` with labels and options (stored title, content and excerpt through `Content_Renderer` with the options of the row, GMT dates, author ID, display name and archive URL only, never e-mail or login; `core.featured_image`: the absolute URL of the original image through `wp_get_original_image_url()`, so not the "-scaled" copy of a large image, null without a featured image or when its attachment is missing or not an image) |
| `includes/sources/class-language-provider.php` | `KKCE\Sources\Language_Provider`: the language sources, common to every post type, all texts. `language.site`: the language of the General settings (`WPLANG`, not `get_locale()`, which multilingual plugins change) in W3C form, `en-US` without one, always offered. With Polylang active and at least one post type of the feed translated: `language.code`, `language.locale`, `language.w3c` (the language of the item through `pll_get_post_language()`; three sources rather than an option so that they work in every rule) and `language.translation_group` (name of the `post_translations` term, shared by the translations of one content). Null for an item without a language, without a translation group, or without Polylang (a saved mapping keeps working when Polylang is turned off). The only class that calls Polylang, behind `function_exists()`; values come from the term cache that the query of a page fills; WPML is not supported |
| `includes/sources/class-taxonomy-provider.php` | `KKCE\Sources\Taxonomy_Provider`: the taxonomy sources of a post type, one pair for every public taxonomy of it (`public => true`; the internal taxonomies of Polylang are excluded by name): `taxonomy.<name>` (`list`: the names of the assigned terms, a flat list without parents in a hierarchical taxonomy, `[]` without terms) and `taxonomy.<name>.first` (`string`: the first of them, null without terms; usable as the input of a lookup table or of a condition, which a list is not). Terms in the alphabetical order of their names, as WordPress gives them; names decoded from the HTML entities WordPress stores. A private taxonomy, one of another post type or an unknown identifier gives null. Values come from the term cache that the query of a page fills; taxonomy names cannot contain a dot, so `.first` is unambiguous |
| `includes/sources/class-meta-keys.php` | `KKCE\Sources\Meta_Keys`: pure. Which post meta keys can be a source: `is_valid()` (1 to 191 characters among letters, digits and `_ . : -`), `is_internal()` (`_edit_*`, `_wp_*`, `_oembed_*`, `_kkce_*` and `_thumbnail_id`), `is_hidden()` (leading underscore), `is_selectable()`, `key_from_source()`, and `declared_type()` (registered type to declared type; `array` and `object` are not exported) |
| `includes/sources/class-meta-provider.php` | `KKCE\Sources\Meta_Provider`: the post meta sources of a post type (registered keys with their type, from the registrations of the post type and those for every post type, `register_meta( 'post', ... )` without a subtype, the former first, as WordPress reads them (`registrations()`); a key registered as a structure stays out also when it is found in the items; with `discover`, a sample of at most `SAMPLE_LIMIT`, 200, distinct keys of the published items, read by one prepared query that leaves out the internal keys and the hidden references of ACF, a key starting with `_` whose value is `field_...`; cached for a minute), and their values: the stored value when it is a scalar, otherwise null; a missing key gives null, an empty text stays `""`, the first value of a repeated key is read; `declared_types()` gives the declared type of the meta sources of a post type, reading the registrations once and caching nothing beyond the returned function. The only query of the plugin that reads the database directly: no WordPress function lists the distinct meta keys of a post type |
| `includes/admin/class-feed-list.php` | `KKCE\Admin\Feed_List`: columns of the `kkce_feed` list (relative endpoint address computed by `rest_url()`, source, access, status), read through the sanitizers and escaped; the status of an enabled feed that is not published is "Active, not published"; the status of a feed whose mapping confirms sensitive sources adds "Exports sensitive fields" |
| `includes/class-settings.php` | `KKCE\Settings`: option `kkce_settings` (defaults, safe reading, processing of the form: pure; `get()` and `sanitize_option()` are the only WordPress calls) |
| `includes/class-uninstaller.php` | `KKCE\Uninstaller`: deletes feeds (every status, with meta), the option and the capability when the setting asks for it |
| `includes/admin/class-settings-page.php` | `KKCE\Admin\Settings_Page`: submenu page built with the Settings API (the three date filters are one row, a `fieldset` of boxes with a legend), and the diagnostics, which read the enabled feeds once (`enabled_feed_findings()`: whether one needs authentication, and the titles of those that confirm sensitive sources); the diagnostics also list, by title, the enabled feeds whose mapping confirms sensitive sources |
| `includes/admin/class-guide-page.php` | `KKCE\Admin\Guide_Page`: the "Guide" submenu page, after "Settings" (`manage_options`, the same as the editor): how the rows of a mapping work, one tab per topic, each with examples (the settings of a row and the JSON it gives). The tabs are `nav-tab` links to their own address, printed by the server (`url()`: `edit.php?post_type=kkce_feed&page=kkce-guide&tab=<tab>`), the current one with `aria-current="page"`; the page works without JavaScript, reads no data and changes nothing. The requested tab is read from `$_GET['tab']` with `sanitize_key()` and one `phpcs:ignore` of `NonceVerification.Recommended` (a read-only choice compared with a fixed list) |
| `includes/admin/class-guide-tabs.php` | `KKCE\Admin\Guide_Tabs`: pure. The tabs of the guide in their order (`TABS`: overview, the five rules, output types, preview) and `choose()`, the tab shown for a requested value (the first one for a missing, unknown or malformed value) |
| `includes/admin/class-feed-editor.php` | `KKCE\Admin\Feed_Editor`: the "Feed settings" meta box of the `kkce_feed` editor (opened by a text that says what a feed is and what the box is for) and its saving (nonce per feed, `edit_post` capability, slug set through `wp_insert_post_data`, meta written on `save_post_kkce_feed`, errors shown once in a notice; a warning at every visit while the feed is enabled but not published), the live address of the feed under the slug field, the filter of the post types (printed hidden above `FILTER_THRESHOLD`, 10, post types and shown by the script), `is_feed_screen()` (the edit and create screens of a feed, shared with `Mapping_Box`), and the loading of `assets/js/feed-editor.js` and `assets/css/feed-editor.css` (same handle `kkce-feed-editor`). The status is a real checkbox exposed as a switch (`role="switch"`, class `kkce-switch`), so it is saved as before and works without JavaScript. Saves the mapping of the hidden field `kkce_feed[mapping]` in the same request (same nonce and capability): checked with `Catalog_Validator` against the post types the feed will have; a valid mapping is written to `_kkce_mapping` (canonical form, through the meta sanitizer), a refused one leaves the saved mapping unchanged and is kept for 10 minutes as a draft (`draft_key()`: transient per user and feed, with its errors; an empty text when it was refused as too large) plus the notice `mapping.not_saved`; without the field (no JavaScript) the mapping is not touched. A feed is saved disabled without mapping rows (`active.no_rows`). The JSON text is read with `wp_unslash()` only: one `phpcs:ignore` of `InputNotSanitized`, because a JSON document cannot go through a text sanitizer; it is validated and re-encoded instead |
| `includes/admin/class-mapping-box.php` | `KKCE\Admin\Mapping_Box`: the "Mapping" meta box (below the settings box), opened by a short introduction (each row defines one key; use the preview before saving) that ends with the link "How the rows work" to the Overview tab of the guide (`Guide_Page::url()`), opened in a new tab because the editor often has unsaved changes (text "(opens in a new tab)" for screen readers, Dashicon `external`): a container with the initial data of the mapping editor in an escaped `data-kkce-mapping` attribute (saved canonical mapping, `Admin_Routes::describe_catalog()` of the saved post types, catalog, preview and items routes, and the rules of the validator: maximum rows, key length, `Mapping_Validator::KEY_FORMAT`, `MAX_CHAIN`, `VALUE_MAX_LENGTH`, `Template::MAX_LENGTH`, `MAX_CASES`, so the script copies none of them; and `rules`, for every rule the editor offers a short sentence and the address of its tab of the guide, `Guide_Page::url()`, so the script writes no address), a `noscript` message, and the loading of `assets/js/feed-mapping.js` and `assets/css/feed-mapping.css` with their translations. It prints no mapping field. A mapping refused by the last save of the user (`Feed_Editor::draft_key()`) is read once and given as `draft` (`errors`, `readable` and, when its rows can be shown, `mapping`: the JSON text as sent, which the script reads with `JSON.parse` so that empty maps stay objects; the saved mapping stays in `mapping` of the data) |
| `assets/js/feed-mapping.js` | Vanilla JavaScript of the mapping editor (handle `kkce-feed-mapping`, footer, depends on `wp-api-fetch` and `wp-i18n`, translated through `wp_set_script_translations()`). When the catalog has the language of the items (Polylang translates a post type of the feed) and the mapping has rows, none of which reads `language.code`, `language.locale` or `language.w3c` in any rule, a short warning above the rows (`checkLanguage()`, at every change and redraw) says that the items cannot be told apart by language, with "Read the guide" to the section on multilingual sites of the guide (`languageGuide` in the data of the box, in a new tab); it never blocks the save. A language source of a saved mapping that the catalog no longer offers is shown as needing Polylang. A row is prepared when it enters the editor (`withGeneralScope()`: an editable row always has the general scope `*`), so drawing only reads the data; every rule draws its scopes and the exception picker with `appendScopes()`, and the scopes of inputs, templates and fixed values are filtered for the hidden field by `keptScopes()`. Every row can be closed: its legend ("Row 1: title") names the group for assistive technologies only (`screen-reader-text`); the visible title is the header, a button that opens and closes the row (`aria-expanded`, `aria-controls`, text written by `fillSummary()` from `summaryParts()`: key, what it writes, output type, number of exceptions, a mark of the advanced options, for example "title ← Title · Text", with the key in bold and the details in grey, the text being the same; kept up to date while typing), followed by move up and move down as Dashicons in compact 30 px squares with their accessible names and "Remove" as a red underlined link apart (every removal is underlined, as in WordPress); the text buttons of the box have the compact 30 px size and the colors of the admin color scheme. The body of a row is laid out as a form: every labelled control (`labelled()`) has its label in a column on the left and the control on the right, text without a label of its own (help, links, errors) starts where the controls start (`kkce-mapping-indent`), and the parts come in the same order for every rule under small titles (`zone()`: "What" for key and rule, "From where" for the configuration of the rule, "How" for output type and advanced options); a control whose meaning is said by what is beside it (the value of an output after its choice, the cells of the lookup table) keeps its label for assistive technologies only (`labelledHidden()`). Under the rule, its sentence and the link "Read the guide" to its tab of the guide, in a new tab (`renderRuleHelp()`, from `data.rules`; the select of the rule is described by it); they change with the rule. The actions that add something ("Add fallback", "Add exception for a post type", "Add line": `addLink()`) and "Expand all", "Collapse all" are light links (`button-link`), at least 24 px high; the body of a closed row is `hidden`, so its controls cannot take the focus. Open or closed is interface state (`row.collapsed`), never a change of the feed: saved rows start closed, while new rows, proposed rows and every row of a refused draft start open, and a row whose key has an error opens by itself; "Expand all" and "Collapse all" appear above two rows or more, after the number of rows; after a row is removed the header of the next one takes the focus. One `fieldset` per row, in the order key → rule → configuration of the rule → output type (output key, rule `field`, `fixed`, `template`, `lookup` or `condition` in a native `select`, then the configuration, then output type, then the advanced options and the confirmations of the sensitive fields; move up/down and remove are in the header). The name of a scope ("For all post types", "For Pages") is shown only when the row has exceptions; otherwise the visible label is short ("Value") and the accessible name keeps the scope through `aria-label` (`labelledScope()`), and the legend of a chain is visually hidden. A `condition` row has a field to check per scope (lists offered), a check (operator; one text, or a text area with one value per line for "is one of") and the outputs "If true, write" and "Otherwise, write" with the choices of the default of a table; the texts are kept while the check changes and written only for the checks that use them. A `lookup` row has a field to read per scope (sources of type `list` are not offered), a real `<table>` of lines (headers "If the value is" and "Write"; text to match, "Fixed text" or "Field" output, move up and down as icons, remove, "Add line" up to `maxCases`) and a default ("Nothing (null)", fixed text, field, the value read); texts to match are trimmed and a new empty line is left out of the hidden field. A `field` row has the general chain (`*`) and optional exceptions for single post types, added on request (the button "Add exception for a post type" shows the picker "Different for" with "Add exception" and "Cancel"; opening and cancelling are interface state, `row.pickerOpen`, not changes of the feed; existing exceptions are always shown), each a chain of native `select` with `optgroup` (add fallback, move, remove); a `fixed` row has a text value per scope, converted by the output type of the row. The advanced options are a native `<details>` section (`renderAdvanced()`), closed when the editor opens, whose title sums up what is set ("Advanced options (maximum length 200, raw content)"); opening it is interface state (`row.advancedOpen`, kept across redraws), not a change of the feed. It holds the maximum length of a plain text and the options of the fields (`raw`, `shortcodes`), which appear only when a field of the row accepts them, with their warnings (`raw` disables `shortcodes`); the confirmations of the sensitive fields (`renderConfirmations()`) stay outside it, always visible; a source that needs a confirmation shows a warning (the confirmation itself is asked when saving). Changing the rule keeps the settings of the other rule in memory until the page is left; only the chosen rule is written. The hidden field gets only what was chosen: empty places of a chain, empty chains and empty fixed values are left out, focus kept on the edited control, changes announced in an `aria-live` region, key checks shown as help only. It creates the hidden field `kkce_feed[mapping]` (JSON text): without JavaScript the field is not sent. A new catalog is asked to `GET /catalog` when the checked post types change. Every list of fields of a row ends with "Other post meta key…": choosing it keeps the list as it was (value and focus) and opens under it a form (`renderMetaForm()`: key, "Use this key", "Cancel", a `role="status"` message); the key is sent to `GET /catalog` in `meta_keys`, a refused key keeps the form with the reason, an accepted one is chosen in that list. Opening the form and typing in it stop their events, so they are not changes of the feed; using the key is. The proposed rows of the catalog fill an empty mapping until it is touched; the key of a source is proposed while the key is empty or still the proposed one. Rows it cannot edit (a rule of a later version, or a damaged shape) are kept unchanged. Labels used by several controls are constants, so every translatable string has one translators comment. The DOM is built with `createElement` and `textContent` only. A refused mapping (`data.draft`) is shown with an error notice listing its errors and counts as an unsaved change; every sensitive source of a row shows its warning and the box "I understand, publish this value", which writes it in `confirmed` |
| `assets/js/feed-mapping.js` (preview panel) | `createPreview( deps )`, outside `MappingEditor`, with explicit dependencies given by the editor (layout, current catalog, whether there are rows, JSON text of the mapping, error messages, announcements, routes): shown only when the mapping has at least one row (without rows the rules use the whole width); the JSON of one item per checked post type, from `POST /preview` with the mapping of the hidden field (saved or not), each in a `<details>` panel open at first whose title names the type and the item ("Posts — Concert (ID 12)"); the choice of the item, "Expand" and "Copy JSON" are on one line above the JSON; the JSON has a limited height until "Expand" (`aria-expanded`, `aria-controls`) and is never cut; "Copy JSON" uses the clipboard of the browser on a secure connection, otherwise (or when copying fails) it selects the whole JSON and says to press Ctrl+C, in a `role="status"` message; open panels and expanded JSON are kept across redraws and are not changes of the feed; computed again 600 ms after the last change and with "Refresh"; one request per post type (each with all the post types of the feed in `post_types` and the one to preview in `post_type`), so each type can use its own item, chosen from `GET /items` (default: the latest modified item; a chosen item that is no longer published goes back to the latest). Errors of the mapping (`kkce_invalid_mapping`) are listed as sentences (row, key, setting, reason) and announced; the JSON is shown, not announced. Choosing the item is not an unsaved change of the page (both input and change events from the preview picker are stopped before reaching the box; actual mapping edits still trigger the warning). Each panel shows the `notices` of its item above the JSON: `author_login`, computed by `Admin_Routes` when a text of the object contains the author archive URL with pretty permalinks and the `user_nicename` is the one WordPress derives from the login, and `author_display_login`, when a text of the object contains the display name of the author and that name is the login, as WordPress sets it for a new user (the notice never contains the login). Diagnostics of empty fields and failed conversions are not done (tracked as an issue) |
| `assets/css/feed-mapping.css` | Layout of the rows of the mapping editor and of the preview: one box per row (a scope is a plain heading, not a box), labels in a column of their own (`--kkce-label`) and controls lined up beside them, selects and text fields of one width, the output type separated by a line, the lookup table with its title in the column of the labels; when the column of the rows (a container of its own) is at most 600 px wide, labels above their controls and one control per line; two columns (rules, preview kept in view with `position: sticky`) when the preview is shown (`.kkce-has-preview`) and the box is at least 900 px wide (container query on `.kkce-mapping`), one column otherwise (colors and focus from the WordPress admin) |
| `assets/js/feed-editor.js` | Vanilla JavaScript of the feed editor, loaded only on the edit and create screens of `kkce_feed` (handle `kkce-feed-editor`, footer, version `KKCE_VERSION`): updates the address of the feed while the slug is typed (slug rules and messages printed by the server in the box, nothing duplicated) and filters a long list of post types (it sets `style.display`, because the admin style gives those labels `display: inline-block`, which wins over the `hidden` attribute), and asks for confirmation before leaving with unsaved changes in the settings and mapping boxes (the mapping editor signals its changes with a bubbling `change` event). Progressive enhancement: the box works without it. Shipped in the release ZIP and required by `kkce_release_missing_files()` |
| `assets/css/feed-editor.css` | Style of the "Feed settings" box, loaded with the editor script on the edit and create screens of `kkce_feed`: the status drawn as an on/off switch (on in the admin color, `--wp-admin-theme-color`, off in grey; the knob position also says the state; system colors in forced-colors mode; no transition with reduced motion) |
| `includes/class-feed-form.php` | `KKCE\Feed_Form`: pure processing of the submitted editor values (which value is saved, which error is reported); `require_rows()` disables a feed whose mapping has no row (`no_rows`); `kept_slug()` decides the slug a feed keeps when written outside its editor (the saved one; what WordPress proposes when moving to the trash; on restore the slug before the trash without `__trashed`, never a renamed one, and none when another feed uses it) |
| `includes/class-feed-validator.php` | `KKCE\Feed_Validator`: pure validation (no WordPress call) of the slug, the items per page, the access and the list of source post types; returns error codes, the editor writes the messages |
| `languages/` | `kk-content-exporter.pot`, `kk-content-exporter-it_IT.po` / `.mo`, and the generated JSON translations of the admin scripts (`kk-content-exporter-<locale>-<md5>.json`) |
| `readme.txt` | WordPress.org readme (Stable tag, requirements, changelog) |
| `docs/` | Public guides on the repository, left out of the package (`.distignore`): `reading-a-feed.md`, for the developers of a program that reads the feeds (response, pages, access, date filters, removed items, languages, errors, limits) |
| `README.md`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md` | Repository documentation |
| `VERSION.txt` | Current version (see *Version alignment* in `AGENTS/GIT_WORKFLOW.md`) |
| `bin/` | Development command-line scripts: `php-lint.php`, `build-zip.php`, `svn-prepare.php`, `release-functions.php`; never shipped |
| `.distignore` | Files and folders left out of the release ZIP |
| `build/` | Generated release folder, ZIP and SVN working copy (ignored by Git) |
| `tests/unit/`, `tests/bootstrap-unit.php`, `phpunit.xml` | Unit tests (PHPUnit + Brain Monkey, no WordPress) |
| `tests/integration/`, `tests/bootstrap-integration.php`, `phpunit-integration.xml` | Integration tests (WordPress test suite in the wp-env test environment, dedicated database); two suites in separate processes: `integration` without optional plugins, `polylang` (`tests/integration/polylang/`) with Polylang loaded (`KKCE_TEST_POLYLANG=1`) |
| `tests/e2e/`, `playwright.config.js` | End-to-end tests (Playwright, Chromium, axe-core): `specs/`, `support/`, README with the list of the tests |
| `tests/bin/` | Node scripts behind the Composer test commands: `integration.mjs`, `e2e.mjs`, `docker.mjs` (Docker check), `tools.mjs` (shared: plugin root, executables of npm packages, commands run as arrays without a shell) |
| `package.json`, `package-lock.json` | npm test tools only (`@wordpress/env`, `@playwright/test`, `@axe-core/playwright`, exact versions); never shipped |
| `.wp-env.e2etest.json`, `.wp-env.inttest.json` | wp-env environments: development (E2E, port 8890) and tests (integration, port 8891), WordPress 7.1.4, PHP 8.3; the test environment also mounts Polylang 3.9.2 from WordPress.org, not activated |
| `.github/workflows/` | `ci.yml` (automatic checks and integration suite), `functional.yml` (*End-to-end tests*, started by hand) |
| `tests/phpstan/` | PHPStan bootstrap (runtime constants) and `polylang-stubs.php`: the Polylang functions the plugin calls, copied by hand from Polylang 3.9.2 (no `wpsyntex/polylang-stubs` release for 3.9) |
| `.githooks/` | Git hooks (pre-commit, commit-msg) |
| `phpcs.xml.dist`, `phpstan.neon.dist` | Coding standards and static analysis configuration |
| `AGENTS/`, `AGENTS.md`, `CLAUDE.md` | AI collaboration context |
| `DEV/` | Symlink to private working material; ignored by Git, never shipped |

`includes/admin/` holds the classes of the administration screens (the feed list, the editor with its settings and mapping boxes, the settings page and the guide).
`includes/output/` holds the logic of the output: the mapping format and its validation, the sensitive names, the
rule evaluator (pure), the normalizer, the rendering of content, the export of an item (the link with WordPress) and
the container of a feed response. `includes/sources/` holds the sources a mapping can read: the catalog and the
providers (core with the featured image, language, taxonomies and post meta now; ACF and CMB2 in 1.1.0). `includes/rest/` holds the REST routes: the admin routes of
the mapping editor and the endpoint of the feeds.


## Plugin Lifecycle

1. **Load** (`kk-content-exporter.php`): exits without `ABSPATH`; defines `KKCE_VERSION`,
   `KKCE_PLUGIN_FILE`, `KKCE_PLUGIN_DIR`; registers `KKCE\Autoloader`; registers activation and
   deactivation hooks; hooks `KKCE\Plugin::boot()` on `plugins_loaded`.
2. **Boot** (`plugins_loaded`): `Plugin::boot()` only registers hooks and is idempotent. No
   translatable string is evaluated before `init` (WordPress 6.7+ reports translations loaded too early).
3. **Init** (`init`): `Plugin::load_textdomain()` loads the bundled translations. The post type `kkce_feed` is registered by
   `Feed_Post_Type::register()` and its configuration meta by `Feed_Meta::register()` (both on `init`).
   WordPress returns stored meta as strings (`'1'` or `''` for the flag, `'20'` for the number): readers convert
   them with `sanitize_active()` and `sanitize_per_page()`. The source post types are a multiple meta
   (`single => false`, no default, no serialization, so feeds can be found by post type with a `meta_query`):
   read them with `Feed_Meta::post_types()`, which gives a clean list without empty values or duplicates
   (a feed saved with one row gives a list of one); the editor deletes and rewrites the rows when the list changes.
   The mapping is the meta `_kkce_mapping`, a JSON text with a version (format in `Output\Mapping`). The sanitizer
   checks it with `Output\Mapping_Validator` and stores the canonical form, or an empty string when it is not valid
   (a sanitizer cannot refuse a value; the editor reports the errors before saving); `Feed_Meta::mapping()` checks it again
   on every read, so a damaged stored value gives an empty mapping. WordPress removes the backslashes of a meta value:
   write it with `Feed_Meta::save_mapping()`, which goes through `wp_slash()`. The text is ASCII only (`wp_json_encode()`). `sanitize_access()` fails closed: only the exact value
   `public` opens a feed, any other stored value gives `authenticated` (a feed without the meta gets the registered
   default `public`). REST routes are registered on `rest_api_init` (`Rest\Admin_Routes::register()`).
4. **Activation** (`Plugin::activate()`): grants the capability `kkce_read_export` (`Plugin::READ_EXPORT_CAPABILITY`)
   to the administrator role. It is idempotent, creates no content and does not flush rewrite rules (the
   `kkce_feed` post type has none). A role added later does not receive the capability until the plugin is
   activated again.
5. **Deactivation** (`Plugin::deactivate()`): never deletes the feed configuration or the capability.
6. **Uninstall** (`uninstall.php`): the only place where data is removed, and only when the setting "Delete all
   the data of the plugin when the plugin is deleted" is on (off by default; a missing or damaged value means
   keep). The plugin is not loaded at that time, so the file registers the autoloader and the feeds are found by
   the name of the post type. Feeds in every status (trash included) are deleted with their meta, then the option
   `kkce_settings` and the capability `kkce_read_export` from every role and every user who received it directly.
   Multisite network activation is not supported in V1.

Add new hooks in `Plugin::boot()` (or in a class it wires), never as side effects of including a file.


## Conventions and Intentional Patterns

- Namespace `KKCE\` with WordPress-style class and file names (for example `KKCE\Feed_Repository` in
  includes/class-feed-repository.php); global names are prefixed `kkce_` / `KKCE_`. This is a deliberate
  decision (2026-11-07): no PSR-4, no Composer autoloader at runtime, no `vendor/` in the package (see
  *Code structure* in `AGENTS/CODING_STANDARDS.md`).
- Pure logic (mapping, normalization, validation) lives in classes that call no WordPress function;
  WordPress access is confined to thin wiring classes (hooks, source providers, REST controllers).
- Hook callbacks are static methods referenced as `array( Class::class, 'method' )`, so tests can check
  them with `has_action()` / `has_filter()`.
- Every PHP file in `includes/` starts with `defined( 'ABSPATH' ) || exit;`.
- The plugin's own data uses core APIs only (custom post type, `register_post_meta()`, native meta
  boxes, Settings API); ACF, CMB2 and Polylang are read only through guarded, optional integrations.
- Text domain loading on `init` with `load_plugin_textdomain()` is intentional (see *Internationalization*
  in `AGENTS/CODING_STANDARDS.md`): do not remove it to silence Plugin Check.


## Public Endpoints and Hooks

One public REST endpoint (below); no shortcode, cron job or WP-CLI command, and the plugin offers no hook of its own.
Hooks used (all in `Plugin::boot()`): `rest_api_init` (`Rest\Admin_Routes::register()` and `Rest\Feed_Routes::register()`), `manage_kkce_feed_posts_columns`, `manage_kkce_feed_posts_custom_column`,
`post_row_actions` (removes Quick Edit from the feeds, because it could change a slug without validation),
`add_meta_boxes_kkce_feed` (`Feed_Editor` and `Mapping_Box`), `wp_insert_post_data`, `save_post_kkce_feed`, `admin_notices`, `untrashed_post`,
`admin_enqueue_scripts` (`Feed_Editor::enqueue_assets()` and `Mapping_Box::enqueue_assets()`), `admin_menu` and `admin_init` (the settings page; `admin_menu` also adds the guide, `Guide_Page::add_menu()`, registered after the settings so it follows them in the menu)
(`Feed_Post_Type::disable_restored_feed()`: a feed restored from the trash starts disabled).
`wp_insert_post_data` runs for every post type and returns at once for anything but `kkce_feed`; it is hooked twice:
`Feed_Post_Type::protect_slug()` (priority 9) keeps the slug of a feed, then `Feed_Editor::filter_post_data()` (10) sets it
for an authorized save of the editor. The editor warns, at every visit, a feed restored without its slug ("another feed
now uses the slug …") until it gets a slug, then removes the meta.

Rules of the editor: a field with an invalid value keeps its previous value and is reported in a notice, the
submitted slug is validated, never repaired: `filter_var()` with `FILTER_VALIDATE_REGEXP` and `Feed_Validator::SLUG_PATTERN`
gives it back unchanged or false, so malformed or non-string input gives the format error and keeps the stored slug
(no text sanitizer can turn it into another valid slug, and no `phpcs:ignore` is needed);
other fields are saved; the slug and at least one source post type are required (an empty slug is an error, never taken from the title: it is the public address of the feed; the box marks both as required with visible text and `aria-required`, never the HTML `required`, which would block saving a draft); a feed can be enabled only with them and a usable slug (when the submitted
slug is refused, the stored one is kept but checked with the same rules, because WordPress can assign a slug
outside the editor: from the title of a published post without one, written by WordPress after the filters; on restore
from the trash the plugin gives the old slug back only when no other feed uses it); a slug that is invalid,
reserved or used by another feed is refused, never renamed silently (WordPress alone would add "-2", so the
slugs it renames itself, `feed`, `rss`, `rss2`, `rdf`, `atom` and `embed`, are reserved too); every source
post type must be a public post type, `attachment` excluded (a list with one that is not is refused as a whole and
the previous list is kept; duplicates are saved once), or one already saved for the feed: a saved post type that is no
longer selectable (its plugin deactivated) stays checked with the note "not available" and is kept until it is unchecked; the items per page of a feed cannot exceed the
global maximum of the settings (a feed that already exceeds a lowered maximum keeps its value, and the editor
reports it at every save until it is lowered).

Settings page: the warnings are shown on the page only, not on every admin screen. The REST check asks a new
`WP_REST_Server` object to `check_authentication()` (the core method that runs the filter `rest_authentication_errors`)
as an anonymous user: no `rest_api_init` runs and no request is made; the Application Passwords check asks
`wp_is_application_passwords_available()` and looks at enabled feeds only (first 100).

Admin REST routes (`Rest\Admin_Routes`, namespace `kkce-admin/v1`, kept out of the `/wp-json/` index), used by
the mapping editor. Permission: the capability `edit_posts` of the `kkce_feed` post type (today `manage_options`,
the same as the editor); anonymous requests get 401, other users 403. The editor JavaScript authenticates with the
cookie and the REST nonce (`wp_rest`). Arguments are validated by their schema (400 on error); `post_types` is a
list of 1 to 100 keys (`[a-z0-9_-]{1,20}`), and the ones that are not selectable (not public, `attachment`,
unknown) are left out.
- `GET /catalog?post_types[]=...&meta_keys[]=...`: `Admin_Routes::describe_catalog()`, also printed by the mapping box as its initial
  data: `post_types` (selectable ones), `unavailable` (the others), `labels` (post type => label), `sources` (id, label,
  group, post type, declared type, options, `hidden` (a post meta whose key starts with an underscore), `needs_confirmation`,
  `suggested_key` from `Mapping::suggest_key()`) from `Catalog_Factory` (with the keys in use), `suggested_rows`. `meta_keys` are keys typed by
  the administrator or used by the saved mapping: the selectable ones are listed for every post type, the others are given back in
  `refused_meta_keys`.
- `POST /preview` with `mapping` (JSON text, read with `Mapping::decode()` and checked with `Catalog_Validator` against
  the catalog of every requested post type, the same check as the save), `post_types` (all the post types of the feed), an
  optional `post_type` (the one to preview, which must be in `post_types`, otherwise 400 `kkce_no_post_types`: the editor
  asks one post type at a time, and the mapping is still checked against the whole feed) and an optional `post_id`. An
  unreadable or invalid mapping gives 400 `kkce_invalid_mapping` with every error (`row`, `field`, `code`; `json`
  for unreadable text, `too_large` over `Mapping::MAX_BYTES` bytes, also in the saved form); no selectable post type is checked first (400 `kkce_no_post_types`), then the mapping; no selectable post
  type gives 400 `kkce_no_post_types`. With `post_id`, the item must be published, without a password and of a
  selectable post type of the request, otherwise 404 `kkce_item_not_found`; without it, the latest modified
  published item without a password of every post type, or of `post_type` only. Response: `items` (`post` with id, type and stored title;
  `data`, the object of `Item_Exporter`, always a JSON object) and `empty_types` (post types without items). A source
  that the previewed post type does not have (a field of another post type of the feed) gives a missing value.
- `GET /items?post_type=...`: the latest modified items of a selectable post type that the preview can use (published,
  without a password; at most `Admin_Routes::RECENT_ITEMS`, 20), newest first, each with `id`, stored `title` and
  `modified` (GMT, ISO 8601). A post type that is missing, malformed or not selectable gives 400 (`kkce_no_post_types`
  when not selectable). The preview uses the same query for its latest item.

Feed endpoint (`Rest\Feed_Routes`, namespace `kkce/v1`), registered on `rest_api_init` only while the endpoints
are switched on in the settings (otherwise WordPress answers 404 `rest_no_route`). The setting `show_in_index` (on by
default, and on when the stored value is damaged) decides whether the route is listed in `/wp-json/` and
`/wp-json/kkce/v1`; the route answers in both cases, `OPTIONS` still describes it and the namespace `kkce/v1` stays in
the list of the namespaces. The admin routes are never listed:
- `GET /kkce/v1/<slug>` (the slug in `Feed_Validator::SLUG_PATTERN`; the route matches without regard to case, so the
  slug is checked again with `Feed_Validator::validate_slug()` and must equal the `post_name` exactly). A feed is served
  when it is published (`post_status` `publish`), enabled (`_kkce_active`) and has mapping rows; a missing, unpublished,
  disabled feed, or one whose stored mapping is empty or damaged, gives the same 404 `kkce_feed_not_found`. The
  `permission_callback` (`can_read()`) gives that 404, then applies `Rest\Feed_Access` when the feed needs an
  authentication (its access `authenticated`, or the setting that requires it for every feed): the request must be
  authenticated with an Application Password (`rest_get_authenticated_app_password()`; a session cookie with the REST
  nonce or another authentication plugin is not enough, also for an administrator), otherwise 401
  `kkce_authentication_required`; its user must have the capability `kkce_read_export`, otherwise 403 `rest_forbidden`.
  A `rest_post_dispatch` filter (`Feed_Routes::authentication_challenge()`, registered in `Plugin::boot()`) adds
  `WWW-Authenticate: Basic realm="KK Content Exporter"` to 401 responses on the plugin's registered endpoints
  when WordPress Application Passwords are available. This includes anonymous admin requests and authentication
  errors returned by WordPress before dispatch, even on public feeds. It checks the matching registered callback,
  preserves existing challenges and leaves core routes, other plugins' routes and other statuses unchanged.
  It never enables Application Passwords or changes authentication or permission decisions.
  HTTPS follows WordPress, which offers Application Passwords on HTTPS only, except in environments of type `local`; the
  plugin adds no check. A wrong password is refused by WordPress before the route. Items: published, without a password, of the post types of the feed
  that can still be exported (`Exportable_Post_Types`), computed by `Item_Exporter` as an anonymous visitor.
  Item queries, including the count for an empty page, also run as an anonymous visitor, so user-dependent
  query filters apply the same visibility to public and authenticated reads. The previous user is restored
  in `finally`, including on query exceptions; authentication and capability checks keep the requesting user. Response:
  `Output\Feed_Response`. Cost: a page costs the same number of queries with 1 item and with 5 (checked by
  `KKCE_Feed_Query_Cost_Integration_Test`): the query loads posts, meta and terms of the whole page, and
  `update_post_author_caches()` and `update_post_thumbnail_cache()` load their authors and their featured images
  (attachments with their meta) at once before the items are exported.
- Parameters, validated by their schema (400 `rest_invalid_param` otherwise): `page` (whole number from 1 to
  2147483647, default 1; a page after the last one gives 200 with an empty `items` and the real total, counted by a
  second query of IDs, because WordPress does not count the items of an empty page; a page whose offset, (page - 1) x per page, does not fit in a PHP integer, possible because the maximum items per page has no ceiling, is answered the same way without the query of the items, `Feed_Response::offset_fits()`; the number of pages is computed without overflow) and `per_page` (whole number of at
  least 1; by default the value of the feed within the global maximum; a value above the global maximum is refused,
  with the maximum in the message, by `Feed_Routes::validate_per_page()`). Other parameters are ignored.
- Date filters (`Rest\Date_Filters`, checked by `Feed_Routes::validate_date_filter()`): `modified_after` and
  `modified_before` on `post_modified_gmt`, `published_after` on `post_date_gmt`, through a `date_query` of the query of
  the feed (also the count of an empty page). A date is written as the dates of the feed: ISO 8601 with a time zone
  (`2026-11-01T10:00:00Z`, or an offset `+01:00` converted to UTC), fractions of a second cut; a date without a time zone
  or impossible gives 400 with an example. Input years below 1000 are refused before timestamp conversion; the UTC result must also remain within years 1000 to 9999. Half-open interval: `after` included, `before` excluded; `modified_after`
  equal to or later than `modified_before` gives 400 (checked in `serve()`). Each filter has a switch in the settings
  (on by default); a filter turned off gives 400, never an ignored parameter. Known limit of the incremental reading
  (`KKCE_Feed_Modified_Date_Integration_Test`): every save of the post moves `post_modified_gmt` (the classic editor,
  `wp_update_post()`, and the block editor, which sends changed meta and terms to the REST route of the post), but a
  change made only to meta, terms or the featured image through the PHP functions of WordPress (`update_post_meta()`,
  `wp_set_post_terms()`, `set_post_thumbnail()`) does not, so `modified_after` does not return that item until it
  is saved again; documented in the FAQ of `readme.txt`.
- Language (`lang`, optional, checked by `Feed_Routes::validate_lang()`): a Polylang language code
  (`Language_Provider::languages()`); 400 without Polylang or for a language the site does not have, never an ignored
  parameter. It keeps the items with the term of that language and every item of the post types Polylang does not
  translate; items of a translated post type without a language go. Applied by the filter `posts_where`
  (`Feed_Routes::filter_by_language()`, a subquery on `term_relationships` with the `term_taxonomy_id` of the
  language), added around the query of a feed only (also the count of an empty page) and applied only to the queries
  with the query variable `kkce_feed_language`. Without `lang`, every language, as the REST routes of WordPress do with
  Polylang: Polylang adds no language condition to the queries of a REST request (it only sets its current language
  when it sees `lang`).
- Order: `post_modified_gmt ASC, ID ASC`, stable across pages and post types and independent of the time zone of the
  site (the local modified date repeats when daylight saving time ends). `WP_Query` cannot order by the GMT date: the
  filter `posts_orderby` (`Feed_Routes::order_by_modified_gmt()`) is added around the query of a feed only, removed in
  `finally`, and changes only the queries with the query variable `kkce_feed_order`. The query keeps the filters of
  other plugins (`suppress_filters` is false).

Planned for 1.1.0: a mode with the IDs only, the parameter `ids` and the aggregated log of the export errors.
No shortcodes, cron jobs or WP-CLI commands are planned for 1.0 (the log of 1.1.0 brings a daily clean-up).


### Export rendering scope

`Item_Exporter` temporarily establishes the exported post and the loop globals populated by
`setup_postdata()`, alongside the anonymous user. It restores the exact previous values and unset
state in `finally`, including after a rendering exception. The scoped PHPCS suppression on the
assignment of `$GLOBALS['post']` is intentional: WordPress block context and template tags require
that global, and `setup_postdata()` alone does not set it. A second line-scoped suppression permits
restoring the saved WordPress loop globals: their names belong to core and cannot be prefixed.

`Content_Renderer` short-circuits WordPress HTTP API calls with `pre_http_request` throughout block,
shortcode and URL rendering. Each call uses its own temporary callback, removed in `finally`, so
nested rendering keeps the outer guard. This covers HTTP API requests from dynamic blocks as well
as opted-in shortcodes; arbitrary third-party PHP that bypasses the WordPress HTTP API is outside
this guard. `Url_Absolutizer` reads srcset URLs up to ASCII whitespace and keeps internal commas,
including those in data URLs.

`Normalizer` preserves literal comparisons in plain text, refuses integer text outside the PHP
integer range (including values with leading zeros), and refuses null bytes before parsing dates.
These invalid numbers and dates give `null`, like every row without a value.

## Technology Stack

- Platform: WordPress 6.4+ (development site on WordPress 7.1), PHP 8.3+.
- No runtime dependencies; no JavaScript or CSS build: the admin scripts are vanilla JavaScript (decision
  2026-11-07) and no CSS framework is used.
- Development tools (Composer): PHP_CodeSniffer with WordPress Coding Standards and
  PHPCompatibilityWP; PHPStan with `szepeviktor/phpstan-wordpress` and `php-stubs/wordpress-stubs`;
  PHPUnit 9.6 with Yoast PHPUnit Polyfills; Brain Monkey; WP-CLI with `i18n-command`.


## Quality checks

`WordPress.DB.SlowDBQuery` is disabled centrally in PHPCS by operator decision (2026-11-11): meta-query
performance is assessed through query counts and representative data, rather than argument-name
warnings. Inline suppressions for this sniff are unnecessary; other database checks remain active.

Canonical list of quality-check commands for AI agents and contributors; run them from the plugin
root. All scripts are non-interactive and can be reused by a future CI.

| Command | What it does | Run by the pre-commit hook |
|---|---|---|
| `composer run lint` | `php -l` on every first-party PHP file (`bin/php-lint.php`) | Staged PHP files only |
| `composer run phpcs` | PHPCS with `phpcs.xml.dist`; target: 0 errors and 0 warnings | Staged PHP files, errors only |
| `composer run phpcbf` | Automatic PHPCS fixes | No |
| `composer run phpstan` | PHPStan on the whole plugin at the level of `phpstan.neon.dist` (8), no baseline | Yes |
| `composer run test:unit` | Unit suite (includes the contract tests listed in `tests/README.md`) | Yes |
| `composer run test:all` | Unit suite, then the integration suite in Docker | No |
| `composer run test:integration` (`npm run test:integration`) | Integration suite in the wp-env test environment (`.wp-env.inttest.json`, port 8891, database `kkce_wordpress_test` created on start; `tests/bin/integration.mjs`, which first checks that Docker answers, then runs the `integration` suite and the `polylang` suite in two PHPUnit processes) | No |
| `composer run env:start` / `env:stop` / `env:destroy`, and `env:tests:start` / `env:tests:stop` / `env:tests:destroy` for the integration environment, `env:stop:all` / `env:destroy:all` for both (npm scripts of the same name; `destroy` deletes without confirmation) | wp-env development environment for the E2E suite (`.wp-env.e2etest.json`, WordPress 7.1.4, PHP 8.3, http://localhost:8890) | No |
| `composer run test:e2e` (`npm run test:e2e`) | Starts the wp-env development environment when needed, then the Playwright E2E suite (`tests/bin/e2e.mjs`; `tests/e2e/`, Chromium, one worker, axe-core on the elements of the plugin); `composer run test:e2e:headed`, `test:e2e:ui`, `test:e2e:report` for debugging | No |
| `composer run check` | `lint`, `phpcs`, `phpstan`, `test:unit` | — |
| `composer run make-pot` | Regenerates `languages/kk-content-exporter.pot` | No |
| `composer run i18n` | `make-pot`, `update-po`, `make-mo`, `make-json` | No |
| `composer run hooks:install` | Activates `.githooks/` in the clone | — |
| `composer run build:zip` | Builds `build/kk-content-exporter-<version>.zip` (clean tree, `check`, `.distignore`, package verification) | No |
| `composer run svn:prepare` | Prepares the WordPress.org SVN working copy from the build (dry run by default, `-- --apply`; never commits) | No |
| `composer audit` | Known vulnerabilities in the Composer dependencies | No |
| Plugin Check on the built package (`wp plugin check <plugin-root>/build/kk-content-exporter` from the Local site root) | WordPress.org directory checks; complements PHPCS (details in *WordPress.org Plugin Directory Rules*, `AGENTS/CODING_STANDARDS.md`) | No; before every release |

The commit-msg hook also blocks AI attribution (`Co-Authored-By` trailers, "Generated with" lines) and
first lines without an allowed prefix or longer than 255 characters. CI on GitHub Actions (`.github/workflows/ci.yml`)
validates Composer configuration and its lockfile before installation, runs `composer run check` on PHP
8.3 and 8.4, `composer audit --locked`, `git diff --check` on every versioned file, the release ZIP build
(`build:zip -- --skip-checks`), `npm audit --audit-level=high` and actionlint (container pinned by digest).
Integration tests use wp-env on WordPress 7.1.4 and the minimum 6.4 (`WP_ENV_CORE`; Polylang tests are
skipped on 6.4 because Polylang 3.9.2 needs 6.8). CI runs on push/pull request to `main` and `dev`, and
supports `workflow_dispatch`. Runs superseded on the same workflow/ref are cancelled. Check/package
jobs have 15-minute timeouts, other short jobs 10, integration 30. All checkouts disable persistent
credentials and workflow permissions remain `contents: read`.

`.github/workflows/functional.yml` runs the end-to-end suite manually with a 40-minute timeout.
Manual workflow definitions must be on the default branch for GitHub to offer *Run workflow*.
Both workflows keep calling the project scripts. Actions are pinned to a full commit SHA with the tag
in a comment (`KKCE_CI_Workflow_Unit_Test`): to update one, read the commit of the new tag
(`git ls-remote --tags`) and change the SHA and comment together. Plugin Check is not in CI yet.

`.github/dependabot.yml` schedules monthly GitHub Actions, Composer and npm updates to `dev`, capped
at three open PRs per ecosystem, with minor/patch groups for Composer/npm and separate major updates.
Maintainers review and merge the PRs. Scheduled updates require this configuration on the default branch.
The npm tools are development-only; targeted overrides for rimraf, Express and isomorphic-git replace
vulnerable transitive pins. Reassess and remove each override when upstream dependencies include the
fix; run npm audit, integration and E2E whenever changing these tools.


## References

- Project scope and boundaries: `AGENTS/PROJECT.md`.
- Rules and checklists: `AGENTS/CODING_STANDARDS.md`.
- Tests: `tests/README.md`, `tests/unit/README.md`, `tests/integration/README.md`.
- Translations: `AGENTS/I18N.md`.

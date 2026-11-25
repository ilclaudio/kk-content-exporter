=== KK Content Exporter ===
Contributors: ioclaudio
Author: ioclaudio
Author URI: https://www.claudiobattaglino.it/
Tags: export, json, rest-api, feed, content
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.4
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Publish selected WordPress content as read-only JSON feeds through a REST API, with the fields and structure you choose.

== Description ==

KK Content Exporter lets you share selected WordPress content with other websites and applications through read-only JSON feeds, accessed via a REST API.

Choose the content and fields to include, give the fields your own names, and combine different content types in one feed with a common structure.

= Features =

* Include one or more content types and selected WordPress fields, the featured image URL, categories, tags and other taxonomies, or custom fields (post meta).
* On multilingual sites with Polylang, include the language of each item and the group of its translations, or read one language only.
* Rename fields and build values using fixed text, templates, lookup tables and conditions.
* Preview the JSON before saving your feed.
* Learn every rule from the built-in guide, with examples, under **Content Exporter > Guide**.
* Share public feeds or require an Application Password, with pagination and a configurable page limit.
* Let applications read only what changed, with date filters.

Only published, non-password-protected content and selected fields are exported. The plugin makes no requests to external services; other systems read or import the feed themselves.

= Development status =

This plugin is in development. Feeds are served, with pages, access control and date filters, and can include the URL of the featured image, the terms of the taxonomies and the language. Polylang is optional.

== Installation ==

= Install and activate =

1. Upload the `kk-content-exporter` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins screen.

= Create your first feed =

This example creates a public feed of post titles and links. You need at least one published post without a password to see sample data.

1. In the WordPress admin, open **Content Exporter > Add New** and name the feed **News**.
2. In **Feed settings**, enter `news` in **Slug** and select **Posts** under **Source**.
3. Under **Access**, choose **Public: anyone can read the feed**. For this public example, **Require authentication for all feeds** must be off in **Content Exporter > Settings**.
4. In **Mapping**, click **Add row**. Use **Rule** = **Field**, choose **Title**, and set **JSON key** to `title`.
5. Add a second row with **Rule** = **Field**, **Permalink** as the field, and `url` as the **JSON key**.
6. Check the preview, then turn on **Enable the feed** and click **Publish** (or **Update** for an existing feed). The feed must be both published and enabled.

To use the other rules (fixed values, templates, lookup tables and conditions), open **Content Exporter > Guide**: it explains every rule with examples. The Mapping box links to it, and every row links to the tab of its rule.

= Read the feed =

Copy the feed URL shown below **Slug** and open it in your browser. For the example above:

`https://example.com/wp-json/kkce/v1/news`

Use your site's address in place of `https://example.com`. Applications read the same URL with an HTTP `GET` request and no request body. From a terminal, for example:

    curl 'https://example.com/wp-json/kkce/v1/news?page=1&per_page=10'

Here, `page=1` selects the first page and `per_page=10` requests ten items. Increase `page` to read the next page; the page size cannot exceed the maximum in the plugin settings.

The JSON response contains `items` (the selected fields), `pagination` (page information) and `meta` (feed information). Items are sorted by modification date, oldest first, so pages stay stable while an application reads them.

= Global settings =

Open **Content Exporter > Settings** to see the **Content Exporter settings** page. These settings apply to all feeds. Click **Save Changes** to apply them.

* **Endpoints — Serve the feeds** (on by default): the main switch for serving feeds. Turn it off to stop all feed endpoints from answering. Your feeds and their configuration are kept. When it is on, each feed must still be published and enabled individually.
* **REST API index — Show the feed endpoint in the REST API index** (on by default): lets tools discover the feed route and its parameters through `/wp-json/`. Turning it off hides that description, but feeds can still be read at their URLs. Use authentication to restrict access.
* **Authentication — Require authentication for all feeds** (off by default): requires an authorized user's Application Password for every feed, including those marked **Public**. Leave it off to use each feed's own **Access** setting. See the authenticated-feed FAQ below for connection requirements.
* **Maximum items per page** (100 by default): the largest page any feed can return. Enter a whole number of at least 1. A feed configured above this maximum is limited to it; a request that explicitly asks for more is rejected. There is no fixed upper limit, but large pages may slow down the site.
* **Date filters** (all on by default): allow applications to use `modified_after`, `modified_before` and `published_after` (see the FAQ below). A filter turned off is rejected with an error, never ignored.
* **Uninstall — Delete all the data of the plugin when the plugin is deleted** (off by default): when enabled, deleting the plugin permanently removes feeds, their configuration, global settings and the access permission added by the plugin. Deactivation alone does not remove them. Leave this off to keep the configuration for a later reinstall. Source content is always kept.

= Diagnostic messages =

The settings page may also warn about:

* **Public REST access blocked:** another plugin or a site setting may prevent anonymous readers from opening public feeds.
* **Application Passwords unavailable:** authenticated feeds need this WordPress feature, normally available over HTTPS or in a local development environment.
* **Sensitive fields included:** enabled feeds contain fields explicitly confirmed as sensitive or personal. Review whether those values should be shared, especially in public feeds.

These messages describe possible problems; they do not change settings or repair the site automatically.

== Frequently Asked Questions ==

= How do I read an authenticated feed? =

Choose **Authenticated** under **Access** and save the feed. Your application must use HTTPS and a WordPress Application Password for an authorized user. Administrators receive access when the plugin is activated. Being logged into WordPress in a browser is not enough.

= How can an application read only what changed? =

Add `modified_after` with the time of the previous reading, as an ISO 8601 date with a time zone, for example `?modified_after=2026-11-01T10:00:00Z`. `modified_before` and `published_after` work the same way; each filter can be turned off under **Content Exporter > Settings**.

An item is returned again when its modification date changes, which happens every time it is saved, also in the editor. Changes made only to custom fields, categories, tags or the featured image by another plugin or by code, without saving the item, do not change that date: those items are not returned until they are saved again. Applications should therefore also read the whole feed from time to time.

= Does it work on a multilingual site? =

Yes, with Polylang. A feed returns every language unless the application adds `lang` with a language code, for example `?lang=en`; items of content types that Polylang does not translate are always included. To export everything in one feed, add the fields **Language code** and **Translation group** to the mapping: each item then says its language, and translations of the same content share the same group. WPML is not supported.

= Why does my feed answer "not found"? =

A feed answers only when it is published, enabled and has at least one mapping row, and while **Serve the feeds** is on in **Content Exporter > Settings**. A draft, pending or scheduled feed answers "not found" even when it is enabled: the editor and the list of the feeds show a warning. Check also that the slug in the address is written exactly as in the feed, in lowercase.

Other errors: a wrong parameter (for example a page size above the maximum, a date without a time zone or an unknown language) gives "bad request" with an explanation; an authenticated feed read without an Application Password gives "unauthorized", and one read by a user without access gives "forbidden".

= Does the plugin change my content or WordPress's REST API? =

No. It leaves the original content, its visibility and WordPress's existing REST API unchanged. Feeds have their own addresses and configuration.

= Does the plugin work on a multisite network? =

It works on a single site. Network activation is not supported in this version: activate the plugin on each site where you need it.

== Changelog ==

= 0.0.4 =
* In development: feed endpoint with pages, access control and date filters; featured image URL; terms of the taxonomies; language of the site and, with Polylang, of each item, with the `lang` parameter; warnings for enabled feeds that are not published; mapping rows laid out as a form; a guide to the rules.

= 0.0.3 =
* Post meta fields in the mapping: the keys in use, hidden keys listed apart, and any key typed by hand.

= 0.0.2 =
* Fixes in the mapping editor: refused drafts stay editable, sensitive fields typed in a template ask for confirmation at once.

= 0.0.1 =
* Feeds with one or more post types, settings page and uninstall cleanup.
* Field mapping with five rules, live preview and save; sensitive fields only after confirmation.
* Italian translation.

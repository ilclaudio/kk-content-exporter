=== KK Content Exporter ===
Contributors: ioclaudio
Author: ioclaudio
Author URI: https://www.claudiobattaglino.it/
Tags: export, json, rest-api, feed, content
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Share WordPress content with other websites and applications through custom JSON feeds with a consistent structure.

== Description ==

KK Content Exporter makes your WordPress content available to other websites, applications and importers in a JSON format you define.

Use the feeds to import content into other WordPress sites or external systems, adapting fields and values to the destination's needs.

Combine posts, pages and custom post types in one JSON feed with a common structure. Define shared rules and add exceptions for each post type, mapping different source fields to the same output keys.

Feeds are available through dedicated, read-only REST API endpoints. The original content stays unchanged.

= Features =

* Combine multiple post types in one feed, with shared mapping rules and exceptions for each type.
* Select WordPress fields, the featured image URL, categories, tags, other taxonomies and custom fields (post meta).
* On multilingual sites with Polylang, include the language of each item and the group of its translations, or read one language only.
* Rename fields and build values using fixed text, templates, lookup tables and conditions.
* Preview the JSON before saving your feed.
* Learn every rule from the built-in guide, with examples, under **Content Exporter > Guide**.
* Share public feeds or require an Application Password, with pagination and a configurable page limit.
* Let applications read only what changed, with date filters.

Only published, non-password-protected content and selected fields are exported. The plugin makes no requests to external services: it provides the feeds, while the external system reads them, imports the content and keeps its own copy up to date.

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

For examples of every mapping rule, open **Content Exporter > Guide** or follow the guide links in the Mapping box.

= Read the feed =

Copy the feed URL shown below **Slug** and open it in your browser. For the example above:

`https://example.com/wp-json/kkce/v1/news`

Replace `https://example.com` with your site's address. Applications use an HTTP `GET` request, for example:

    curl 'https://example.com/wp-json/kkce/v1/news?page=1&per_page=10'

This requests ten items from page 1. Increase `page` to continue; `per_page` cannot exceed the maximum in the settings.

The JSON response contains `items` (the selected fields), `pagination` (page information) and `meta` (feed information). Items are sorted by modification date, oldest first, then by ID when dates match. Content changes during reading can move items between pages.

= Global settings =

Open **Content Exporter > Settings** to configure all feeds, then click **Save Changes**.

* **Serve the feeds** (on by default): switch all endpoints on or off without deleting their configuration; each feed must also be published and enabled.
* **Show the feed endpoint in the REST API index** (on by default): allow discovery through `/wp-json/`; hiding it does not restrict access to feed URLs.
* **Require authentication for all feeds** (off by default): require an authorized user's Application Password even for public feeds; see the FAQ below.
* **Maximum items per page** (100 by default): cap the page size for all feeds; requests above the maximum are rejected, and large pages may slow down the site.
* **Date filters** (all on by default): allow `modified_after`, `modified_before` and `published_after`; disabled filters return an error.
* **Delete all the data of the plugin when the plugin is deleted** (off by default): remove feeds, settings and the plugin's access permission on deletion; deactivation keeps them, and source content is always kept.

= Diagnostic messages =

The settings page reports:

* **Public REST access blocked:** another plugin or setting may block anonymous readers.
* **Application Passwords unavailable:** authenticated feeds need this feature, normally available over HTTPS or locally.
* **Sensitive fields included:** review the sensitive fields confirmed for enabled feeds, especially public ones.

These messages do not change settings automatically.

== Frequently Asked Questions ==

= Which custom fields can I export? =

You can select post meta with simple values, such as text, numbers and booleans, or enter a meta key manually. ACF and CMB2 fields can be read as stored post meta; repeaters, groups, relationships and other complex structures are not supported in this version.

= Which author details can I export? =

Only mapped author fields: ID, display name and archive URL (the URL requires confirmation), never e-mail. The preview warns when the display name matches the login or the archive URL may reveal it.

= How do I read an authenticated feed? =

Choose **Authenticated** under **Access** and save the feed. Your application must use HTTPS and a WordPress Application Password for an authorized user. Administrators receive access when the plugin is activated. Being logged into WordPress in a browser is not enough.

= Which user should an application use to read authenticated feeds? =

Use a dedicated user with only the `kkce_read_export` capability to limit the impact of a leaked password. For example, with WP-CLI:

    wp role create kkce_reader "Feed reader"
    wp cap add kkce_reader kkce_read_export
    wp user create feed-reader feed-reader@example.com --role=kkce_reader
    wp user application-password create feed-reader "Importer" --porcelain

The last command prints the Application Password. The application sends it with the user name using HTTP Basic authentication over HTTPS. This role cannot access the dashboard. Without WP-CLI, use a role editor plugin to create the role, then create an Application Password in the user's profile as an administrator.

Revoke the password or delete the user when no longer needed. The plugin never generates or stores passwords. Uninstall cleanup removes its capability, but keeps the custom role.

= How can an application read only what changed? =

Use `modified_after` with the previous reading time in ISO 8601 with a time zone, for example `?modified_after=2026-11-01T10:00:00Z`. The other date filters are `modified_before` and `published_after`.

Saving an item updates its modification date. Changes made only to custom fields, terms or the featured image by code may leave that date unchanged, so date filters miss them until the item is saved again. Applications should also read the whole feed periodically.

= Does it work on a multilingual site? =

Yes, with Polylang. A feed returns every language unless the application adds `lang` with a language code, for example `?lang=en`; items of content types that Polylang does not translate are always included. To export everything in one feed, add the fields **Language code** and **Translation group** to the mapping: each item then says its language, and translations of the same content share the same group. WPML is not supported.

= Why does my feed answer "not found"? =

A feed must be published, enabled and have mapping rows, with **Serve the feeds** on in the settings. Draft, pending and scheduled feeds answer "not found" even when enabled. The URL must match the feed's lowercase slug exactly.

A feed restored from the trash starts disabled. If another feed took its slug meanwhile, it comes back without a slug: choose a new one in the editor, which says which slug is taken.

Other errors: a wrong parameter (for example a page size above the maximum, a date without a time zone or an unknown language) gives "bad request" with an explanation; an authenticated feed read without an Application Password gives "unauthorized", and one read by a user without access gives "forbidden".

= Does the plugin change my content or WordPress's REST API? =

No. It leaves the original content, its visibility and WordPress's existing REST API unchanged. Feeds have their own addresses and configuration.

= Is the plugin available in my language? =

The plugin is written in English. Translations are made by the community on translate.wordpress.org, and WordPress installs them automatically on sites in that language. Anyone can contribute a translation.

= Does the plugin work on a multisite network? =

It works on a single site. Network activation is not supported in this version: activate the plugin on each site where you need it.

== Screenshots ==

1. The Mapping box: each row writes one JSON key, with a live preview of the items a reader will receive.
2. Feed settings: address, source content types, public or authenticated access, items per page and status.
3. The list of feeds with their address, source, access and status.
4. Global settings: serving the feeds, REST API index, authentication, page limit and date filters.
5. The built-in guide that explains every mapping rule with examples.
6. The JSON of a public feed, read with a plain GET request without authentication.

== Changelog ==

= 1.0.1 =
* First public release.

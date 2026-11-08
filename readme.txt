=== KK Content Exporter ===
Contributors: ioclaudio
Author: ioclaudio
Author URI: https://www.claudiobattaglino.it/
Tags: export, json, rest-api, feed, content
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Exposes published content of a chosen post type as configurable, read-only JSON feeds.

== Description ==

KK Content Exporter lets an administrator choose a post type, configure one or more feeds and map the fields of the content to freely named JSON keys. Each feed is served by a read-only REST endpoint.

**This is a development version.** Feeds can already be created and configured, but they are not served yet: the field mapping and the endpoints arrive before version 1.0.0.

Planned for version 1.0.0:

* Feeds stored in a private administrative post type.
* Free mapping of WordPress fields, selected post meta, taxonomies, featured image and language to JSON keys.
* Optional, read-only support for simple fields of Advanced Custom Fields, CMB2 and Polylang, when those plugins are active. None of them is required.
* Public or authenticated (Application Passwords) feeds, with pagination and incremental reading.
* Only published, non password-protected content is ever exported. The plugin never writes to the source content.

The plugin makes no request to external services.

== Installation ==

1. Upload the `kk-content-exporter` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate the plugin through the Plugins screen.

== Frequently Asked Questions ==

= Does the plugin change the native REST API? =

No. Feeds are served under their own namespace and the plugin does not change the `show_in_rest` setting of any post type.

= Are drafts or private posts exported? =

No. Only published content that is not protected by a password is exported.

== Changelog ==

= 0.0.1 =
* Project initialization: plugin bootstrap, translations (Italian included), development tooling. No user-facing features yet.

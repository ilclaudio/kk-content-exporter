# Reading a feed

This guide is for the developers of a program that reads the feeds of KK Content Exporter, for example an
importer on another site. How to create and configure a feed is explained in [`readme.txt`](../readme.txt) and, inside
WordPress, on the **Content Exporter > Guide** page, which describes every rule of a mapping with examples.

## The address

Every feed has its own address, shown in the feed editor under **Slug**:

```
GET https://example.com/wp-json/kkce/v1/<slug>
```

On a site without pretty permalinks the address is `https://example.com/?rest_route=/kkce/v1/<slug>`. Use the address
the editor shows. Only `GET` is served, without a request body.

A feed answers only while it is published and enabled, has at least one mapping row, and **Serve the feeds** is on in
the settings of the plugin. Otherwise the answer is `404`.

## The response

```json
{
  "items": [
    {
      "id": 12,
      "title": "Summer concert",
      "link": "https://example.com/summer-concert/",
      "image": null,
      "updated": "2026-11-20T09:15:00Z"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 50,
    "total": 1,
    "total_pages": 1
  },
  "meta": {
    "feed": "news",
    "generated_at": "2026-11-26T10:00:00Z"
  }
}
```

- `items`: one JSON object per published item. Its keys are the JSON keys of the mapping rows of the feed, in the
  order of the rows.
- `pagination`: the page read, its size, the number of items of the feed and the number of pages (`0` without items).
- `meta`: the slug of the feed and when the response was computed (UTC).

### The values of an item

- **Every item has every key**, also when a row has no value for it: the program always finds the same keys.
- A key without a value is `null`. An empty text stays `""` and an empty list stays `[]`.
- Dates and times are ISO 8601 in UTC (`2026-11-20T09:15:00Z`); a date without a time stays `2026-11-20`.
- URLs are absolute (`http` or `https`).
- The type of each value (text, HTML, number, true or false, date, URL, list) is chosen by the administrator in the
  row; a value that cannot be converted to it is `null`.

The plugin exports only published content without a password, and only the fields of the mapping: never drafts,
private content, unmapped custom fields, or the e-mail address or login name of an author.

## Pages

| Parameter | Value | Default |
|---|---|---|
| `page` | Whole number from 1 | `1` |
| `per_page` | Whole number from 1 to the maximum of the site | The value of the feed |

A `per_page` above the maximum set on the site (100 by default) is refused with `400` and the maximum in the message;
it is never reduced silently. A page after the last one answers `200` with an empty `items` and the real `total`, so
a program that walks the pages does not fail when the feed gets shorter while it reads.

Items are ordered by modification date (UTC), oldest first, then by ID. The order is the same across pages and post
types, and it does not depend on the time zone of the site.

```sh
curl 'https://example.com/wp-json/kkce/v1/news?page=2&per_page=100'
```

## Access

A feed is **public** (anyone can read it) or **authenticated**. The administrator can also require authentication for
every feed. An authenticated feed is read with HTTP Basic authentication, the user name and an
[Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/) of a user
with the capability `kkce_read_export`, over HTTPS:

```sh
curl --user feed-reader 'https://example.com/wp-json/kkce/v1/news'
```

curl asks for the password, so it stays out of the command history; a program sends the same `Authorization: Basic`
header with the user name and the Application Password.

- Being logged into WordPress in a browser, or another authentication plugin, is not enough: only an Application
  Password opens an authenticated feed.
- Without an Application Password the answer is `401`, with `WWW-Authenticate: Basic realm="KK Content Exporter"`
  when the site offers Application Passwords. With a valid one of a user without the capability, `403`.
- An authenticated feed returns exactly the same data as a public one: authentication decides **who** reads, never
  **what**.
- Use a user that can only read the feeds, not an administrator. [`readme.txt`](../readme.txt) explains how to
  create it (FAQ "Which user should an application use to read authenticated feeds?").

## Reading only what changed

| Parameter | Field | Bound |
|---|---|---|
| `modified_after` | Modification date | Included |
| `modified_before` | Modification date | Excluded |
| `published_after` | Publication date | Included |

Dates are ISO 8601 **with a time zone**: `2026-11-01T10:00:00Z` or `2026-11-01T11:00:00+01:00` (converted to UTC).
Fractions of a second are cut. A date without a time zone, an impossible date, or `modified_after` not earlier than
`modified_before` gives `400` with an example. Each filter can be turned off on the site; a filter turned off gives
`400`, never an ignored parameter, so a program never reads more than it asked for.

The interval is half-open: `modified_after` **includes** its bound. Read the next time with `modified_after` equal to
the latest modification date you received: items modified in that same second are never lost, but the items you
already have at that second come again. **Update items by their ID** (the mapping should export it): reading an item
twice is then harmless.

```sh
curl 'https://example.com/wp-json/kkce/v1/news?modified_after=2026-11-20T09:15:00Z'
```

### What the modification date does not see

WordPress moves the modification date of an item every time it is saved, also in the editor. A change made only to
custom fields, categories, tags or the featured image by code or by another plugin, without saving the item, does
**not** move it: `modified_after` does not return that item until it is saved again. Read the whole feed from time
to time.

### Items that disappear

A feed returns only the items that it exports **now**. An item that is deleted, moved to the trash, set back to draft,
made private or protected by a password, or whose post type is removed from the feed, is simply not returned any
more: nothing in the response says that it was removed.

To find these items, read the whole feed from time to time, collect the IDs of every page, and remove from your copy
the items whose ID is not among them. A lighter mode that returns only IDs and dates is planned for version 1.1.0.

## Languages (Polylang)

On a site with Polylang a feed returns the items of **every** language. Two ways to tell them apart:

- the administrator adds a row with a language field (for example **Language code**) and, to group translations,
  **Translation group**;
- or the program reads one language at a time with `lang`, a Polylang language code: `?lang=en`.

With `lang`, the feed returns the items of that language and every item of the post types that Polylang does not
translate; items of a translated post type without a language are left out. `lang` gives `400` on a site without
Polylang or for a language the site does not have. WPML is not supported.

## Errors

Errors use the WordPress REST format: `{ "code": "...", "message": "...", "data": { "status": ... } }`.

| Status | Code | When |
|---|---|---|
| `400` | `rest_invalid_param` | A wrong parameter: page, page size, date, language |
| `401` | `kkce_authentication_required` | An authenticated feed read without an Application Password |
| `403` | `rest_forbidden` | The user of the Application Password cannot read the feeds |
| `404` | `kkce_feed_not_found` | No feed with this slug, or not published, not enabled, without rows |
| `404` | `rest_no_route` | **Serve the feeds** is off in the settings |

A wrong password is refused by WordPress before the feed, with its own `401` error.

## Limits of version 1.0

- No `ETag` or `Last-Modified`: every request computes the page again (each page costs the same number of database
  queries whatever its size).
- No list of removed items, and no mode with the IDs only (planned for 1.1.0).
- Network activation on a multisite network is not supported: each site has its own feeds.

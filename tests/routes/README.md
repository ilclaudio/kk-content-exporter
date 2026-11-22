# Manual checks of the feed endpoint

`Insomnia_Feed_Endpoint.json` is an Insomnia collection (export format 4) of requests to the feed endpoint
`GET /wp-json/kkce/v1/<slug>`: pages, errors, route and methods, access, date filters. Each request says
in its name the status it expects, for example `Missing feed (404)`; without a status it expects 200.

Import it in Insomnia, or run every request from the command line with `run-routes.mjs`.

## Running the requests from the command line

1. Copy `routes.config.example.json` to `routes.config.json` in this folder. `routes.config.json` is
   ignored by Git: it holds the address of the site and the credentials. Never commit it.
2. Fill it in:
   - `base_url`: address and port of the site, without a final slash (`http://kkce.local`,
     `http://localhost:8890`);
   - `feed_slug`: a published, enabled, public feed with at least three items;
   - `unpublished_feed_slug`: an enabled feed left as a draft;
   - `max_per_page`: the maximum items per page of the settings;
   - `since`, `until`: two dates for the date filters, ISO 8601 with a time zone;
   - for the authenticated requests: `authenticated_feed_slug` (a published, enabled feed with the access
     **Authenticated**, `feeditem-auth` in the example; not the public feed), `wp_user` and `wp_app_password` (an
     Application Password of a user with the capability `kkce_read_export`), and
     `wp_user_without_capability` with `wp_app_password_without_capability` (a user without it). Leave
     them empty to skip those requests.
3. Run, from the root of the plugin (Node 24):

```sh
node tests/routes/run-routes.mjs                    # every request
node tests/routes/run-routes.mjs --filter "date"    # only the requests whose folder or name contain the text
node tests/routes/run-routes.mjs --config other.json --collection other-collection.json
```

The script is generic: it replaces the variables `{{ _.name }}` of any Insomnia collection with the
values of `variables` in the configuration (a variable missing there takes the value of the base
environment of the collection, except `base_url`). It prints `PASS`, `FAIL` or `SKIP` (an empty variable)
for each request, with the status and the WordPress error code; credentials are sent as HTTP Basic
authentication and never printed. Exit code 0 when no request failed.

It sends every request of the collection, including the two `POST` requests of *Route and methods* and
*Errors*: they change nothing on the site. Use it only on development sites.

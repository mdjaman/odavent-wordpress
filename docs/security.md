# Security

How the plugin handles secrets, untrusted data and updates, what it does not protect, and how to report a
problem. The short version: the plugin only **reads** from Odavent, stores no attendee data, treats
everything it receives as hostile, and Odavent can only make it re-fetch data.

## The site key

* Stored in the `odavent_events_settings` option, **not autoloaded** (so it is not loaded on every request).
  It is saved as plain text in your database, like other plugin API keys.
* Never printed back: the settings screen shows a masked value (`odv_wp_••••1a2b`) and a blank field keeps
  the stored key. It never appears in a URL, a log line or an error message.
* Only a well-formed key (`odv_wp_` followed by 8 to 120 letters, digits, dashes or underscores) is ever used.
* **Constant override.** Define `ODAVENT_SITE_KEY` in `wp-config.php` and it wins over the stored key; the
  settings field becomes read-only. This keeps the key out of the database and out of database backups.
* It is sent only to the Odavent address from the settings, in an `Authorization: Bearer` header, over
  HTTPS (plain `http://localhost` is accepted on sites whose environment type is `local`). Redirects are
  not followed, so a redirect cannot forward the key somewhere else, and certificates are always verified.
* The key lets the holder read the events of your Odavent organization (drafts included). It cannot register
  anyone, change an event or read attendees. If it leaks, revoke it in your Odavent organization settings
  and connect again.

### How private is the key, really?

The key is only as private as your WordPress installation. While a request to Odavent is being made, any
code running on your site can see it: other plugins hooked on `http_api_debug`, `http_request_args` or
`pre_http_request`, debugging plugins such as Query Monitor (which list outgoing requests and their headers)
and anyone with database or file access. The constant keeps it out of the database but not out of memory.
Treat the key like a password for your event data, install only plugins you trust, and do not leave
request-logging tools active on a production site.

## Refresh pings

Odavent tells your site that an event changed with `POST /wp-json/odavent/v1/refresh`. The route is open
to the internet, so its only protection is a signature:

* Header `X-Odavent-Signature: t=<unix time>,v1=<hex>` where `v1` is the HMAC-SHA256 of `"<t>.<raw body>"`
  keyed with your site key.
* The timestamp must be within **300 seconds** of your server's clock, in either direction. Keep your server
  clock accurate (NTP).
* **Replay protection:** once a signature has been accepted, its hash is remembered for 11 minutes (twice the timestamp window plus a minute) and the same
  request is refused. (The remembering uses transients; with a persistent object cache that evicts entries
  early, a replay inside the 5 minute window could succeed, which is harmless, see below.)
* **Body limit of 8 KB**, checked from the declared length and the real body before anything is hashed.
* The signature is compared in constant time, and every failure (missing header, no key configured, bad or
  stale or replayed signature) gets the same `401` answer, so a caller learns nothing. Rejections are not
  logged, so an attacker cannot fill your logs.
* Nothing is written before the signature is valid.

What a valid ping can do: drop the cached copy of one event and purge the pages that show it. It carries no
content: the next visitor makes the plugin fetch fresh data with your key. So even a forged or replayed ping
could, at worst, cause one extra request to Odavent.

## Draft previews

Events that are drafts on Odavent are not public. They are shown only in this situation: a logged-in user
who has the preview capability (**`edit_posts` by default**, which includes Contributors) views a page or the
block editor. They then see the draft content with a "Draft preview" notice.

* Visitors never see a draft: the plugin keeps a content-free marker for them and shows nothing.
* A draft is never stored in the public cache and never becomes the "last good" copy. Editors' previews live
  in their own cache for 60 seconds.
* A page with Odavent content viewed by someone with the preview capability is sent with no-cache headers and
  the `DONOTCACHEPAGE` constant, so a preview cannot be stored by a page cache and served to visitors. This
  protection looks at the page's own content only; see
  [operations.md](operations.md#page-caches).
* The same capability decides who can list your events (drafts included) in the block editor
  (`GET /wp-json/odavent/v1/events`).

If you do not want Contributors to see unpublished event data, raise the capability:
[`odavent_events_preview_capability`](hooks.md#odavent_events_preview_capability).

## Output escaping and sanitising

Everything in an Odavent payload is treated as untrusted, even though it comes from Odavent: it is
cleaned when it is read and escaped again when it is printed.

* Plain text fields have all tags removed and are escaped with `esc_html()` / `esc_attr()`.
* Organizer-written HTML (descriptions, bios) is reduced with `wp_kses()` to an allow-list: `p`, `br`, `strong`,
  `em`, `b`, `i`, `ul`, `ol`, `li`, `h3`, `h4`, `h5`, and `a` with `href` and `rel` only. No scripts, styles,
  iframes, images, forms, event-handler attributes or `style` attributes. Links are rebuilt afterwards: only
  absolute `http://` and `https://` addresses survive, `target` is dropped and `rel="nofollow noopener"` is
  forced. The list can be extended with [`odavent_events_allowed_html`](hooks.md#odavent_events_allowed_html).
* URLs (links, images, logos) must be absolute `http` or `https`; `javascript:`, `data:`, relative and
  protocol-relative addresses are rejected, and so are URLs with spaces, control characters, quotes or
  angle brackets. Tabs and newlines hidden inside a scheme are removed before the check.
* The schema.org structured data is JSON encoded with `JSON_HEX_TAG` and `JSON_HEX_AMP`, so nothing in the
  data can close the script element.
* Template overrides receive cleaned values and must still escape on output; the built-in templates do.
* Responses are limited to 2 MB and JSON nesting to 16 levels, and a malformed or unexpected answer is
  treated as "Odavent unavailable", never trusted partially.

## Updates

The plugin offers its own updates in the normal WordPress update screen. Because an update installs code
on your site, the checks are strict:

* The plugin header has `Update URI: https://github.com/mdjaman/odavent-wordpress`. WordPress then never
  accepts an update for this plugin from wordpress.org, so a different plugin with the same folder name
  cannot take it over.
* The package address Odavent reports must start with the pinned prefix
  `https://github.com/mdjaman/odavent-wordpress/releases/download/` and be exactly
  `<prefix>v<version>/<file>.zip` for the version offered (the `v` may also be a capital `V`, or left out),
  with a plain file name (no query, fragment,
  `@`, `..` or percent-encoding). The Odavent endpoint therefore **cannot point your site at another host**,
  and cannot present an older release's file as a newer version.
* Only a strictly newer version is offered (never a downgrade). A pre-release such as `1.2.0-beta` is
  offered only to sites already running a pre-release.
* Any release that fails validation is treated like a failed check: nothing is offered and the result is
  cached for an hour. The latest valid release is cached for 12 hours.
* The check runs only in wp-admin, WP-Cron and WP-CLI, never on a front-end request.
* The pinned prefix can be changed only by code on your site, with the
  [`odavent_events_update_package_prefix`](hooks.md#odavent_events_update_package_prefix) filter.

**What is not verified yet: the content of the zip.** There is no published checksum or signature, so
integrity rests on HTTPS and on the security of the `mdjaman/odavent-wordpress` GitHub repository and
its releases. Someone who can publish a release in that repository can ship code to every site that
updates. Odavent can only choose which of the repository's release files is offered (not where it comes
from). If that matters to you, update manually after reviewing the release, or disable auto-updates for this
plugin in Plugins.

## Data the plugin handles

* **No attendee data.** Registration, payment and the attendee list stay on Odavent. The plugin has no form
  that collects visitor data and stores nothing about visitors. Register and "Find my registration" links
  lead to Odavent's own pages. The Register link carries `utm_source=wordpress`, `utm_medium=plugin`,
  `utm_campaign=<your site's host name>` and `return=<the page the visitor came from>`, plus `ticket=<id>`
  when a ticket is chosen.
* **What is sent to Odavent:** your site key, the event (id or slug) and the content language, with a
  `User-Agent` of `Odavent-WP/<version>`. The update check sends no key.
* **What is stored on your site:** the settings, cached event data (transients), a "last good" copy of each
  event used when Odavent cannot be reached, the list of your events for the editor (5 minutes) and the
  update check result. Cached event data is what the public event page shows, plus ticket and venue details.

## Uninstall

Deleting the plugin from the Plugins screen removes its settings option, all cached event data, "last good"
copies, transients and the update check. On multisite it does this for every site and for the network-wide
update check. The blocks and shortcodes you added to pages stay in your content (and show as unknown
blocks); the `ODAVENT_SITE_KEY` constant stays in your `wp-config.php`, where you can remove it.

## Reporting a vulnerability

Please do **not** open a public issue. Use GitHub's private vulnerability reporting for the repository: open
https://github.com/mdjaman/odavent-wordpress/security/advisories/new (the *Security* tab > *Report a
vulnerability* on `mdjaman/odavent-wordpress`). Describe what you found, how to reproduce it and
which version you tested. We will acknowledge the report, work on a fix and credit you when it is released
if you wish.

# Operations

How the plugin behaves in production: live refresh, caches, what visitors see when things go wrong, and what
to check when something looks off.

## How live refresh works

1. You change something on Odavent (a speaker, a session, a ticket, the venue, the event itself).
2. Odavent sends a signed `POST` to `https://your-site/wp-json/odavent/v1/refresh` with the event's id.
   Changes made in quick succession are combined, so it arrives within about a minute.
3. Your site verifies the signature (see [security.md](security.md#refresh-pings)), forgets the cached copy of
   that event (but keeps its last good copy, and lifts the circuit breaker: Odavent just proved it is
   reachable) and answers `200`. It then purges the page cache of the pages that show it after the answer has
   been sent (where the server allows it), so a large site never makes Odavent's 10 second ping time out.
4. The next visitor makes the plugin fetch the fresh data, and the new page is cached again. If that fetch
   fails (Odavent slow, `5xx`, `429`), the visitor gets the last good copy as stale (no Register links), never
   an empty block that a page cache would then keep.

If a ping never arrives, nothing breaks: the cached copy expires after the **cache lifetime** (Settings >
Odavent Events, default 15 minutes, between 60 seconds and 24 hours) and the next view refreshes it. Live
refresh is an accelerator, the cache lifetime is the safety net.

### It needs pretty permalinks

The refresh address is `/wp-json/odavent/v1/refresh`. With the **Plain** permalink setting
(`?p=123` addresses) `/wp-json/` is not reachable, so Odavent cannot deliver pings. The plugin still works,
but pages only refresh when the cache lifetime ends, and the settings page says so. Choose any other option
in Settings > Permalinks to get live updates.

### Security plugins, firewalls and CDNs must let pings through

Odavent signs every ping, and no list of sender IP addresses is published, so you cannot allow a fixed set
of addresses. Allow `POST /wp-json/odavent/v1/refresh` for everyone (the signature is the protection):

* "Disable REST API" / "Restrict REST API" plugins and the matching options of security suites (Wordfence,
  iThemes/Solid Security, All In One WP Security...): add an exception for the `odavent/v1` namespace.
  The plugin's editor route (`/wp-json/odavent/v1/events`) also needs the REST API for logged-in editors.
* Web application firewalls and CDNs (Cloudflare, Sucuri, ModSecurity...): do not challenge, rate-limit or
  block that path. Bot-fight and JavaScript challenges turn the ping into an HTTP `403` or `503`.
* Basic authentication or maintenance-mode plugins in front of the site block pings too.

Odavent shows the outcome of the last ping for your site in its WordPress settings (an HTTP status such as
`http_403`, or `unreachable`, `timeout`) and has a "Send test ping" button.

## Page caches

When a ping arrives the plugin cleans the post cache of every published post of a public post type whose
content contains an Odavent block or shortcode (at most 500 posts), then calls the purge function of the
page-cache plugin that is active:

| Cache | How it is purged |
|---|---|
| WP Rocket | `rocket_clean_post()` |
| W3 Total Cache | `w3tc_flush_post()` |
| WP Super Cache | `wp_cache_post_change()` |
| LiteSpeed Cache | `litespeed_purge_post` action |
| Anything else | the [`odavent_events_purge_post`](hooks.md#odavent_events_purge_post) action runs for each post |

**Blocks inside non-public sources are not detected.** The plugin finds pages by searching the content of
posts. Odavent blocks placed in a **synced pattern**, a **block-theme template or template part** (the Site
Editor), a **block widget area** or a custom field are not in any post's content, so the pages that show
them are not purged, and nothing tells the plugin to load the stylesheet early or to keep editors' previews
out of the page cache. For those, add the pages yourself:

```php
// Pages that show a countdown from a template part.
add_filter( 'odavent_events_purge_post_ids', function ( array $post_ids ): array {
	return array_merge( $post_ids, array( (int) get_option( 'page_on_front' ), 123 ) );
} );

// Load the stylesheet in the head, and keep editors' pages out of the page cache, on every page.
add_filter( 'odavent_events_page_has_content', '__return_true' );
```

(`odavent_events_page_has_content` returning `true` everywhere is simple but turns off page caching for
logged-in editors on the whole site. Return `true` only for the relevant pages if that matters.)

The early stylesheet and the no-cache protection for editors only look at the post's own content as well,
see [`odavent_events_page_has_content`](hooks.md#odavent_events_page_has_content). Pages with such blocks
still work; the stylesheet is simply loaded a little later.

A CDN that caches whole HTML pages needs its own purge in the `odavent_events_purge_post` action (for
example a call to the CDN's API with `get_permalink( $post_id )`).

## Caching, failures and the circuit breaker

* **Cache lifetime:** an event's data is cached for the lifetime set in the settings (default 900 seconds).
* **Last good copy:** every successful fetch of a public event is also saved as a copy that does not expire.
  When Odavent cannot be reached (timeout, error, bad answer) the page shows that copy.
* **Circuit breaker:** after a failed request the plugin stops calling Odavent for **60 seconds** and serves
  the last good copy (or nothing) straight away, instead of every visitor waiting for the 8 second timeout.
* **A stale view never shows Register.** A last good copy may say "open" for an event that has since been
  cancelled, paused or unpublished. While a block shows a stale copy, the Register button and the ticket
  links are replaced by "Registration details could not be refreshed. Please check the event page on
  Odavent." Everything else (speakers, agenda, venue) keeps showing.
* **No copy yet:** if Odavent is down and nothing was ever cached, visitors see nothing in that spot (no
  broken box). Editors see a notice explaining why.
* Clearing the cache (Settings > Odavent Events > Tools > Clear cache) removes cached data and last good
  copies and resets the breaker. The next views fetch fresh data.

## What visitors and editors see

"Editors" are logged-in users with the preview capability (`edit_posts` by default).

| Event state on Odavent | Visitors | Editors |
|---|---|---|
| Published, open (`LIVE`) | Everything; Register button and ticket links when registration is open and a ticket is on sale | The same |
| Published and finished (`ENDED`) | Everything, with "This event has ended." above the header and register blocks. The countdown shows "Event ended". Register is shown only if Odavent still reports registration open | The same |
| `PAUSED` | Everything, with "Registration is temporarily closed." and no Register or ticket links. A countdown whose start has passed hides itself | The same |
| `CANCELLED` | Everything, with "This event has been cancelled." and no Register or ticket links | The same |
| `ARCHIVED` | Treated like ended: "This event has ended.", no Register | The same |
| `DRAFT` (not published) | Nothing | The draft content with "Draft preview: this event is not published on Odavent yet. Visitors do not see it." Register links appear once published. Never page-cached |
| `GONE` (deleted, unpublished or not in your organization) | Nothing | A notice: "This Odavent event was not found or is no longer published. Choose another event in the block settings." |
| `UNAVAILABLE`, no copy (Odavent unreachable, never cached) | Nothing | A notice: "Odavent could not be reached and there is no saved copy of this event yet." |
| Unreachable, with a last good copy (stale) | The content, but no Register or ticket links, with "Registration details could not be refreshed..." | The same |

A block with no event chosen and no default event shows nothing to visitors and, to editors, "Choose an event
for this Odavent block...".

### Connection states

* **Site key rejected** (revoked, rotated, mistyped): Odavent answers `401`. The settings page shows "Your
  Odavent site key was rejected; check it below." for up to 12 hours. As with a plan that no longer includes
  the plugin (below), the plugin stops using the key for an hour at a time and reads published events from
  Odavent's public event pages by slug, so **published events keep showing, with their Register links**.
  Blocks that were never given an event slug keep showing their last good copy (as stale, without Register
  links). Draft previews, the event picker and live refresh stop until the key is fixed in Settings > Odavent
  Events (or `wp-config.php`); a successful *Test connection* re-enables the key at once.
* **Plan no longer includes the plugin** (Odavent answers `403`): the plugin stops using the key for
  an hour at a time and reads published events from Odavent's public event pages by slug instead. **Published
  events keep showing**, refreshed when the cache lifetime ends; draft previews, the event picker in the
  editor and live refresh stop until the plan includes the plugin again. Blocks that were never given an
  event slug can only keep showing their last good copy.
* **Odavent has a newer data format** than the plugin knows: the data is still used, and the settings page
  asks you to update the plugin.

## Troubleshooting

**The page shows old data.**
1. Wait for the cache lifetime, or press *Clear cache* in Settings > Odavent Events > Tools.
2. Purge your page cache or CDN. If the page is in a synced pattern, template part or widget, see
   [Page caches](#page-caches).
3. With plain permalinks, or a security plugin or firewall in front of `/wp-json/`, pings never arrive and
   pages only change at the end of the cache lifetime. Check the next two points.

**Pings are blocked.**
* Open `https://your-site/wp-json/` in a browser: if it is not a page of JSON, REST is unreachable (plain
  permalinks, a security plugin, or a firewall).
* On Odavent, look at the last ping error of your site and use "Send test ping". `http_401`/`http_403` usually
  means a firewall in front of the site or a rejected signature (wrong key, or a server clock more than
  5 minutes off); `http_404` means the plugin is inactive or REST is blocked; `http_301`/`http_302` means the
  address saved on Odavent redirects (wrong `www`, or `http` instead of `https`): save the final address.
* Allow `POST /wp-json/odavent/v1/refresh`, see [above](#security-plugins-firewalls-and-cdns-must-let-pings-through).

**The Register button is missing.**
Check the table above. The usual reasons: registration is closed or the event paused or cancelled on
Odavent; every ticket is sold out or not on sale (the block says "Sold out" or "This ticket is not on
sale"); Odavent could not be reached and the page is showing a stale copy (the notice says so); the event is
a draft and you are looking as a visitor; the shortcode or block points at another event.

**The block editor cannot list events.**
The event picker needs your site key to work, your plan to include the plugin, the REST API to be reachable
by logged-in users, and your account to have the preview capability. The message in the picker tells which
(key missing or rejected, plan, Odavent unreachable). Without the list you can still type the event's slug.

**Text is in the wrong language.**
Event content follows the page language: `fr_*` asks Odavent for French, `en_*` for English, any other
locale for your organization's default language. Multilingual plugins can decide with
[`odavent_events_locale`](hooks.md#odavent_events_locale). The plugin's own words (buttons, notices) follow
the site language, English and French are included.

## Multisite

* Settings, the site key, caches and page-cache purging are **per site**. Each site connects with its own
  key (or defines `ODAVENT_SITE_KEY` in `wp-config.php`, which applies to the whole network).
* The update check result is stored **network-wide**: it is fetched once for the whole network, not once per
  site.
* Network-activate the plugin if you want it available everywhere; each site still has to be configured.
* Deleting the plugin removes the data of every site and the network-wide update check.

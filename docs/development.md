# Development environment

A real WordPress in Docker, a stub of the Odavent API, WP-CLI, an end-to-end smoke test and browser tests.
The unit tests (`bin/test`) use doubles of WordPress; this environment proves the plugin on the real thing.

Needs Docker (with Compose v2), `curl`, `jq` and `openssl` on the host. Nothing else: PHP, Node and WP-CLI
run in containers.

## Quick start

```sh
bin/dev-up        # start, install WordPress, activate the plugin, create the test pages (~15 s warm, ~1 min cold)
bin/smoke.sh      # the end-to-end checks (prints PASS/FAIL per check, non-zero exit on any FAIL)
bin/e2e           # browser tests (Playwright in Docker); screenshots in tests/e2e/artifacts/
bin/dev-down      # remove the containers and volumes (only the odavent-wp-dev project's)
```

Open <http://localhost:8089> (log in as `admin` / `admin`). The test pages:

| Page | Content |
|---|---|
| `/all-blocks-1/` | the ten blocks for event 1 (published, the full fixture) |
| `/shortcodes-1/` | the ten shortcodes for event 1 (classic content) |
| `/cancelled-2/` | the ten blocks for event 2 (cancelled) |
| `/draft-3/` | the ten blocks for event 3 (draft: visitors see nothing, editors a preview) |
| `/hostile-5/` | the ten blocks for event 5 (the hostile fixture: XSS strings, bad URLs, a 50 KB description) |
| `/slug-only/` | the ten blocks with only `eventSlug` |
| `/event-page-6/`, `/event-page-shortcode-6/` | the event page block / shortcode for event 6 (a partner of type `both`, a cover and a logo that 404) |
| `/event-page-7/`, `/event-page-shortcode-7/` | the same for event 7, the cancelled twin of 6 |
| `/widget-host/` | no Odavent content; the smoke test places a widget in the dev widget area and watches this page |

Other helpers: `bin/wp ...` runs WP-CLI in the stack (`bin/wp option get odavent_events_settings`),
`bin/sign-ping <key> <body>` prints the `X-Odavent-Signature` header Odavent sends with a refresh ping.

## What runs where

`docker/docker-compose.yml`, project `odavent-wp-dev`:

| Service | What |
|---|---|
| `db` | MariaDB 10.11, published on `127.0.0.1:8091` (`DB_PORT` to change it) |
| `wordpress` | the official image (`WP_IMAGE`), published on `127.0.0.1:8089` only (admin/admin must never be reachable from the network). The repository is bind-mounted **read-only** as `wp-content/plugins/odavent-events`; `WP_DEBUG` + `WP_DEBUG_LOG` on, `WP_ENVIRONMENT_TYPE=local`, automatic updates off |
| `stub` | `php -S 127.0.0.1:8081 docker/stub/index.php`, sharing the WordPress container's network |
| `wpcli` | WP-CLI on the same PHP version, same network, user 33 (www-data) |

Because `stub` and `wpcli` share the network namespace of `wordpress`, inside the stack `127.0.0.1:8081` is the
stub and `localhost:8089` is the site (Apache listens on 8089 inside the container too, so WordPress' own
loopback requests work). The plugin accepts the `http://127.0.0.1:8081` address only because the environment
type is `local`.

`docker/mu-plugins/` (dev stack only, never shipped):

* `odavent-dev-probe.php` adds an `X-Odavent-Probe-Donotcachepage: 1` header when `DONOTCACHEPAGE` is defined
  before the theme renders, so the smoke test can see the early no-cache layer from outside.
* `odavent-dev-widget-area.php` registers a widget area, `dev-widget-area`, printed in the footer of every page
  while it holds a widget (the default block themes have no widget areas): `bin/wp widget add odavent_events_speakers dev-widget-area 1 --eventId=1`.
* `odavent-dev-page-cache.php` is a minimal full-page cache, off unless the option `odv_dev_page_cache` is `1`:
  it stores anonymous pages in `wp-content/odv-dev-cache/`, answers `X-Odv-Dev-Cache: HIT` or `MISS`, and empties
  itself on the plugin's purge actions (`odavent_events_purge_all`, `odavent_events_purge_post`), counting them in
  `purge-all.log` / `purge-post.log` there, the way a page-cache plugin would.

### The stub Odavent API

`docker/stub/index.php` serves the routes of the real `WordpressController` from `tests/fixtures/*.json`, with
the same envelope (`{success, message, data}`) and error shape (`{success: false, message, errors: {code}}`):

| Route | Answer |
|---|---|
| `GET /api/v1/wordpress/site/events` | Bearer `odv_wp_stubkey_for_dev_0123456789`, else 401 `invalid_key`. Events 1, 2, 3, 5, 6, 7 |
| `GET /api/v1/wordpress/site/events/{id}` | 1 published, 2 cancelled, 3 draft, 5 hostile (published), 6 published (event 1's content plus a partner of type `both`; its cover and that partner's logo 404), 7 the cancelled twin of 6; anything else 404 `not_found` |
| `GET /api/v1/wordpress/events/{slug}` | public, published events only, else 404 `not_found` |
| `GET /api/v1/wordpress/plugin-update` | version `9.9.9`, the pinned GitHub download URL |
| `GET /storage/missing/...` | 404 (event 6's broken images: `http://127.0.0.1:8081/storage/missing/cover-6.webp`, `.../partner-406.png`) |

`?locale=fr` appends ` (fr)` to the event, session, speaker and ticket names. Test controls (not part of the real
API) change behaviour at runtime, from inside the stack:

```sh
docker compose -f docker/docker-compose.yml exec wordpress curl -s -X POST http://127.0.0.1:8081/__control \
  -d '{"mode":"timeout"}'            # ok | 500 | timeout (sleeps 12 s) | garbage | plan_required
# also: {"rename":{"1":"New name"}}, {"status":{"1":"cancelled"}}, {"starts_in":{"1":3600}},
#       {"event":{"1":{"registration_open":false}}}, {"update":{"download_url":"..."}}, {"reset":true}
# GET /__state shows the state, GET /__log the requests the plugin made.
```

## WordPress versions

`WP_IMAGE` picks the WordPress image; WP-CLI follows its PHP version. `bin/dev-down` before switching (the
WordPress files live in a volume):

```sh
bin/dev-down && bin/dev-up                                                        # default: wordpress:7.1-php8.3-apache
bin/dev-down && WP_IMAGE=wordpress:6.1-php7.4-apache bin/dev-up                   # the plugin's minimum
WP_IMAGE=wordpress:6.1-php7.4-apache bin/smoke.sh                                 # use the same WP_IMAGE for every command
```

CI runs both (`smoke` and `e2e` jobs in `.github/workflows/ci.yml`, latest WordPress and 6.1/PHP 7.4,
non-blocking for now).

## What the smoke test checks

`bin/smoke.sh` (`ONLY="1 5" bin/smoke.sh` for some checks):

1. all-blocks page: header, speakers, agenda, sponsors, tickets; a Register link with `utm_source=wordpress`,
   `utm_medium=plugin`, `return=`; the "handled securely by Odavent" notice; JSON-LD that parses; HTML that
   parses without errors.
2. cancelled event: the notice, no Register link anywhere.
3. draft: nothing of it for visitors; content + "Draft preview" for the admin, with no-cache headers and
   `DONOTCACHEPAGE` defined at `template_redirect` (probe header).
4. Odavent failing (500, timeout, garbage): the page still answers 200 with the last good copy (stale, no
   Register); the circuit breaker makes the next request fast and silent; no PHP warnings.
5. live refresh: unsigned, wrong-key, stale and replayed pings are 401 with identical bodies and change
   nothing; a signed ping shows the new name on the next request.
6. the settings page never contains the key; `GET /wp-json/odavent/v1/events` is 401 for visitors, 200 for the
   admin with a REST nonce.
7. shortcodes render the same markup as blocks (normalised: whitespace, entities, wptexturize quotes,
   page-specific URLs, countdown digits).
8. hostile event through the real `wp_kses`/`esc_url`: no script but the JSON-LD, no `on*` attribute, no
   `javascript:`/`data:` URL, the page complete.
9. settings form: an invalid URL gives exactly one error notice; the option is never autoloaded; the page
   renders while Odavent fails (numeric default-event field).
10. no key and the event unpublished: after the cache lifetime, the stale copy shows no Register link.
11. update checker: 9.9.9 offered with the pinned GitHub package; a non-GitHub or other-repository package never.
12. uninstall (on the packaged copy, the way the Plugins screen does it): no `odavent_events_*` option or
    transient left.
13. the release zip (`bin/build-zip 0.1.0`) installed into a fresh WordPress at `/pkg`: checks 1 and 5 again,
    and in `fr_FR` the Register button reads "S’inscrire".
14. event page block (event 6): the ten default sections in order, the five headings, unique anchors, the
    `both` partners headed "Sponsors and exhibitors", every image marked `data-odv-img` and the fallback script
    loaded; the cancelled twin (event 7): exactly one lifecycle notice, the cancelled text once, no Register link;
    `[odavent_event_page]` renders the same markup as the block for both.
15. a Register button widget (`wp widget add`, dev widget area) on a page without Odavent content, behind the
    dev page cache: it renders for the live event and is cached (HIT); cancelled on Odavent, the cached page still
    offers Register until a signed ping, which fires `odavent_events_purge_all` (and the per-post purge); the next
    request is rendered again with the cancelled notice and no Register link; an editor's view of that page is
    not cached (`DONOTCACHEPAGE`).
16. Appearance through `options.php`: hostile values are stored as valid ones only (colour kept, radius clamped,
    the rest default), the front end has `--odv-accent` only for a valid colour (with automatic contrast), the
    outline style adds its rule, and a low-contrast pair is saved with a warning giving the ratio.

`wp-content/debug.log` is read by check 0 (after install and activation), 4 (during the failures), 8 (hostile
event), 11 (update checks), 14, 15, 16, 13 (the packaged site's log and the dev site's meanwhile) and a final check over the
whole run: a PHP warning, notice, deprecation or fatal there fails. Check 0 (and 13 for the packaged site) first
writes a canary line through `error_log()` and requires it in the log, and a missing log fails these checks, so
they cannot pass on a site whose logging is broken. A full run must print `EXPECTED_PASSES` PASS lines (set at
the top of `bin/smoke.sh`): a skipped section fails the run.

Note: `wp plugin delete` (WP-CLI) does not run `uninstall.php` by design; check 12 uses core's
`delete_plugins()`, which is what the Plugins screen calls.

## Browser tests

`bin/e2e` runs `mcr.microsoft.com/playwright:v{version}-noble` (the version in `tests/e2e/package.json`) in the
WordPress container's network and installs `@playwright/test` inside the container. Specs in `tests/e2e/specs/`:

* editor: the ten blocks register with `apiVersion` 3; the Speakers block is found by inserter search; the
  Event select lists the stub events with translated statuses; choosing event 1 previews its speakers
  (ServerSideRender); "Show biography" changes the preview; a slug-only block selects its event, not
  "Default event"; no console errors.
* the fixtures' images (`https://odavent.com/storage/...`) and Gravatar avatars are answered with a local SVG
  placeholder, so the browser never calls production and the tests run offline; any other external request
  is listed in the test's annotations. Any console error or warning fails a test, except a few exact core
  messages listed with their WordPress version in `tests/e2e/specs/helpers.js`. `bin/e2e` stores the admin's
  editor preferences first (no welcome guide, no "Choose a pattern" modal for new pages).
* editor (event page): the Event page block from the inserter previews event 6; turning the Speakers section
  off in the inspector drops it (and its heading) from the server-side preview, back on stores `sections` as
  empty; the "Full event page" pattern inserts the ten blocks in page order.
* front end: the agenda track filter (`aria-pressed`, `hidden`, rooms follow their parent); the countdown
  ticks without `NaN`; a paused event's countdown hides itself once the start passes; on `/event-page-6/` the
  404 cover is removed and the 404 logo of the `both` partner becomes its name (the expected "Failed to load
  resource" messages for exactly those two URLs are allowed, see `expected404` in `helpers.js`); full-page
  screenshots at 390 and 1280 px (light mode) in `tests/e2e/artifacts/` for a human to look at.
* settings: the Appearance preview follows the accent typed in the colour picker (the preview button's computed
  colours), and shows the ratio and the warning for white text on yellow (1.07:1).

## Pointing the plugin at a real local Odavent

Run Odavent locally (for example on port 8000) and create a WordPress site key in the organization's
settings. The plugin accepts plain `http` only for `localhost` / `127.0.0.1` on a `local` site, so
`http://host.docker.internal:8000` would be refused: forward a local port inside the stack to the host instead.

```sh
docker compose -f docker/docker-compose.yml exec -d -u root wordpress \
  sh -c 'apt-get update -qq && apt-get install -y -qq socat && socat TCP-LISTEN:8082,fork,reuseaddr TCP:host.docker.internal:8000'
bin/wp option patch update odavent_events_settings base_url http://127.0.0.1:8082
bin/wp option patch update odavent_events_settings site_key odv_wp_...
```

`host.docker.internal` resolves on Docker Desktop; on Linux add
`extra_hosts: ["host.docker.internal:host-gateway"]` to the `wordpress` service. For refresh pings, register the
site on Odavent as `http://localhost:8089` only if your Odavent can reach that address (it usually cannot from a
container: use the stub and `bin/sign-ping` to test pings).

## Troubleshooting

* **Port in use**: WordPress always uses `127.0.0.1:8089` (the site URL, Apache's port inside the container and
  the tests depend on it): free that port. The database port can move: `DB_PORT=8096 bin/dev-up`.
* **"the dev stack is not running"** from `bin/smoke.sh`/`bin/e2e`: run `bin/dev-up`, with the same `WP_IMAGE`.
* **WordPress 7.x code on PHP 7.4, or a version you did not ask for**: the volume still holds another version;
  `bin/dev-down` then `bin/dev-up`.
* **Pretty permalinks**: `bin/dev-up` sets `/%postname%/` and writes `.htaccess` (`docker/wp-cli.yml` tells WP-CLI
  that mod_rewrite is on). With plain permalinks `/wp-json/` and the refresh route are unreachable.
* **Odd timeouts or "stale" signatures accepted/refused**: the Docker VM clock can drift after the host sleeps.
  Compare `date -u +%s` with `bin/wp eval 'echo time();'`; restart Docker Desktop if they differ.
* **Logs**: `bin/wp eval 'echo file_get_contents( WP_CONTENT_DIR . "/debug.log" );'`,
  `docker compose -f docker/docker-compose.yml logs wordpress stub`.

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

`docker/mu-plugins/odavent-dev-probe.php` (dev stack only, never shipped) adds an
`X-Odavent-Probe-Donotcachepage: 1` header when `DONOTCACHEPAGE` is defined before the theme renders, so the
smoke test can see the early no-cache layer from outside.

### The stub Odavent API

`docker/stub/index.php` serves the routes of the real `WordpressController` from `tests/fixtures/*.json`, with
the same envelope (`{success, message, data}`) and error shape (`{success: false, message, errors: {code}}`):

| Route | Answer |
|---|---|
| `GET /api/v1/wordpress/site/events` | Bearer `odv_wp_stubkey_for_dev_0123456789`, else 401 `invalid_key`. Events 1, 2, 3, 5 |
| `GET /api/v1/wordpress/site/events/{id}` | 1 published, 2 cancelled, 3 draft, 5 hostile (published), anything else 404 `not_found` |
| `GET /api/v1/wordpress/events/{slug}` | public, published events only, else 404 `not_found` |
| `GET /api/v1/wordpress/plugin-update` | version `9.9.9`, the pinned GitHub download URL |

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

Every check also reads `wp-content/debug.log`: a PHP warning, notice or fatal fails the run.

Note: `wp plugin delete` (WP-CLI) does not run `uninstall.php` by design; check 12 uses core's
`delete_plugins()`, which is what the Plugins screen calls.

## Browser tests

`bin/e2e` runs `mcr.microsoft.com/playwright:v{version}-noble` (the version in `tests/e2e/package.json`) in the
WordPress container's network and installs `@playwright/test` inside the container. Specs in `tests/e2e/specs/`:

* editor: the ten blocks register with `apiVersion` 3; the Speakers block is found by inserter search; the
  Event select lists the stub events with translated statuses; choosing event 1 previews its speakers
  (ServerSideRender); "Show biography" changes the preview; a slug-only block selects its event, not
  "Default event"; no console errors.
* front end: the agenda track filter (`aria-pressed`, `hidden`, rooms follow their parent); the countdown
  ticks without `NaN`; a paused event's countdown hides itself once the start passes; full-page screenshots at
  390 and 1280 px (light mode) in `tests/e2e/artifacts/` for a human to look at.

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

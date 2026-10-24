# Odavent Events for WordPress

A WordPress plugin that shows the live **speakers, agenda, sponsors, tickets, countdown, venue and
add-to-calendar links** of your [Odavent](https://odavent.com) events on **your own WordPress site**, with
your own theme or page builder.

Odavent keeps running the event. **Registration and payment always happen on Odavent's own page**; the
plugin only links to it. No attendee data is ever sent to, or stored on, your WordPress site.

## Requirements

* PHP 7.4 or later and WordPress 6.1 or later
* An Odavent organization on a plan that includes the WordPress plugin (Pro or Enterprise)
* A **site key** (it starts with `odv_wp_`), created under Settings > WordPress in your Odavent organization

## Installation

Install from the **release zip**, not from GitHub's "Download ZIP" button:

1. Open the [latest release](https://github.com/mdjaman/odavent-wordpress/releases/latest) and download
   `odavent-events-X.Y.Z.zip`.
2. In WordPress: Plugins > Add New > Upload Plugin > choose the zip > Install > Activate.

GitHub's "Download ZIP" and "Source code" archives are **not** the plugin: the folder is called
`odavent-wordpress-main` instead of `odavent-events` (which breaks updates and translations) and they hold
development files, not a ready-to-run plugin.

Later versions appear in Plugins like any other update.

## Connecting your site key

1. Go to **Settings > Odavent Events**.
2. Check the Odavent address (it is `https://odavent.com` unless you were given another) and paste your
   site key.
3. Save, then press **Test connection**. You should see "Connected" and the number of events found.
4. Optionally choose a **default event**, used by every block and shortcode that does not name one.

Instead of saving the key in the database you can define it in `wp-config.php`, which also keeps it out of
database backups:

```php
define( 'ODAVENT_SITE_KEY', 'odv_wp_...' );
```

## Blocks and shortcodes

Add blocks from the **Odavent Events** category in the block editor. Pick an event in the block's settings
(or leave it on the default event). Every block has a shortcode that renders the same thing, for the classic
editor, widgets and page builders. Full attribute lists are in [docs/shortcodes.md](docs/shortcodes.md).

| Block | Shortcode | Shows |
|---|---|---|
| Event header | `[odavent_event_header]` | Title, dates, cover, logo, description, venue |
| Register button | `[odavent_register_button]` | A button to the registration page on Odavent |
| Tickets | `[odavent_tickets]` | Ticket types with price, availability and Register links |
| Find my registration | `[odavent_find_registration]` | A link for attendees to get their ticket again |
| Speakers | `[odavent_speakers]` | Speakers with photo, role, company and optional bio |
| Agenda | `[odavent_agenda]` | Sessions by day, with a track filter |
| Sponsors | `[odavent_sponsors]` | Sponsors, exhibitors and partners, grouped by type |
| Countdown | `[odavent_countdown]` | A live countdown to the start |
| Venue | `[odavent_venue]` | Venue, address and a map link |
| Add to calendar | `[odavent_add_to_calendar]` | Google Calendar, Outlook and .ics links |

Example: `[odavent_speakers event="annual-summit" columns="2" show_bio="yes"]`

## Live updates

When you change something on Odavent, it pings your site at `/wp-json/odavent/v1/refresh` (signed with
your site key), and the plugin drops its cached copy and purges the page caches of WP Rocket, W3 Total Cache,
WP Super Cache and LiteSpeed Cache. Without a ping, pages refresh when the cache lifetime ends (15
minutes by default).

* This needs **pretty permalinks** (any setting except "Plain").
* Security plugins and firewalls must let `POST /wp-json/odavent/v1/refresh` through.
* Blocks inside synced patterns, block-theme templates or widgets are not detected for cache purging: see
  [docs/operations.md](docs/operations.md#page-caches).

## Documentation

* [docs/shortcodes.md](docs/shortcodes.md): every shortcode and attribute
* [docs/theming.md](docs/theming.md): CSS variables, class names, template overrides
* [docs/hooks.md](docs/hooks.md): every filter and action
* [docs/security.md](docs/security.md): how the key, pings, previews and updates are protected
* [docs/operations.md](docs/operations.md): live refresh, caching, what visitors see, troubleshooting
* [CHANGELOG.md](CHANGELOG.md)

## Security

The plugin only reads from Odavent, stores no attendee data, escapes everything it prints, verifies every
ping with an HMAC signature and only installs updates from this repository's GitHub releases. Details and
limits (the update package is not signed yet) are in [docs/security.md](docs/security.md). Report
vulnerabilities privately through GitHub's *Security > Report a vulnerability* on this repository.

## Development

Tests run inside Docker, so no local PHP or Composer is needed (Docker is required). Tests run on PHP 7.4 by
default (the plugin's floor), so PHP 8-only syntax fails locally; use `PHP_VERSION=8.3 bin/test` to run on
another version:

```sh
bin/test              # the whole PHPUnit suite
bin/test --filter PluginTest
bin/lint-js           # syntax check of assets/js (node --check)
bin/test-js           # tests of the browser scripts (plain node)
bin/make-pot.sh       # regenerate languages/odavent-events.pot (wp i18n make-pot in Docker)
php bin/check-translations.php   # every .po complete and consistent with the .pot
bin/build-zip 0.1.0   # build dist/odavent-events-0.1.0.zip
```

The plugin targets PHP 7.4+ and WordPress 6.1+ and has no runtime Composer dependency (`composer install`
only brings the test tools). CI lints every PHP file, runs PHPUnit on PHP 7.4 and 8.3, runs PHPCS
(`PHPCompatibilityWP` for syntax and most newer functions, plus a deny-list of PHP 8-only functions such as
`str_contains()` because PHPCompatibility skips unqualified calls inside a namespace; see `phpcs.xml.dist`),
checks the translations and syntax-checks and tests the scripts. PHPCS cannot see every PHP 8 feature, so the
PHP 7.4 test run is the real gate.

### Translations

English is the source language; `languages/odavent-events-fr_FR.po` is the French translation. After
adding or changing a string, run `bin/make-pot.sh`, add the translation to the `.po`, and run
`php bin/check-translations.php` (it fails on an empty translation, a string missing from the `.pot`, or
placeholders such as `%s` / `%1$s` that differ). The `.mo` files and the JSON files used by the block editor
are compiled by `bin/build-zip.php` (PHP only, no `msgfmt`) and are not committed. Use a literal
`'odavent-events'` as the text domain in every call, including in `assets/js/editor.js`: the extractor
cannot read a variable.

### Releasing

A release is a Git tag. The version must be the same everywhere, and the tag must equal it.

1. Bump the version in `odavent-events.php` (the `Version:` header **and** `ODAVENT_EVENTS_VERSION`) and
   in `readme.txt` (`Stable tag`, and `Tested up to` / `Requires` if they changed), and add the version to
   the changelog in both `CHANGELOG.md` (a `## [X.Y.Z] - YYYY-MM-DD` section) and `readme.txt`.
2. Run `bin/make-pot.sh` before tagging: it regenerates the `.pot` (strings, source references and the
   `Project-Id-Version` header, which it reads from the plugin header's `Version:`), then finish the French
   translation (and its `Project-Id-Version`, if you keep it in step).
3. Run the local gates: `bin/test`, `PHP_VERSION=8.3 bin/test`, `bin/lint-js`, `bin/test-js`,
   `php bin/check-translations.php`, and PHPCS.
4. Merge to `main`, then tag and push: `git tag vX.Y.Z && git push origin vX.Y.Z`.

The `Release` workflow (`.github/workflows/release.yml`, tags `v*` only) then checks that the tagged commit is
on `main` and that the tag equals the plugin header version, runs the tests, builds `odavent-events-X.Y.Z.zip`
(single top-level folder `odavent-events/`, runtime files only) and creates the GitHub release with that zip as
its only asset. It has two jobs: `build` (read-only token, not kept in the checkout) runs Composer, the tests
and the packaging and uploads the zip and the notes as a workflow artifact; `publish` is the only job that
can write to the repository, and it runs no project or dependency code (no checkout, no Composer): it
downloads that artifact and calls `gh release create --verify-tag`. Every third-party action in both
workflows is pinned to a full commit SHA with its version in a trailing comment; to update one, look up the
commit of the new tag (`git ls-remote https://github.com/actions/checkout refs/tags/v4.4.0`, and the `^{}`
line for an annotated tag) and replace both.

The release **body** is the changelog section followed by three plain lines that Odavent reads when it serves
update information to your sites (no bold, bullets or backticks on them):

```
Requires at least: 6.1
Requires PHP: 7.4
Tested up to: 7.1
```

They come from `readme.txt`. The update checker also requires the asset URL to be exactly
`https://github.com/mdjaman/odavent-wordpress/releases/download/vX.Y.Z/odavent-events-X.Y.Z.zip`, which is why
the tag must be `vX.Y.Z` with the same version as the plugin header. Odavent caches the latest release for up
to an hour. A tag with a pre-release suffix (`v1.0.0-beta.1`) creates a GitHub pre-release, which Odavent
ignores.

To check the packaging without publishing: `bin/build-zip X.Y.Z` and
`php bin/release-notes.php X.Y.Z`.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).

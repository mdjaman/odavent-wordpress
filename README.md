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

## For organizers

You do not need to write any code. Five steps take you from an empty page to a live event page.

1. **Install and connect.** Install the plugin from the release zip (see Installation above), then open
   **Settings > Odavent Events**, paste your site key and press **Test connection**. You should see
   "Connected".
2. **Add the event page.** Edit the page that should show your event (or create a new one). Click the **+**
   button, search for **Event page** and add it, then pick your event in the panel on the right. This one
   block shows everything: the title and dates, a countdown, the Register button, tickets, speakers, the
   agenda, sponsors, the venue and the add-to-calendar links. Prefer to place and style the parts yourself?
   Insert the **Full event page** pattern instead (open the **Patterns** tab and look under **Odavent**): it
   puts every part on the page as its own block, and you can move, remove or restyle each one.
3. **Choose what to show.** Select the Event page block and, in the panel on the right, switch the
   sections you want on or off: header, countdown, register button, tickets, find my registration,
   speakers, agenda, sponsors, venue and calendar. You can also turn the section headings off, choose the
   number of columns for speakers and sponsors, and show speaker biographies or the places left. A section
   with nothing to show (an event without sponsors, say) simply does not appear.
4. **Match your brand.** Go to **Settings > Odavent Events** and scroll to **Appearance**. Pick your accent
   colour (the button and highlight colour), and optionally the text and border colours, the roundness of
   the corners, the spacing and a filled or outlined button. A live preview shows the result before you save,
   and the plugin warns you when button text would be hard to read (the text on your accent colour, or the
   accent itself as the text of outlined buttons). Leave a field empty to keep the default.
5. **Use the widgets.** If your theme has widget areas (a sidebar or a footer), go to **Appearance >
   Widgets**: every Odavent block is also there as an **Odavent: ...** widget, for example a countdown or a
   list of speakers in your sidebar. On a block theme the widgets screen does not exist; use the blocks
   (in the Site Editor too) instead.

**When something looks off**

* *Old information is showing.* Odavent normally updates your page within moments. If it does not, press
  **Clear cache** in the plugin tools on the Settings page, and clear your caching plugin.
* *Nothing appears for my event.* Check that the event is published on Odavent (an editor sees a message
  on the page; visitors see nothing for an unpublished event), and that the connection test says
  "Connected".
* *The colours do not change.* The Appearance settings need **Default styles** switched on in the same
  settings page. If your theme or a page builder sets its own colours, ask your webmaster to read
  [docs/theming.md](docs/theming.md).
* *A photo or logo is missing.* A picture that cannot load is replaced instead of showing a broken-image
  icon (a speaker gets their initials, a sponsor their name); check the image on Odavent.
* *Registration is on Odavent.* The Register buttons always lead to Odavent's own secure page; attendees
  never register or pay on your website.

## For webmasters

Everything below this part, and the files in [docs](docs), are for you:

* [docs/shortcodes.md](docs/shortcodes.md): every shortcode and attribute, including the all-in-one
  `[odavent_event_page]`
* [docs/widgets.md](docs/widgets.md): the classic widgets and the Legacy Widget block, and how they interact
  with page caches
* [docs/theming.md](docs/theming.md): the Appearance settings and how they combine with your CSS, CSS
  variables, class names, template overrides
* [docs/hooks.md](docs/hooks.md): every filter and action

## Blocks and shortcodes

Add blocks from the **Odavent Events** category in the block editor. Pick an event in the block's settings
(or leave it on the default event). To preview a **draft** event, pick it in the list (that stores its ID):
an event typed in by its slug (the block's "Event slug" field, or `event="slug"` in a shortcode) is found only
once it is published. Every block has a shortcode that renders the same thing, for the classic
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
| Event page | `[odavent_event_page]` | All of the above in one block, with the sections you choose, in a fixed order |

Every block is also a classic widget ([docs/widgets.md](docs/widgets.md)), and the **Full event page** block
pattern inserts the individual blocks in page order.

Example: `[odavent_speakers event="annual-summit" columns="2" show_bio="yes"]`

## Live updates

When you change something on Odavent, it pings your site at `/wp-json/odavent/v1/refresh` (signed with
your site key), and the plugin drops its cached copy and purges the page caches of WP Rocket, W3 Total Cache,
WP Super Cache and LiteSpeed Cache. Without a ping, pages refresh when the cache lifetime ends (15
minutes by default).

* This needs **pretty permalinks** (any setting except "Plain").
* Security plugins and firewalls must let `POST /wp-json/odavent/v1/refresh` through.
* Blocks inside synced patterns or block-theme templates are not detected for cache purging: see
  [docs/operations.md](docs/operations.md#page-caches). Odavent widgets, and Odavent blocks or shortcodes in
  a widget area, are detected and purge the whole site's page cache: see [docs/widgets.md](docs/widgets.md#page-caches).

## Documentation

* [docs/shortcodes.md](docs/shortcodes.md): every shortcode and attribute
* [docs/widgets.md](docs/widgets.md): the classic widgets and their cache behaviour
* [docs/theming.md](docs/theming.md): Appearance settings, CSS variables, class names, template overrides
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
the tag must be `vX.Y.Z` with the same version as the plugin header.

To check the packaging without publishing: `bin/build-zip X.Y.Z` and
`php bin/release-notes.php X.Y.Z`.

#### How Odavent serves the update (read before publishing)

Sites do not read GitHub: they ask `https://odavent.com/api/v1/wordpress/plugin-update`, which reads GitHub's
`releases/latest` for this repository. That has consequences:

* **Pre-releases are invisible to it.** GitHub's "latest" release is never a pre-release, so a tag with a
  suffix (`v1.0.0-beta.1`, a GitHub pre-release) is never offered to any site. A throwaway `v0.1.0-rc.1`
  proves the workflow, the asset name and the zip, but **not** the update path.
* **It caches.** The latest release is cached for an hour, a failed GitHub lookup for five minutes, and the
  last good release is kept **forever** (served whenever GitHub fails). Sites cache what they got for up to
  12 hours (an hour after a failure); *Check again* in Dashboard > Updates refreshes a site at once.
* **It takes the first `.zip` asset** of the release. Attach nothing else that ends in `.zip`.
* **Never delete a published release** without also clearing Odavent's cache, or sites keep being offered
  the deleted file (a 404 at update time) for as long as the last good copy lives. On the Odavent server:
  `php artisan cache:forget wordpress_plugin_release`, `php artisan cache:forget wordpress_plugin_release_failed`
  and `php artisan cache:forget wordpress_plugin_release_last_good`. To withdraw a bad release, publish a fixed
  higher version instead whenever you can.

#### First release checklist

1. Fix and verify: every gate above green, plus `bin/smoke.sh` and `bin/e2e` on the newest WordPress and on
   `wordpress:6.1-php7.4-apache`.
2. `bin/make-pot.sh`, finish `languages/odavent-events-fr_FR.po`, `php bin/check-translations.php`.
3. The versions agree: the `Version:` header, `ODAVENT_EVENTS_VERSION`, `Stable tag` in `readme.txt`, and the
   `CHANGELOG.md` section with today's date.
4. Merge to `main` (CI green), then `git tag vX.Y.Z && git push origin vX.Y.Z`.
5. Watch the `Release` workflow until both jobs are green, and check the release has exactly one asset,
   `odavent-events-X.Y.Z.zip`, and the three `Requires`/`Tested` lines at the end of its body.
6. `curl -s https://odavent.com/api/v1/wordpress/plugin-update` (allow up to an hour for the cache) and check
   that `version` is `X.Y.Z` and `download_url` is the asset above.
7. Do a real update on a scratch site: install the plugin there with its `Version:` header lowered to `0.0.9`
   (or publish a no-op `0.1.1` and update a site running `0.1.0`), press *Check again* in Dashboard >
   Updates, update, and check the plugin still works and reports the new version.

#### Who can ship code to every site

Anyone who can push a tag that reaches the `Release` workflow, or publish a release in this repository, ships
code to every site that updates the plugin, automatically on sites with auto-updates on. Protect the
repository accordingly: require 2FA for everyone with write access, protect `v*` tags (tag rulesets) and
`main`, and restrict who can create releases to the maintainers who publish them.

## License

GPL-2.0-or-later, see [LICENSE](LICENSE).

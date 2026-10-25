# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The section of a version is used as the body of its
GitHub release (see "Releasing" in the README).

## [Unreleased]

### Added

- Event page: one block (`odavent-events/event-page`), shortcode (`[odavent_event_page]`) and widget that show the whole event page, with the sections you choose (as toggles in the editor) in a fixed order, optional section headings, columns for speakers and sponsors, speaker biographies and remaining places. Section anchors and one lifecycle notice for the whole page.
- "Full event page" block pattern (category Odavent) that inserts every block in page order.
- Classic widgets for every block, usable in widget areas and through the Legacy Widget block. While an Odavent widget (or an Odavent block or shortcode in a block, Text or Custom HTML widget) is active, an event refresh purges the whole-site page cache; the `odavent_events_purge_all_on_widget` filter and the `odavent_events_purge_all` action control it.
- Appearance settings (Settings > Odavent Events > Appearance): accent, text-on-accent (automatic or chosen), text and border colours, corner radius, spacing and button style, with a live preview and a contrast warning. The `odavent_events_appearance_css` filter adjusts the generated CSS.
- Broken-image fallback: a speaker photo that cannot load shows initials, a sponsor logo its name, and an unloadable header logo or cover is removed.
- Documentation: an organizer quick start in the README, `docs/widgets.md`, and the Appearance, event page and image notes in `docs/theming.md`.
- English and French translations of all of the above.

### Fixed

- The sponsors block headed partners that are both sponsor and exhibitor with the raw word "Both"; the heading now reads "Sponsors and exhibitors" (translated).
- On a combined event page the ended, paused or cancelled notice and the stale-data notice appear once, instead of being repeated by the header, Register and Tickets parts.
- Deleting the plugin also removes the widgets' settings (`widget_odavent_events_*` options, which WordPress creates for every registered widget even when none is placed).

## [0.1.0] - 2026-10-23

### Added

- Ten blocks, each with a matching shortcode: event header, register button, tickets, find my registration, speakers, agenda, sponsors, countdown, venue and add to calendar.
- Settings page (Settings > Odavent Events): Odavent address, site key (or the `ODAVENT_SITE_KEY` constant), default event, cache lifetime, connection test and cache clearing.
- Live refresh: Odavent notifies the site at `/wp-json/odavent/v1/refresh` (HMAC-signed), the cached event is dropped and the page caches of WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache are purged.
- Last-good copies and a circuit breaker, so an Odavent outage never breaks a page and a stale copy never shows a Register button.
- Draft previews and an event picker in the block editor.
- Theming: CSS variables, an optional default stylesheet and template overrides from the theme.
- Updates through the WordPress update screen, from the project's GitHub releases only.
- English and French translations (including the block editor).

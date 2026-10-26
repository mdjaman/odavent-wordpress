# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The section of a version is used as the body of its
GitHub release (see "Releasing" in the README).

## [Unreleased]

## [0.2.0] - 2026-10-26

### Added

- Sponsors can be grouped by **tier** (Gold, Silver... as typed on each partner in Odavent): `group_by="tier"` (block setting "Group by"). Tiers appear in the organizer's partner order, untiered partners come last, and `tier="Gold"` shows a single tier so each level can have its own columns. `group_by="none"` gives a flat list; `group_by_type="no"` still works.
- The agenda can show a single session (`session_id`), a single track (`track`) or a single day (`day="YYYY-MM-DD"`), alone or combined.

### Fixed

- A page built with Elementor's Odavent widgets is now found by the page-cache purge and the editor-preview protection (Elementor keeps those widgets in post meta, not in the post content).

## [0.1.0] - 2026-10-25

### Added

- Eleven blocks, each with a matching shortcode and a classic widget: ten for the parts of an event (event header, register button, tickets, find my registration, speakers, agenda, sponsors, countdown, venue, add to calendar) and an **event page** that shows the whole event page from one piece.
- Event page (`odavent-events/event-page`, `[odavent_event_page]`): the sections you choose (toggles in the editor) in a fixed order, optional section headings, columns for speakers and sponsors, speaker biographies and remaining places, section anchors, and one lifecycle notice for the whole page. A "Full event page" block pattern (category Odavent) inserts every block in page order.
- Classic widgets for every block, usable in widget areas and through the Legacy Widget block. While an Odavent widget (or an Odavent block or shortcode in a block, Text or Custom HTML widget) is active, an event refresh purges the whole-site page cache; the `odavent_events_purge_all_on_widget` filter and the `odavent_events_purge_all` action control it.
- Appearance settings (Settings > Odavent Events > Appearance): accent, text-on-accent (automatic or chosen), text and border colours, corner radius, spacing and button style, with a live preview and a contrast warning. The `odavent_events_appearance_css` filter adjusts the generated CSS.
- Settings page (Settings > Odavent Events): Odavent address, site key (or the `ODAVENT_SITE_KEY` constant), default event, cache lifetime, default styles switch, connection test and cache clearing.
- Live refresh: Odavent notifies the site at `/wp-json/odavent/v1/refresh` (HMAC-signed), the cached event is dropped and the page caches of WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache are purged.
- Last-good copies and a circuit breaker, so an Odavent outage never breaks a page and a stale copy never shows a Register button.
- Draft previews and an event picker in the block editor.
- Broken-image fallback: a speaker photo that cannot load shows initials, a sponsor logo its name, and an unloadable header logo or cover is removed.
- Theming: CSS variables, an optional default stylesheet and template overrides from the theme.
- Documentation for organizers (README quick start) and webmasters (`docs/`).
- Updates through the WordPress update screen, from the project's GitHub releases only.
- English and French translations (including the block editor).

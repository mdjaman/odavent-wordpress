# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/). The section of a version is used as the body of its
GitHub release (see "Releasing" in the README).

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

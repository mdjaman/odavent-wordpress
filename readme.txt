=== Odavent Events ===
Contributors: mdjaman
Tags: events, conference, speakers, agenda, tickets
Requires at least: 6.1
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show live speakers, agenda, sponsors and tickets from your Odavent events on your own WordPress site.

== Description ==

Odavent Events lets you build the website of your event in your own WordPress, with your own theme or page builder, while Odavent keeps running the event itself.

Add blocks or shortcodes to any page and they show the current data of your Odavent event: speakers, agenda, sponsors, tickets, countdown, venue, add-to-calendar links and a registration button. When you change something on Odavent, the page is refreshed within moments.

**Registration and payment always happen on Odavent's own secure page.** The plugin only links to it. No attendee data is ever sent to, or stored on, your WordPress site.

Start with the **Event page** block: one block that shows the whole page (header, countdown, Register button, tickets, speakers, agenda, sponsors, venue and calendar links), with toggles for the sections you want. Or insert the "Full event page" pattern to get every part as its own block. Colours, corner radius, spacing and button style are set without CSS under Settings > Odavent Events > Appearance, with a live preview.

Blocks (each one also has a shortcode and a classic widget with the same output):

* Event page (all of the sections below in one block)
* Event header (title, dates, cover, logo, description, venue)
* Register button
* Tickets
* Find my registration
* Speakers
* Agenda (by day, with a track filter)
* Sponsors (grouped by type)
* Countdown
* Venue
* Add to calendar

Good to know:

* Needs an Odavent plan that includes the WordPress plugin (Pro or Enterprise) and a site key from your Odavent organization settings.
* Pages stay fast: event data is cached, and the last good copy keeps showing if Odavent is briefly unreachable.
* Drafts can be previewed by editors in the block editor before the event is published.
* Every block is also available as a widget for themes with widget areas.
* A picture that cannot load is replaced by initials or a name instead of a broken-image icon.
* Available in English and French.

This plugin connects to the Odavent service (https://odavent.com) to read your event data. It sends your site key and the event you asked for, and nothing about your visitors.

== Installation ==

1. Download `odavent-events-X.Y.Z.zip` from the latest release at https://github.com/mdjaman/odavent-wordpress/releases. Use that file, not GitHub's "Source code" or "Download ZIP" button: those archives have a different folder name and contain development files.
2. In WordPress, go to Plugins > Add New > Upload Plugin, choose the zip and activate the plugin.
3. Go to Settings > Odavent Events, enter your Odavent address and your site key (it starts with `odv_wp_`), save, then press "Test connection".
4. Edit a page and add an Odavent block, or use a shortcode such as `[odavent_speakers event="your-event-slug"]`.

Instead of typing the key in the settings you can define it in wp-config.php: `define( 'ODAVENT_SITE_KEY', 'odv_wp_...' );`

Updates appear in Plugins like any other update.

== Frequently Asked Questions ==

= Why does my page still show old data? =

Odavent tells your site to refresh as soon as you change something, but that needs the WordPress REST API to be reachable by Odavent (see the next two questions). Otherwise a page refreshes when its cache lifetime ends (Settings > Odavent Events > Cache lifetime). If you use a page cache or a CDN, press "Clear cache" in the plugin tools and purge your caching plugin. The plugin purges WP Rocket, W3 Total Cache, WP Super Cache and LiteSpeed Cache pages by itself.

= Do I need pretty permalinks? =

For live updates, yes. Odavent notifies your site at /wp-json/odavent/v1/refresh, which does not work with the "Plain" permalink setting. With plain permalinks everything still works, but pages refresh only when the cache lifetime ends. Choose any other setting in Settings > Permalinks.

= Does it work with page builders and caching plugins? =

Yes. Every block has a shortcode, so Elementor, Divi, Beaver Builder, WPBakery, the classic editor and widgets can all use it. Page caches are purged automatically when an event changes. If you use a security plugin or firewall, allow POST requests to /wp-json/odavent/v1/refresh.

= Can I change how it looks? =

Yes. Choose your colours, corner radius, spacing and button style under Settings > Odavent Events > Appearance, no code needed. For more, the default stylesheet uses CSS variables you can override from your theme, can be turned off in the settings, and every template can be overridden from your theme. See docs/theming.md in the repository.

= What happens if Odavent is down? =

Visitors keep seeing the last good copy of the event. A stale copy never shows a Register button, so nobody is sent to register for something that may have changed.

= Can I put an Odavent block in a sidebar or footer? =

Yes. Every block is also a widget ("Odavent: Speakers", "Odavent: Countdown"...), available under Appearance > Widgets on themes with widget areas, or through the Legacy Widget block. Block themes have no widget screen: use the blocks in the Site Editor instead. While an Odavent widget is active, a change on Odavent purges your whole page cache, because a widget can appear on every page.

= Is any attendee data stored on my site? =

No. Registration, payment and the attendee list stay on Odavent.

== Changelog ==

= Unreleased =
* New: the Event page block, shortcode and widget, and the "Full event page" block pattern.
* New: classic widgets for every block.
* New: Appearance settings (colours, corner radius, spacing, button style) with a live preview.
* New: a picture that cannot load no longer shows a broken-image icon.
* Fixed: partners that are both sponsor and exhibitor were headed "Both"; the heading is now "Sponsors and exhibitors". Notices are no longer repeated on combined pages.

= 0.1.0 =
* First release: ten blocks and shortcodes (event header, register button, tickets, find my registration, speakers, agenda, sponsors, countdown, venue, add to calendar).
* Live refresh from Odavent, page-cache purging, draft previews in the block editor and a settings page with connection test.
* English and French translations.
* Updates through the normal WordPress update screen.

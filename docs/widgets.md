# Widgets

Every Odavent block is also a classic widget, for themes with widget areas (a sidebar, a footer). A widget
shows exactly what the block would: the same output, the same notices for editors, nothing for visitors
when an event is gone or unpublished. A widget with nothing to show prints nothing at all, not even its title.

A widget's settings are the block's attributes (the same ones as the matching shortcode, see
[shortcodes.md](shortcodes.md)) plus an optional **Title** and an **Event** list. Values go through the same
rules as a shortcode: numbers are clamped to their range, and a checkbox you untick is saved as off.

## Where to find them

* **Classic widgets screen** (Appearance > Widgets on a theme that has not switched to block widgets):
  every widget is listed as **Odavent: ...**. Drag it into a widget area and fill in the form.
* **Block-based widgets screen** (WordPress 5.8 and later): either insert the Odavent block itself, or add
  the **Legacy Widget** block and choose the **Odavent: ...** widget in its dropdown. The blocks are the
  better choice there: they have the live preview and the section toggles.
* **Block themes** (themes without widget areas, edited in the Site Editor) have no widgets screen at all.
  Use the Odavent blocks in templates and template parts instead. The widgets are only for themes that
  register widget areas.

## The widgets

The id base is what WordPress stores and what `is_active_widget()` takes. The attributes are the same as the
shortcode of the same name (and `event` / `event_id` are replaced by the widget's **Event** list).

| Widget | Id base | Attributes (see) |
|---|---|---|
| Odavent: Event header | `odavent_events_event_header` | [`[odavent_event_header]`](shortcodes.md#odavent_event_header) |
| Odavent: Register button | `odavent_events_register_button` | [`[odavent_register_button]`](shortcodes.md#odavent_register_button) |
| Odavent: Tickets | `odavent_events_tickets` | [`[odavent_tickets]`](shortcodes.md#odavent_tickets) |
| Odavent: Find my registration | `odavent_events_find_registration` | [`[odavent_find_registration]`](shortcodes.md#odavent_find_registration) |
| Odavent: Speakers | `odavent_events_speakers` | [`[odavent_speakers]`](shortcodes.md#odavent_speakers) |
| Odavent: Agenda | `odavent_events_agenda` | [`[odavent_agenda]`](shortcodes.md#odavent_agenda) |
| Odavent: Sponsors | `odavent_events_sponsors` | [`[odavent_sponsors]`](shortcodes.md#odavent_sponsors) |
| Odavent: Countdown | `odavent_events_countdown` | [`[odavent_countdown]`](shortcodes.md#odavent_countdown) |
| Odavent: Venue | `odavent_events_venue` | [`[odavent_venue]`](shortcodes.md#odavent_venue) |
| Odavent: Add to calendar | `odavent_events_add_to_calendar` | [`[odavent_add_to_calendar]`](shortcodes.md#odavent_add_to_calendar) |
| Odavent: Event page | `odavent_events_event_page` | [`[odavent_event_page]`](shortcodes.md#odavent_event_page) |

The id base is `odavent_events_` plus the block name with dashes turned into underscores. The widgets are
generated from the blocks, so a block added in a later version gets its widget without further work.

### The Event page widget and its sections

The Event page widget has a plain text field for `sections` (a widget form has no toggles). Type the
section names, separated by commas: `header`, `countdown`, `register`, `tickets`, `find-registration`,
`speakers`, `agenda`, `sponsors`, `venue`, `calendar`. For example `speakers,agenda,venue`. Leave it blank
for every section. Unknown names are ignored, and the order on the page is always the one listed here, not
the order you typed. A sidebar is narrow: this widget is best for a short selection of sections.

## Page caches

A widget is not part of a post's content and can appear on every page. So when a signed refresh arrives from
Odavent (the event changed) and **an Odavent widget is active**, the plugin cannot know which pages show it
and purges the **whole-site** page cache instead of only the posts that contain Odavent blocks (WP Rocket,
W3 Total Cache, WP Super Cache and LiteSpeed Cache are purged by the plugin itself, anything else through the
[`odavent_events_purge_all`](hooks.md#odavent_events_purge_all) action).

"An Odavent widget is active" means any of:

* one of the widgets above is placed in an active widget area;
* an Odavent block (a Block widget, WordPress 5.8+), an Odavent shortcode in a Text widget, or one in a
  Custom HTML widget is placed in an active widget area.

Widgets in the inactive area (the "Inactive widgets" list) do not count. The same detection loads the
stylesheet in the page head and keeps editors' previews out of page caches, see
[`odavent_events_page_has_content`](hooks.md#odavent_events_page_has_content).

A whole-site purge is heavier than a per-page one. If your widgets sit in an area your cache never stores, or
you prefer to purge by hand, turn it off:

```php
add_filter( 'odavent_events_purge_all_on_widget', '__return_false' );
```

See [`odavent_events_purge_all_on_widget`](hooks.md#odavent_events_purge_all_on_widget). Without the
whole-site purge, a page that shows the widget refreshes when its cache expires (the cache lifetime in
Settings > Odavent Events) or when you purge it yourself. A widget placed in a block theme's template parts
(not a widget area) is not detected at all; see [operations.md](operations.md#page-caches).

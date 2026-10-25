# Shortcodes

Every Odavent block has a shortcode with the same output, for classic editors, widgets and page builders.
The shortcode name is `odavent_` plus the block name with dashes turned into underscores.

## Common attributes

Every shortcode accepts these, on top of its own attributes below:

| Attribute | Type | Default | Meaning |
|---|---|---|---|
| `event` | string | (default event) | The event's slug on Odavent, e.g. `event="annual-summit"`. |
| `event_id` | integer | (default event) | The event's Odavent ID. Wins over `event` when both are given. |
| `class` | string | (none) | Extra CSS classes for the block's outer element. |

With neither `event` nor `event_id`, the default event from Settings > Odavent Events is used.

**A draft can only be previewed by its ID.** A slug is resolved only for published (or paused, cancelled,
archived) events, so that no visitor can tell a draft from an unknown slug: `event="slug"` of a DRAFT event
shows nothing, even to an editor. To preview a draft, use `event_id="123"` (the ID is the number in the
event's address in the Odavent dashboard, `/manage/events/123`); the slug works once the event is published.

## Values

- Booleans: `true`, `false`, `1`, `0`, `yes`, `no`, `on`, `off` (any case). An empty value (`show_bio=""`) counts as `false`. Anything else is ignored and the default applies.
- Integers: digits only. Text such as `4px` is ignored and the default applies. A number outside the allowed range is clamped by the block.
- Strings: plain text (HTML is removed), at most 500 characters.
- Attributes a shortcode does not have are ignored. Attribute names are written in snake_case (`show_bio`): the dashed spelling (`show-bio`) and the camelCase one (`showBio`) are not recognised and are ignored.
- Enclosed content (`[odavent_speakers]text[/odavent_speakers]`) is ignored: shortcodes are self-closing, and the text between the tags is not shown.

## `[odavent_event_header]`

Title, dates, cover image, logo, description and venue of the event.

| Attribute | Type | Default |
|---|---|---|
| `show_cover` | boolean | `true` |
| `show_logo` | boolean | `true` |
| `show_description` | boolean | `true` |
| `show_venue` | boolean | `true` |
| `show_json_ld` | boolean | `true` |

Example: `[odavent_event_header event="annual-summit" show_cover="no"]`

## `[odavent_register_button]`

A registration button linking to the event's registration page on Odavent.

| Attribute | Type | Default |
|---|---|---|
| `label` | string | (translated default) |
| `ticket_id` | integer | `0` (none) |

Example: `[odavent_register_button event="annual-summit" label="Book your seat" ticket_id="31"]`

## `[odavent_tickets]`

The ticket types with price and a register link.

| Attribute | Type | Default |
|---|---|---|
| `show_description` | boolean | `true` |
| `show_availability` | boolean | `false` |
| `button_label` | string | (translated default) |

Example: `[odavent_tickets event="annual-summit" show_availability="yes"]`

## `[odavent_find_registration]`

A link for attendees to find their existing registration.

| Attribute | Type | Default |
|---|---|---|
| `label` | string | (translated default) |

Example: `[odavent_find_registration event_id="12" label="Already registered?"]`

## `[odavent_speakers]`

The event's speakers.

| Attribute | Type | Default |
|---|---|---|
| `columns` | integer | `3` (2 to 4) |
| `show_bio` | boolean | `false` |
| `show_company` | boolean | `true` |
| `limit` | integer | `0` (all) |
| `speaker_id` | integer | `0` (all speakers) |

Example: `[odavent_speakers event="annual-summit" columns="2" show_bio="yes"]`

## `[odavent_agenda]`

The sessions grouped by day, with an optional track filter.

| Attribute | Type | Default |
|---|---|---|
| `show_speakers` | boolean | `true` |
| `show_description` | boolean | `false` |
| `track_filter` | boolean | `true` |

Example: `[odavent_agenda event="annual-summit" show_description="true" track_filter="off"]`

## `[odavent_sponsors]`

Sponsors, exhibitors and other partners.

| Attribute | Type | Default |
|---|---|---|
| `group_by_type` | boolean | `true` |
| `show_description` | boolean | `false` |
| `columns` | integer | `4` |

With `group_by_type` on, partners are shown in one group per type. Groups that hold a featured (paid)
placement come first, then the others, each part in the order sponsors, exhibitors, media partners, partners,
then any other type; inside a group the order is Odavent's (featured first). With it off, one list in
Odavent's order.

Example: `[odavent_sponsors event="annual-summit" columns="3" group_by_type="no"]`

## `[odavent_countdown]`

A countdown to the start of the event.

| Attribute | Type | Default |
|---|---|---|
| `label_live` | string | (blank: translated default) |
| `label_ended` | string | (blank: translated default) |

Example: `[odavent_countdown event="annual-summit" label_live="We are live!"]`

## `[odavent_venue]`

Where the event takes place, with an optional map link.

| Attribute | Type | Default |
|---|---|---|
| `show_map_link` | boolean | `true` |

Example: `[odavent_venue event="annual-summit" show_map_link="no"]`

## `[odavent_add_to_calendar]`

Links to add the event to Google Calendar, Outlook or a calendar file. Nothing is shown for a cancelled or
archived event.

| Attribute | Type | Default |
|---|---|---|
| `show_google` | boolean | `true` |
| `show_outlook` | boolean | `true` |
| `show_ics` | boolean | `true` |

Example: `[odavent_add_to_calendar event="annual-summit" show_outlook="no"]`

## `[odavent_event_page]`

The whole event page in one shortcode: header, countdown, Register button, tickets, find my registration,
speakers, agenda, sponsors, venue and calendar links, always in that order. A section with nothing to show
is left out together with its heading. An ended, paused or cancelled event's notice appears once, at the
top, and the stale-data notice (when Odavent could not be reached and a saved copy is shown) once, inside
the page, when `register` or `tickets` is among the chosen sections (it stands in for the missing Register
links).

| Attribute | Type | Default |
|---|---|---|
| `sections` | string | (all sections) |
| `show_headings` | boolean | `true` |
| `speakers_columns` | integer | `3` (2 to 4) |
| `sponsors_columns` | integer | `4` (1 to 6) |
| `show_speaker_bio` | boolean | `false` |
| `show_availability` | boolean | `false` |

### How `sections` and the order work

`sections` is a comma list of section names; blank (or no `sections` attribute) means every section:

| Name | Shows | Heading |
|---|---|---|
| `header` | Title, dates, cover, logo, description, venue line | (none) |
| `countdown` | The live countdown | (none) |
| `register` | The Register button | (none) |
| `tickets` | The ticket types | Tickets |
| `find-registration` | The "find my registration" link | (none) |
| `speakers` | The speakers | Speakers |
| `agenda` | The agenda | Agenda |
| `sponsors` | Sponsors, exhibitors and partners | Sponsors and partners |
| `venue` | Venue, address and map link | Venue |
| `calendar` | The add-to-calendar links | (none) |

* The order is **fixed** (the order of the table), never the order you type: `sections="agenda,speakers"`
  shows speakers first. To arrange the parts differently, use the individual shortcodes or blocks (the
  **Full event page** block pattern inserts them in page order).
* Names are case-insensitive and may have spaces around the commas. Unknown names are ignored; a list that
  names no known section means every section.
* `show_headings="no"` removes the headings above tickets, speakers, agenda, sponsors and venue.
* `speakers_columns`, `sponsors_columns`, `show_speaker_bio` and `show_availability` are the speakers'
  `columns` and `show_bio`, the sponsors' `columns` and the tickets' `show_availability`. The other options of
  those sections keep their defaults; use the individual shortcode when you need more control.
* In the block editor the `sections` list is a set of toggles; the shortcode and the
  [Event page widget](widgets.md#the-event-page-widget-and-its-sections) use the comma list.

Examples:

* `[odavent_event_page event="annual-summit"]` (everything)
* `[odavent_event_page event="annual-summit" sections="speakers,agenda" show_headings="no" speakers_columns="2"]`
* `[odavent_event_page event_id="42" sections="header,register,tickets,venue" show_availability="yes"]`

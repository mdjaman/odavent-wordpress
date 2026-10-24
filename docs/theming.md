# Theming

The plugin works with any theme: the markup is plain, fonts and text colour come from your theme, and
the look is controlled by a handful of CSS variables. For more control you can restyle everything with
your own CSS or replace the HTML of any block from your theme.

## CSS variables

The default stylesheet (`assets/css/odavent.css`) reads these variables. Set them on `:root`, on `body`
or on any ancestor of the blocks, for example in Appearance > Customize > Additional CSS:

```css
:root {
	--odv-accent: #0f766e;
	--odv-accent-contrast: #ffffff;
	--odv-radius: 4px;
}
```

| Variable | Default | What it controls |
|---|---|---|
| `--odv-accent` | `#3730a3` | Buttons, active filter, featured cards, session accent bar, placeholder photos, focus ring. |
| `--odv-accent-contrast` | `#fff` | Text on top of the accent: buttons, the active agenda filter, placeholder initials. |
| `--odv-text` | `inherit` | Text colour of every block. By default the theme's colour is used. |
| `--odv-muted` | `color-mix(in srgb, currentColor 70%, transparent)`, `#5b6270` in browsers without `color-mix` | Secondary text: dates, roles, descriptions, notices. |
| `--odv-border` | `#d9dce3` | Card, filter and countdown borders. |
| `--odv-radius` | `8px` | Corner radius of cards, buttons and notices. |
| `--odv-gap` | `1rem` | Spacing between cards and inside them; block margins are `1.5 x` this. |

**`--odv-accent-contrast` assumes a dark accent.** The default is white text, which is readable on dark
and mid-tone accents. If you choose a light accent (yellow, light blue), set `--odv-accent-contrast` to a
dark colour, otherwise button text becomes hard to read.

Variables that start with `--_` (such as `--_accent`) are internal to the stylesheet; do not rely on them.

## The Appearance settings

Organizers can set the main variables without CSS under **Settings > Odavent Events > Appearance**: accent
colour, text on accent (automatic, or a chosen colour), text colour, border colour, corner radius (0 to 24
px), spacing (compact, comfortable, roomy) and button style (filled or outline). Each one maps to a variable:

| Setting | Writes |
|---|---|
| Accent colour | `--odv-accent`, and `--odv-accent-contrast` too when "Text on accent" is automatic (black or white, whichever reads better) |
| Text on accent | `--odv-accent-contrast` |
| Text colour | `--odv-text` |
| Border colour | `--odv-border` |
| Corner radius | `--odv-radius` (px) |
| Spacing | `--odv-gap`: compact `0.75rem`, comfortable `1rem`, roomy `1.5rem` |
| Button style | Outline: transparent buttons with the accent as text and border, filled on hover; filled is the default |

Saving warns (and still saves) when button text falls below a 4.5:1 contrast ratio: the text on the accent
(judged against the default `#3730a3` when only "Text on accent" is set), and, with outline buttons, the accent
as text on a white page. An empty field writes nothing, so the stylesheet's own default applies. The values are validated (hex colours,
a clamped whole number and fixed keywords; never free text) and added after the plugin's stylesheet, so the
screen only has an effect while **Default styles** is on. The generated CSS can be changed with the
[`odavent_events_appearance_css`](hooks.md#odavent_events_appearance_css) filter.

### Which CSS wins

The settings are **defaults, declared on `:root`**, and the plugin never uses `!important` for them:

* Your own CSS wins over the settings whenever it is declared closer or later: a variable set on `body`, on a
  wrapper of the blocks, or on `:root` further down the page overrides the Appearance value.
* The one exception is the **outline** button style. It is a rule set on the buttons themselves, with a
  higher specificity (`.odv-register .odv-register__button:not(.odv-register__button--disabled):not([aria-disabled="true"])`
  and `.odv-tickets .odv-tickets__register`). To restyle those buttons yourself while the outline style is
  on, your selector needs at least that specificity (and to come later in the page), or set the button style
  back to filled.
* The plugin's stylesheet and the settings are both skipped when "Default styles" is off.

## Turning the default stylesheet off

Settings > Odavent Events > "Load default styles". When unchecked the plugin loads no CSS at all (also not
in the block editor) and you style the markup below yourself. Fonts, colours and spacing then come only from
your theme and your CSS. Things worth restyling from scratch:

* the grids (`.odv-speakers__list`, `.odv-sponsors__list`, `.odv-tickets__list`); the column count is
  in the modifier class, for example `.odv-speakers__list--cols-3`;
* the buttons (`.odv-register__button`, `.odv-tickets__register`) and the agenda filter buttons;
* the agenda session rows, which use a two-column grid in the default stylesheet;
* the visually hidden states: `[hidden]` elements must stay hidden (`.odv-countdown [hidden]`, filtered
  agenda sessions and days), or the agenda filter and the countdown stop working.

## Class names

Every block renders one `section` with a root class, and its parts use BEM names (`root__part`,
`root__part--modifier`). The root also receives the "Additional CSS class(es)" of the block, or the
`class` attribute of the shortcode, and always a `data-odv-event` attribute with the Odavent event id.

| Block | Root | Parts |
|---|---|---|
| Event header | `.odv-header` | `.odv-header__cover`, `.odv-header__logo`, `.odv-header__title`, `.odv-header__dates`, `.odv-header__meta` (venue line), `.odv-header__description` |
| Register button | `.odv-register` | `.odv-register__button`, `.odv-register__notice` (secure-payment line), `.odv-register__status` (why there is no button); `.odv-register__button--disabled` is styled but not used by the default template |
| Tickets | `.odv-tickets` | `.odv-tickets__list`, `.odv-tickets__item`, `.odv-tickets__item--sold-out`, `.odv-tickets__name`, `.odv-tickets__price`, `.odv-tickets__description`, `.odv-tickets__status`, `.odv-tickets__register`, `.odv-tickets__message`; the secure-payment line reuses `.odv-register__notice` |
| Find my registration | `.odv-find-registration` | `.odv-find-registration__link` |
| Speakers | `.odv-speakers` | `.odv-speakers__list`, `.odv-speakers__list--cols-2`, `.odv-speakers__list--cols-3`, `.odv-speakers__list--cols-4`, `.odv-speakers__list--single`, `.odv-speakers__item`, `.odv-speakers__item--featured`, `.odv-speakers__photo`, `.odv-speakers__photo--placeholder`, `.odv-speakers__name`, `.odv-speakers__role`, `.odv-speakers__company`, `.odv-speakers__bio` |
| Agenda | `.odv-agenda` | `.odv-agenda__filters`, `.odv-agenda__filter`, `.odv-agenda__filter--active` (the active button also has `aria-pressed="true"`), `.odv-agenda__day`, `.odv-agenda__day-title`, `.odv-agenda__list`, `.odv-agenda__session`, `.odv-agenda__session--child` (a room nested under a session), `.odv-agenda__time`, `.odv-agenda__title`, `.odv-agenda__location`, `.odv-agenda__track`, `.odv-agenda__speakers`, `.odv-agenda__description`, `.odv-agenda__rooms` |
| Sponsors | `.odv-sponsors` | `.odv-sponsors__group`, `.odv-sponsors__group-title`, `.odv-sponsors__list`, `.odv-sponsors__list--cols-1`, `.odv-sponsors__list--cols-2`, `.odv-sponsors__list--cols-3`, `.odv-sponsors__list--cols-4`, `.odv-sponsors__list--cols-5`, `.odv-sponsors__list--cols-6`, `.odv-sponsors__item`, `.odv-sponsors__item--featured`, `.odv-sponsors__link`, `.odv-sponsors__logo`, `.odv-sponsors__name`, `.odv-sponsors__description` |
| Countdown | `.odv-countdown` | `.odv-countdown__timer`, `.odv-countdown__unit`, `.odv-countdown__value`, `.odv-countdown__label`, `.odv-countdown__message` |
| Venue | `.odv-venue` | `.odv-venue__name`, `.odv-venue__address`, `.odv-venue__map` |
| Add to calendar | `.odv-calendar` | `.odv-calendar__list`, `.odv-calendar__link` |
| Event page | `.odv-event-page` (a `div`) | `.odv-event-page__section` (one per section shown, with a `.odv-event-page__section--{section}` modifier and an `id` anchor such as `odv-speakers-42`), `.odv-event-page__heading`; each section holds the matching block's own markup |
| Notices (lifecycle lines, editor messages) | `.odv-notice` | `.odv-notice--ended`, `.odv-notice--paused`, `.odv-notice--cancelled`, `.odv-notice--draft`, `.odv-notice--stale`, `.odv-notice--unavailable`, `.odv-notice--error` |

What the scripts depend on, if you override these templates: `.odv-agenda__filter` buttons with a
`data-track` attribute, sessions with a matching `data-track`, and `.odv-agenda__day` /
`.odv-agenda__session` (the agenda filter); the `data-*` attributes and the `.odv-countdown__*` classes
on the countdown; and, for the broken-image fallback, `<img>` tags carrying `data-odv-img="photo|logo|cover"` (speaker
photos also `data-odv-initials`). See the notes at the top of `templates/agenda.php` and `templates/countdown.php`.

### Broken images

`assets/js/image-fallback.js` (loaded with the blocks) reads the `data-odv-img` attribute of an image that
cannot load: `photo` (a speaker's photo) becomes the initials placeholder, `logo` on a sponsor becomes the
sponsor's name, and a header `logo` or `cover` is removed, so no broken-image icon is shown. If you override a
template, keep `data-odv-img="photo|logo|cover"` on the `<img>` (and `data-odv-initials` on a speaker photo)
to keep this behaviour.

### The event page markup

The Event page block (and `[odavent_event_page]`, and the Event page widget) is one `div.odv-event-page`
holding one `div.odv-event-page__section` per section shown, in the fixed section order, each with a
`.odv-event-page__section--{section}` modifier (`header`, `countdown`, `register`, `tickets`,
`find-registration`, `speakers`, `agenda`, `sponsors`, `venue`, `calendar`). Tickets, speakers, agenda,
sponsors and venue get an `h2.odv-event-page__heading` (the organizer can turn the headings off); the other
sections speak for themselves. Inside each section is the markup of the matching block (`.odv-speakers`,
`.odv-agenda`...), so your CSS for the individual blocks applies unchanged.

```css
.odv-event-page__section { margin-block: 3rem; }
.odv-event-page__heading { font-size: 1.5rem; border-bottom: 2px solid var(--odv-accent); }
```

* **Anchors.** Every section has an `id` of the form `odv-{section}-{event id}`, for example
  `odv-speakers-42`, so a menu can link to `#odv-speakers-42`. If the same event page is rendered twice in
  one request (some themes run `the_content` twice, or a page shows two event pages of the same event) the
  repeated ids get a suffix, `odv-speakers-42-2`, `odv-speakers-42-3`, so ids stay unique in the document.
* **Notices.** The lifecycle notice of an ended, paused or cancelled event (and the draft preview notice
  for editors) is printed once, directly above `.odv-event-page`, not inside it. The stale-data notice
  ("this information may be out of date") is printed inside it, at the top.

## Template overrides

To change the HTML of a block, copy its template from the plugin's `templates/` folder into your theme in
an `odavent` folder. The plugin looks for `{name}.php` in this order and uses the first one found:

1. `{child theme}/odavent/{name}.php`
2. `{parent theme}/odavent/{name}.php`
3. `{plugin}/templates/{name}.php`

Never edit the files inside the plugin: an update would overwrite your changes. After a plugin update,
compare your copy with the new version of the template; the `@var` list at the top of each file says
which variables it receives.

Every value handed to a template is already cleaned (text has its tags removed, HTML is restricted to the
allowed tags, URLs are absolute `http(s)`). **Escape on output anyway** (`esc_html()`, `esc_attr()`,
`esc_url()`), as the built-in templates do. Values documented as "sanitised HTML" are the exception: they
are printed as they are, because they are already filtered by `Sanitizer::html()`.

Every template also receives `$view`, the whole event (`\Odavent\Events\Data\Event_View`), for advanced
needs. Use `$view->event()`, `$view->tickets()`, `$view->sessions()`, `$view->speakers()` and
`$view->partners()` to read raw payload arrays; those are **not** cleaned. Prefer the prepared variables.

### Templates and their variables

All the block templates receive `$classes` (the root element classes, escape with `esc_attr()`), `$event_id`
(int) and `$view`.

| Template | Other variables |
|---|---|
| `event-header` | `$name`, `$dates` (formatted in the event's timezone, may be empty), `$venue` (one line, may be empty), `$cover_url`, `$logo_url` (`''` when none), `$description` (sanitised HTML), `$json_ld` (schema.org script element; print it as it is or leave it out to drop the structured data) |
| `register-button` | `$url` (`''` when registration is not offered), `$label`, `$status` (why there is no button), `$stale_notice` (HTML of a notice, `''` unless the data is a stale copy), `$notice` (the secure-payment line) |
| `tickets` | `$tickets` (rows: `name`, `description` (sanitised HTML), `price`, `sold_out`, `status`, `remaining`, `url`), `$button_label`, `$message` (why there are no Register links), `$notice`, `$stale_notice` |
| `find-registration` | `$url`, `$label` |
| `speakers` | `$list_class` (carries the column count), `$speakers` (rows: `name`, `role`, `company`, `bio` (sanitised HTML), `photo_url`, `initials`, `featured`) |
| `agenda` | `$tracks` (names for the filter buttons, empty for no filter), `$days` (rows: `title`, `sessions`; a session has `name`, `time`, `location`, `track`, `speakers`, `description` (sanitised HTML) and `rooms`, the sessions nested under it) |
| `sponsors` | `$list_class`, `$groups` (rows: `title`, `items`; an item has `name`, `logo_url`, `link_url`, `description` (sanitised HTML), `featured`) |
| `countdown` | `$starts_at`, `$ends_at` (ISO 8601), `$state` (`upcoming`, `live`, `ended`), `$ended_flag`, `$paused`, `$label_live`, `$label_ended`, `$days`, `$hours`, `$minutes`, `$seconds` |
| `venue` | `$name`, `$address`, `$map_url` (`''` when the link is off) |
| `add-to-calendar` | `$links` (rows: `url`, `label`, `download`) |
| `event-page` | `$sections` (rows in page order: `slug`, `id` (unique anchor), `title` (`''` for sections without a heading), `html` (the section's own rendered markup)), `$show_headings`, `$stale_notice` (HTML of a notice, `''` unless the data is a stale copy) |
| `notice` | `$kind` (the modifier: `ended`, `paused`, `cancelled`, `draft`, `stale`, `unavailable`, `choose`, `error`), `$message` (plain text). This template has no `$classes`, `$event_id` or `$view`. |

To pass more variables to a template, or change them, use the
[`odavent_events_view_data`](hooks.md#odavent_events_view_data) filter.

### Example: a child-theme override of the speakers block

`wp-content/themes/my-child/odavent/speakers.php`:

```php
<?php
/**
 * Speakers as a simple list with the photo on the left.
 *
 * @var string $classes
 * @var int    $event_id
 * @var array  $speakers
 */
defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?> my-speakers" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
	<?php foreach ( $speakers as $speaker ) : ?>
		<article class="my-speakers__person">
			<?php if ( '' !== $speaker['photo_url'] ) : ?>
				<img src="<?php echo esc_url( $speaker['photo_url'] ); ?>" alt="" width="64" height="64" loading="lazy">
			<?php endif; ?>
			<h3><?php echo esc_html( $speaker['name'] ); ?></h3>
			<?php if ( '' !== $speaker['role'] ) : ?>
				<p><?php echo esc_html( $speaker['role'] ); ?></p>
			<?php endif; ?>
		</article>
	<?php endforeach; ?>
</section>
```

Keep the `$classes` on the root element: it carries the "Additional CSS class(es)" the editor typed.

### Example: change one detail with CSS only

```css
/* Round sponsor logos on a grey background, two per row on phones. */
.odv-sponsors__item { background: #f3f4f6; }
.odv-sponsors__logo { border-radius: 50%; }
@media (max-width: 36rem) {
	.odv-sponsors__list { grid-template-columns: repeat(2, 1fr); }
}
```

# Odavent Events plugin: all-in-one event page, widgets and an appearance screen

Date: 2026-10-24. Target: version 0.2.0 (see "Release sequencing"). Repo: `odavent-wordpress` (PHP 7.4+, WordPress 6.1+).

## Why

0.1.0 gives a webmaster ten blocks and ten shortcodes (event header, register button, tickets, find my
registration, speakers, agenda, sponsors, countdown, venue, add to calendar), seven CSS variables and
template overrides. A real end-to-end test against production Odavent showed two gaps:

1. **Building an event page means stacking ten blocks by hand.** The test page for SIA 2026 repeated the
   "This event has ended" message three times, and one real partner type (`both`) showed up as the raw word
   "BOTH" as a heading.
2. **Nothing is configurable without writing CSS or PHP.** An organizer who installs the plugin cannot make the
   page match their brand from the WordPress admin, and there are no sidebar or footer widgets.

Two audiences use the plugin, and both must be served:

- **The organizer** (often not technical): installs the plugin, connects it, adds one thing to a page, picks
  brand colours in a settings screen. Never writes CSS, shortcodes or PHP.
- **The webmaster** (the person who builds the organizer's site): wants shortcodes and widgets for each piece
  (speakers, sessions with their speakers, tickets/registration, sponsors), CSS variables, template overrides
  and hooks, and wants their own CSS to win over any plugin setting.

## Goals

1. One **"Event page"** block and `[odavent_event_page]` shortcode that render the whole event page from one
   piece, with sections the organizer ticks on or off.
2. A **classic WordPress widget** for every block (including the event page), for sidebars, footers and the
   Legacy Widget block, with the same attributes as the matching shortcode.
3. An **Appearance** section in Settings > Odavent Events: brand colours, corner radius, spacing, button style,
   with a live preview.
4. Fix the **"Both" partner label** and the **repeated ended notice** on a combined page.
5. Small robustness item: a **broken image** (the file 404s on Odavent, as the demo event's cover and a sponsor
   logo do today) degrades to the existing placeholder or to nothing, instead of a broken-image icon.
6. **Documentation for both audiences**, English and French strings, tests at the same standard as 0.1.0.

## Non-goals (do not build)

- Native Elementor, Divi, Beaver Builder or Bricks widgets (the shortcode works inside their Shortcode widget).
- A registration form, checkout or login inside WordPress. Registration and payment stay on Odavent; no
  attendee data goes to WordPress.
- Per-block colour or typography pickers (`supports.color` and friends). The block's "Additional CSS class"
  and the global Appearance settings are the controls.
- A free-text custom CSS box (WordPress has Additional CSS), font pickers, automatic dark mode,
  `theme.json` integration.
- New API fields or any change on the Odavent side. The payload contract v1 is untouched.
- Anti-goals: an Appearance setting must never be able to make the page worse than defaults without a
  warning (unreadable button text); and no new setting may be required for the plugin to work.

## Design

### 1. Event page block and shortcode

- New block `odavent-events/event-page` (own `blocks/event-page/block.json`, a renderer in `src/Blocks/Renderers/`,
  added to the registry's renderer map so the shortcode is derived automatically as `[odavent_event_page]`).
  Dynamic block (server-rendered, `save` returns null), like the others.
- Attributes: `eventId`/`eventSlug` as usual; `sections` (array of slugs from `header`, `tickets`, `speakers`,
  `agenda`, `sponsors`, `venue`, `calendar`, `countdown`, `register`, `find-registration`);
  default order: `header, countdown, register, tickets, find-registration, speakers, agenda, sponsors, venue, calendar`.
  The order is fixed (the list only turns sections on or off), and it is exactly the order of this default list,
  with `countdown` placed right after `header` and **on by default**: the countdown section renders only while
  the event is upcoming or live, and nothing for an ended, paused or cancelled event (the single top notice says
  it), so it never repeats "Event ended" on a combined page. (My call.) `showHeadings` (default true);
  `speakersColumns`, `sponsorsColumns`, `showSpeakerBio`, `showAvailability` pass through to the matching section.
- Shortcode spelling: `sections="header,tickets,speakers"` (comma list, unknown names ignored, an empty list means
  the default), the other attributes in snake_case as for every shortcode (`show_headings`, `speakers_columns`...).
- It renders every chosen section through the **existing renderers and `Block_Registry::render_block()` rules**:
  one render path, no duplicated markup, templates still overridable per section. A section with nothing to show
  renders nothing and its heading is dropped too (an event with no speakers shows no "Speakers" heading).
- Headings: `<h2 class="odv-event-page__heading">` per section ("Tickets", "Speakers", "Agenda", "Sponsors and
  partners", "Venue"), translated, each section wrapped with an `id` anchor (`odv-speakers` and so on, made
  unique per event on the page). Root class `odv-event-page`.
- **Lifecycle shown once.** On a combined page the ended / paused / cancelled / draft notice **and the stale-copy
  notice** (`Lifecycle::stale_notice_html()`, which Register and Tickets each print today) appear once, at
  the top; the tickets, register and countdown sections do not repeat it. Register and tickets still behave
  as today (no Register link for an ended, paused, cancelled or stale event).
- Editor: the inspector shows an event picker and one toggle per section (plus the pass-through settings), with the
  usual server-side preview. A block **pattern** "Odavent: full event page" (registered with `register_block_pattern`,
  category Odavent) inserts the individual blocks for organizers who want to reorder or delete sections by hand.

### 2. Widgets

- One `WP_Widget` per block, **generated from `block.json`** (a single base class plus the slug, the same
  principle as the shortcodes: a new block gets its widget without new code), registered on `widgets_init` as
  instances. Names "Odavent: Speakers" and so on, class `odavent_events_widget`, under the existing text domain.
- Form: optional title, event picker (default event when empty), then one control per block attribute, derived
  from its type (checkbox, number, text, select for enums), reusing the shortcode type casting and clamping.
- Output goes through the same `render_block()` path with the widget's `before_widget` / `after_widget` and an
  optional `<h2 class="widget-title">`. A widget that renders nothing outputs nothing (no empty title).
- Instances are sanitised on save **and** again on render (stored data from another version is not trusted).
- **A widget is not post content**, so three places that look for `wp:odavent-events/` or `[odavent_` in post
  content must also know about widgets (my call, the safe side): (a) `Page_Cache_Purger`: when any Odavent widget
  is active (`is_active_widget`), a refresh ping also asks the supported cache plugins to purge the **whole
  site** cache (their domain-wide function, not per post), because the widget can be on every page; a filter
  `odavent_events_purge_all_on_widget` (default true) lets a webmaster turn that off and accept staleness up to
  the cache lifetime; (b) the "editors may preview a draft, so do not cache" rule
  (`Block_Registry::user_can_preview` / `page_has_odavent_content`) must treat a rendered widget like rendered
  content; (c) the stylesheet is enqueued by the widget's own render (the early-in-head check is only an
  optimisation for content). The smoke and e2e tests must prove a cancelled event's Register link disappears
  from a page that shows a widget.
- Works in the classic Widgets screen and, through WordPress's Legacy Widget block, in block-based widget areas.
  Caveat to document: a block theme with no widget areas simply has nowhere to show widgets; use the blocks.

### 3. Appearance settings

- New section "Appearance" on the existing settings page, stored as an `appearance` array inside the existing
  `odavent_events_settings` option (so the uninstaller already removes it). Fields:
  accent colour, text on accent (**automatic by default**: black or white, whichever has the higher contrast
  ratio against the accent; a manual override is allowed), text colour (empty = theme), border colour,
  corner radius (0 to 24 px), spacing (compact / comfortable / roomy, mapped to `--odv-gap`), button style
  (filled / outline).
- Output: the values are applied as CSS custom properties (`--odv-accent`, `--odv-accent-contrast`, `--odv-text`,
  `--odv-border`, `--odv-radius`, `--odv-gap`) through `wp_add_inline_style` on the existing stylesheet handle,
  on `:root`, **only for the fields the organizer changed**, in the front end and in the block editor. The
  button-style choice adds one modifier class on the plugin roots or one small rule; builder's choice.
  Because it is a default declared on `:root`, a webmaster's own CSS (Additional CSS, child theme) declared
  closer or later still wins. Document this precedence.
- Safety: only validated values reach the page: hex colours (`sanitize_hex_color`, normalised), integers
  clamped to range, enums from a fixed list. No free text is ever written into CSS.
- When "Load default styles" is off, the Appearance fields are shown disabled with a one-line explanation
  (there is no stylesheet to configure).
- A **contrast warning** on save (not a block): if the text-on-accent ratio is below 4.5:1, show a notice with
  the ratio. A live preview (a button, a ticket card, a speaker card, using the plugin's real classes) updates
  as colours change; it needs the WordPress colour picker and one small admin script (no build step).
- Filter `odavent_events_appearance_css` (final CSS string) and the existing hooks stay available to webmasters.

### 4. Fixes

- **Partner type `both`**: label "Sponsors and exhibitors" (translated); known types order becomes sponsor,
  both, exhibitor, media, partner. Any other unknown type keeps the current fallback.
- **Broken images**: speaker photos and sponsor logos and the header cover/logo get a capture-phase `error`
  handler in one small deferred script (no inline handlers, so strict CSP sites work). A broken speaker photo is
  replaced by the initials placeholder, a broken sponsor logo by the partner's name, a broken cover/logo is hidden.
  Loaded only when such an image was rendered, like the other scripts.

### 5. Documentation and strings

- README: a "For organizers" quick start (install, connect, add the Event page block, set colours) and a
  "For webmasters" reference pointing at the docs. `docs/shortcodes.md` gains `[odavent_event_page]` and a
  widgets table (the existing test that keeps the doc in step with `block.json` must cover it), `docs/theming.md`
  explains the Appearance screen and the precedence rules, `readme.txt` and `CHANGELOG.md` (0.2.0 section).
- New strings in `languages/odavent-events.pot` and the French `.po` (fr_FR is the only shipped locale);
  the translation check script must pass, including the block editor strings.

## Constraints

- PHP 7.4 syntax, WordPress 6.1 as the floor, no Composer runtime dependencies, no JS build step (the editor
  script is hand-written like `assets/js/editor.js`).
- Nothing new may break a page: every new render path keeps the "never throws, returns a string" rule; widgets and
  the combined block follow the same visitor-vs-editor rules as the blocks (visitors never see an error or a draft).
- Existing public contracts do not change: block names, shortcode names and attributes, CSS class names and
  variables, template variable names, the refresh endpoint, the update checker.
- Quality bar of 0.1.0: unit tests with mutation proof for new behaviour, PHP 7.4 and 8.3 runs, the Docker
  smoke test and the Playwright e2e extended for the new block, a widget and the Appearance screen, on WP 7.1,
  6.9 and 6.1; per-task review and a final whole-branch review.

## Decided by me (not by the product owner)

- Widgets are **classic WordPress widgets**, not page-builder widgets (the owner's answer was "all-in-one with
  widget and shortcodes"; the word was not defined).
- Section order is fixed; organizers who need another order use the pattern or the individual blocks.
- Appearance covers colours, radius, spacing and button style only; fonts stay with the theme.
- The event page block is dynamic (not a pattern only), so it is the same render path as the shortcode.

## Release sequencing

Version 0.1.0 is not tagged yet. Plan: land the one-line "Both" label fix on its own if the owner wants 0.1.0
out first; otherwise everything here ships as 0.2.0 (or as 0.1.0 if the owner prefers one release). The owner
decides; the code is the same either way.

## Left to the builder

File layout, the exact markup of headings and the preview, the way the button style is applied, the exact
copy of labels and notices (English first, then French), how the inspector toggles look, how widget forms
are laid out, and the order of the tasks.

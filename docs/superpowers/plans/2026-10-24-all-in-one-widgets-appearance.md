# All-in-one event page, widgets and appearance: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the Odavent Events plugin one "Event page" block and shortcode, classic widgets for every block, an Appearance settings section, and fix the "Both" label, the repeated lifecycle notices and broken images.

**Architecture:** Everything reuses the one render path (`Block_Registry::render_block()` and the section renderers). The event page is a new renderer that calls the existing renderers; widgets are generated from `block.json` like the shortcodes; appearance is a validated set of CSS custom properties added to the existing stylesheet with `wp_add_inline_style`.

**Tech Stack:** PHP 7.4+ (WordPress 6.1+), hand-written editor JS (no build step), PHPUnit 9.6 with Brain Monkey (`bin/test`), JS tests (`bin/test-js`), Docker smoke (`bin/smoke.sh`) and Playwright e2e (`bin/e2e`).

**Spec:** `docs/superpowers/specs/2026-10-24-all-in-one-widgets-appearance-design.md` (read it first: this plan only records the decisions the spec leaves to the planner).

## Global Constraints

- PHP 7.4 syntax (no typed properties, no `match`, no `str_contains`, no arrow functions), WordPress 6.1 floor, no Composer runtime dependencies, no JS build step.
- Public contracts do not change: existing block names, shortcode names and attributes, CSS class names and `--odv-*` variables, template variable names, the refresh endpoint, the update checker, payload contract v1.
- Every new render path returns a string and never throws; visitors never see an error or a draft; editors get the existing notices.
- Text domain `odavent-events`; every new string in English with a French translation in `languages/odavent-events-fr_FR.po`; `php bin/check-translations.php` must pass.
- No free text is ever written into CSS: only validated hex colours, clamped integers and fixed enums.
- Escape on output in templates (`esc_html`, `esc_attr`, `esc_url`); `defined( 'ABSPATH' ) || exit;` at the top of PHP files that are not classes in `src/`.
- Tests follow 0.1.0's bar: new behaviour has a test that fails without it (prove it by breaking the code once); run `bin/test` (PHP 7.4) and `PHP_VERSION=8.3 bin/test` before each task's final commit, plus `bin/lint-js` and `bin/test-js` when JS changes.
- Commits: conventional style, ending with `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`. Do not push and do not tag; the owner does.
- Version stays `0.1.0` in the plugin header; changelog entries go under `## [Unreleased]`. The owner picks the release number later.

## Review Focus

1. An event with no speakers, partners or tickets, shown through the event page: no empty heading, no empty wrapper, no stray `id`. Pinned in Task 2.
2. Hostile `sections` values (unknown names, duplicates, upper case, spaces, 5,000 characters, `"><script>`): never an error, never output of the raw value, falls back to the default list. Pinned in Task 2.
3. Hostile Appearance input (`red;}body{display:none`, `#12`, `url(x)`, radius `-5` and `9999`, unknown enum): rejected or clamped, and the generated CSS contains only `#rrggbb`, integers with `px`/`rem` and fixed keywords. Pinned in Task 6.
4. A widget with an old or malformed saved instance (missing keys, wrong types, an unchecked checkbox that the browser does not send, an event that no longer exists): renders the defaults or nothing, never a fatal; and a widget for a cancelled event offers no Register link. Pinned in Tasks 4 and 5.
5. A light accent (`#ffeb3b`) with automatic text colour gets dark text; a manual low-contrast pair is saved with a warning, not refused; "Load default styles" off emits no inline style at all. Pinned in Tasks 6 and 7.

---

### Task 1: "Both" partner label (own branch off `main`, shippable as 0.1.0)

**Files:**
- Modify: `src/Blocks/Renderers/Sponsors.php` (`KNOWN_TYPES`, `type_label()`)
- Modify: `tests/Unit/Blocks/SponsorsTest.php`
- Modify: `languages/odavent-events.pot`, `languages/odavent-events-fr_FR.po`

**Interfaces:**
- Produces: `Sponsors::KNOWN_TYPES === array( 'sponsor', 'both', 'exhibitor', 'media', 'partner' )`; the heading for type `both` is `__( 'Sponsors and exhibitors', 'odavent-events' )` (French "Sponsors et exposants").

- [ ] **Step 1:** `git checkout main && git checkout -b fix/both-partner-label`.
- [ ] **Step 2: Write the failing tests** in `SponsorsTest`: `test_type_both_gets_a_translated_heading_between_sponsors_and_exhibitors` (partners of types exhibitor, both, sponsor in that payload order give headings exactly `Sponsors`, `Sponsors and exhibitors`, `Exhibitors`) and `test_an_unknown_type_still_gets_the_humanised_fallback` (type `vip-guest` gives `Vip guest`, unchanged).
- [ ] **Step 3:** Run `bin/test --filter SponsorsTest`; expected: the first test FAILS (heading is "Both").
- [ ] **Step 4:** Implement: add `both` to `KNOWN_TYPES` after `sponsor` and a `case 'both'` in `type_label()`.
- [ ] **Step 5:** `bin/make-pot.sh`, add the French entry, run `php bin/check-translations.php`; then `bin/test` and `PHP_VERSION=8.3 bin/test` (all green).
- [ ] **Step 6:** Commit (`fix(sponsors): label the "both" partner type`). Then `git checkout feat/all-in-one-widgets-appearance && git merge fix/both-partner-label`.

### Task 2: Event page renderer, block and shortcode

**Files:**
- Create: `src/Blocks/Renderers/Event_Page.php`, `blocks/event-page/block.json`, `templates/event-page.php`, `tests/Unit/Blocks/EventPageTest.php`
- Modify: `src/Blocks/Block_Registry.php` (`RENDERERS`, `NOTICE_BLOCKS`, script enqueue loop, `label()` entries), `src/Blocks/Renderers/Register_Button.php`, `Tickets.php`, `Countdown.php`
- Modify: `tests/Unit/Blocks/BlockJsonTest.php`, `tests/Unit/Blocks/BlockRegistryTest.php`, `tests/Unit/Shortcodes/ShortcodesTest.php` only where they count blocks

**Interfaces:**
- Produces: `final class Event_Page implements Renderer` with
  - `const DEFAULT_SECTIONS = array( 'header', 'countdown', 'register', 'tickets', 'find-registration', 'speakers', 'agenda', 'sponsors', 'venue', 'calendar' )` (this is also the fixed render order);
  - `const SECTION_RENDERERS` mapping each of those slugs to its existing renderer class (`calendar` => `Add_To_Calendar`, `header` => `Event_Header`, `register` => `Register_Button`);
  - `public static function parse_sections( string $raw ): array` returns known slugs only, lower-cased, de-duplicated, in `DEFAULT_SECTIONS` order; the default list when `$raw` is empty or yields nothing;
  - `public static function section_labels(): array` slug => translated label (used by Task 3);
  - `@internal public static function reset_ids(): void` for tests.
- `blocks/event-page/block.json` attributes: `eventId` (int 0), `eventSlug` (string ""), `sections` (string, default `""`, a comma list), `showHeadings` (boolean, true), `speakersColumns` (int 3, min 2, max 4), `sponsorsColumns` (int 4, min 1, max 6), `showSpeakerBio` (boolean, false), `showAvailability` (boolean, false); supports `html:false, className:true`; same `editorScript`/`editorStyle` as the other blocks. The shortcode `[odavent_event_page sections="speakers,agenda" show_headings="no" speakers_columns="2"]` follows automatically from the registry.
- Internal attribute `inEventPage` (bool, not in any `block.json`, so never settable by users): `Event_Page` adds it to every section's attributes. Consumers: `Register_Button` (returns `''` for a stale view, the page prints the stale notice once), `Tickets` (empty `message` and `stale_notice` when the page already shows a lifecycle or stale notice; the "Registration is closed." status of a live event stays), `Countdown` (returns `''` when the computed state is `ended`, or the event is paused).
- Template `event-page` receives `$classes`, `$event_id`, `$stale_notice` (HTML, `''` when fresh), `$sections` (rows `slug`, `id`, `title`, `html`), `$show_headings` (bool), `$view`.
- `Block_Registry`: `NOTICE_BLOCKS` gains `event-page` (the lifecycle notice appears once above the whole page); the script enqueue becomes "for every entry of `SCRIPTS`, enqueue when its marker is in the final HTML" (not only for the block's own slug), so an event page that contains an agenda filter or countdown loads their scripts.

Rules to implement (decisions): headings only for `tickets`, `speakers`, `agenda`, `sponsors` ("Sponsors and partners"), `venue`; a section whose renderer returns `''` is dropped with its heading and wrapper; pass-throughs are `speakersColumns`→speakers `columns`, `showSpeakerBio`→speakers `showBio`, `sponsorsColumns`→sponsors `columns`, `showAvailability`→tickets; section wrapper `<div class="odv-event-page__section odv-event-page__section--{slug}" id="odv-{slug}-{event_id}">`, a second occurrence of the same id in one request gets `-2`, `-3`; heading `<h2 class="odv-event-page__heading">`. Section renderers are called directly (never `render_block`), so notices, assets and errors stay in the registry; one section throwing is caught per section so the rest still render.

- [ ] **Step 1: Write the failing tests** in `EventPageTest` (use `BlockTestCase`, fixtures `payload-v1-full`, `payload-v1-minimal`):
  `test_default_order_and_ids`, `test_headings_are_translated_and_only_on_the_five_sections`, `test_sections_attribute_limits_and_keeps_fixed_order` (`"agenda,speakers"` renders speakers before agenda), `test_hostile_sections_fall_back_to_the_default_and_never_echo_the_raw_value` (the strings from Review Focus 2), `test_an_event_without_speakers_partners_or_tickets_has_no_empty_heading_or_wrapper` (minimal payload: no `odv-event-page__heading` whose section html is empty, no `id="odv-speakers-`), `test_show_headings_false_removes_all_headings`, `test_second_event_page_gets_unique_ids`, `test_ended_event_through_the_registry_shows_the_ended_notice_exactly_once` (assert `substr_count( $html, 'This event has ended.' ) === 1`, no `Event ended` countdown text), `test_stale_copy_shows_one_stale_notice_and_no_register_link`, `test_live_event_closed_status_is_kept` ("Registration is closed." still shown), `test_one_failing_section_does_not_hide_the_others`, `test_agenda_and_countdown_scripts_are_enqueued_for_the_event_page` (extend `BlockRegistryTest`).
- [ ] **Step 2:** Run `bin/test --filter EventPageTest`; expected: FAIL (class not found).
- [ ] **Step 3:** Implement `Event_Page`, `block.json`, template and the three small `inEventPage` branches and the registry changes listed above.
- [ ] **Step 4:** Run `bin/test --filter 'EventPageTest|BlockRegistryTest|BlockJsonTest|ShortcodesTest|TicketsTest|RegisterButtonTest|CountdownTest|LifecycleMatrixTest'`; all PASS. Break `parse_sections` and the once-only rule once to see two tests fail, then restore.
- [ ] **Step 5:** `bin/test` (7.4) and `PHP_VERSION=8.3 bin/test`; commit (`feat(blocks): event page block and shortcode`).

### Task 3: Event page in the editor, and the pattern

**Files:**
- Modify: `assets/js/editor.js`, `src/Blocks/Block_Registry.php` (`editor_metadata()`, new `register_patterns()`), `src/Plugin.php` (hook it on `init`), `tests/js/editor.test.js`, `tests/Unit/Blocks/EditorScriptTest.php`, `tests/Unit/Blocks/BlockRegistryTest.php`

**Interfaces:**
- Consumes: `Event_Page::section_labels()`, `Event_Page::DEFAULT_SECTIONS` (Task 2).
- Produces: `editor_metadata()` entry for `odavent-events/event-page` gains `sectionChoices` = list of `{ value, label }` in default order. `Block_Registry::register_patterns(): void` registers category `odavent-events` (label "Odavent") and pattern `odavent-events/full-event-page` (title "Full event page") whose content is the serialized comments of the individual blocks in default order (guarded by `function_exists( 'register_block_pattern' )`).
- editor.js: when `meta.sectionChoices` is present, the `sections` string attribute renders as one `ToggleControl` per choice (checked when the effective list includes it; empty string means all on); toggling writes the comma list in default order, writing `''` when every section is on.

- [ ] **Step 1: Write the failing tests:** JS `test_sections_toggles_write_a_comma_list_in_default_order` and `test_all_sections_on_writes_an_empty_string`; PHP `test_editor_metadata_lists_section_choices_for_the_event_page_only`, `test_pattern_registers_once_and_contains_every_individual_block`, `test_no_pattern_function_means_no_error`.
- [ ] **Step 2:** `bin/test-js` and `bin/test --filter 'EditorScriptTest|BlockRegistryTest'`; expected FAIL.
- [ ] **Step 3:** Implement the metadata, the pattern registration and the editor control.
- [ ] **Step 4:** `bin/lint-js`, `bin/test-js`, `bin/test`, `PHP_VERSION=8.3 bin/test` all green; commit (`feat(editor): section toggles and a full-event-page pattern`).

### Task 4: Widgets

**Files:**
- Create: `src/Widgets/Widgets.php`, `src/Widgets/Block_Widget.php`, `tests/Unit/Widgets/WidgetsTest.php`
- Modify: `src/Plugin.php` (register on `widgets_init`), `src/Shortcodes/Shortcodes.php` (only if a public sanitising method is needed), `tests/Support/WpStubs.php` (a minimal `WP_Widget` stub if absent)

**Interfaces:**
- Produces: `final class Widgets` with `__construct( Block_Registry $blocks, Shortcodes $shortcodes )`, `register(): void` (hooks `widgets_init`, calls `register_widget( new Block_Widget( $slug, ... ) )` for every slug in `$blocks->renderers()`), and `public static function id_bases(): array` (all widget id bases, `odavent_events_` + slug with `-` as `_`, used by Task 5).
- `class Block_Widget extends \WP_Widget`: name "Odavent: {block title}", id base as above, `widget_options` classname `odavent_events_widget`; `form()` shows optional title, an event field (select from the cached event list when the connection has one, otherwise a number field; empty means the default event) and one control per block attribute from `block.json` (checkbox, number, text, select), labelled with the same labels as the editor; `update()` returns attributes cast and clamped by the shortcode rules (`Shortcodes::to_block_attributes`) plus a sanitised `title` (`sanitize_text_field`); an unchecked checkbox is stored as `false`, never as the default; `widget()` re-sanitises the stored instance, renders through `Block_Registry::render_block()`, and prints `before_widget`/`after_widget` and the title **only if the block html is not `''`**.

- [ ] **Step 1: Write the failing tests** (`WidgetsTest`): `test_one_widget_per_block_including_the_event_page`, `test_update_casts_and_clamps_like_the_shortcodes` (`columns` `"99"` becomes the clamp, `"abc"` dropped), `test_unchecked_checkbox_is_saved_as_false_not_the_default` (speakers `showCompany` default true, absent from POST, saved false), `test_malformed_saved_instance_renders_defaults_or_nothing` (missing keys, array where a string is expected, `eventId` of an unknown event), `test_widget_with_nothing_to_show_prints_no_wrapper_and_no_title`, `test_title_is_escaped`, `test_form_has_a_control_for_every_block_attribute_and_none_for_the_managed_ones`.
- [ ] **Step 2:** `bin/test --filter WidgetsTest`; FAIL.
- [ ] **Step 3:** Implement both classes and wire `Plugin::boot()` (`add_action( 'widgets_init', ... )` through `Widgets::register()`).
- [ ] **Step 4:** Full `bin/test` on 7.4 and 8.3; commit (`feat(widgets): a classic widget for every block`).

### Task 5: Widgets and the page cache, previews and styles

**Files:**
- Modify: `src/Refresh/Page_Cache_Purger.php`, `src/Blocks/Block_Registry.php` (`page_has_odavent_content`, `prevent_preview_caching`), `docs/hooks.md`, `tests/Unit/Refresh/PageCachePurgerTest.php` (and the registry test)

**Interfaces:**
- Consumes: `Widgets::id_bases()` (Task 4).
- Produces: `Page_Cache_Purger::purge_event()` also calls a whole-site purge when an Odavent widget is active and `apply_filters( 'odavent_events_purge_all_on_widget', true, $event_id )` is true: the first existing of `rocket_clean_domain`, `w3tc_flush_all`, `wp_cache_clear_cache`, plus `do_action( 'litespeed_purge_all' )` and `do_action( 'odavent_events_purge_all' )`. "Active" is `is_active_widget( false, false, $id_base, true )` for any base. `Block_Registry::page_has_odavent_content()` returns true (through the same filter) when an Odavent widget is active, on singular and non-singular pages alike, so editors' previews are not cached and the stylesheet loads early. Both new hooks are documented in `docs/hooks.md` (the docs test enforces it).

- [ ] **Step 1: Write the failing tests:** `test_an_active_widget_purges_the_whole_site_cache`, `test_the_filter_can_turn_the_whole_site_purge_off`, `test_no_widget_means_no_whole_site_purge`, `test_a_widget_makes_a_non_singular_page_count_as_odavent_content`, `test_editor_on_a_page_with_a_widget_gets_no_cache_constant_before_theme`.
- [ ] **Step 2:** `bin/test --filter 'PageCachePurgerTest|BlockRegistryTest|DocsTest'`; FAIL.
- [ ] **Step 3:** Implement and document the two hooks.
- [ ] **Step 4:** Full runs on 7.4 and 8.3; commit (`fix(cache): widgets are purged, previewed and styled like content`).

### Task 6: Appearance values, CSS output and storage

**Files:**
- Create: `src/Settings/Appearance.php`, `tests/Unit/Settings/AppearanceTest.php`
- Modify: `src/Settings/Options.php` (`defaults()`, `sanitize()`, new `appearance(): array`), `src/Render/Assets.php` (`register_styles()` adds the inline style), `docs/hooks.md`, `tests/Unit/Settings/OptionsTest.php`, `tests/Unit/Render/AssetsTest.php`

**Interfaces:**
- Produces: `final class Appearance` with
  - `const DEFAULTS = array( 'accent' => '', 'contrast' => '', 'text' => '', 'border' => '', 'radius' => '', 'spacing' => '', 'button_style' => 'filled' )` (empty means "not changed by the organizer");
  - `public static function sanitize( $input ): array` (hex via `sanitize_hex_color` then lower-cased six-digit form, `radius` int 0 to 24 or `''`, `spacing` one of `compact|comfortable|roomy|''`, `button_style` one of `filled|outline`, everything else dropped);
  - `public static function css( array $appearance ): string` (`''` when nothing is set; otherwise `:root{--odv-accent:#...;...}` for the changed fields only, `--odv-accent-contrast` computed automatically from the accent when `contrast` is empty, `--odv-gap` 0.75rem/1rem/1.5rem, `--odv-radius` as `Npx`, plus one rule that makes `.odv-register__button` and `.odv-tickets__register` outlined when `button_style` is `outline`), passed through `apply_filters( 'odavent_events_appearance_css', $css, $appearance )`;
  - `public static function auto_contrast( string $hex ): string` returns `#000000` or `#ffffff`, whichever has the higher WCAG contrast ratio; `public static function contrast_ratio( string $a, string $b ): float`.
- `Options::defaults()` gains `'appearance' => Appearance::DEFAULTS`; `Options::sanitize()` runs `Appearance::sanitize()` on `$input['appearance']` (missing means keep the current values); `Options::appearance(): array`. `Assets::register_styles()` calls `wp_add_inline_style( self::STYLE_HANDLE, $css )` when the default stylesheet is on and `$css !== ''`, so it also reaches the block editor.

- [ ] **Step 1: Write the failing tests:** `AppearanceTest`: `test_hostile_values_are_rejected_or_clamped` (Review Focus 3, with an assertion that `css()` output matches `/^[:a-z0-9#;{}.,\s_>-]*$/i` only for allowed tokens and contains neither `body` nor `url(`), `test_only_changed_fields_are_emitted`, `test_a_light_accent_gets_dark_text_automatically` (`#ffeb3b` gives `#000000`; `#3730a3` gives `#ffffff`), `test_manual_contrast_wins`, `test_spacing_and_radius_map_to_variables`, `test_outline_button_rule`, `test_contrast_ratio_is_the_wcag_formula` (black on white is `21.0`). `OptionsTest`: `test_appearance_survives_a_save_and_a_save_that_omits_it`. `AssetsTest`: `test_inline_style_is_added_only_when_default_styles_are_on_and_something_is_set`.
- [ ] **Step 2:** Run `bin/test --filter 'AppearanceTest|OptionsTest|AssetsTest'`; FAIL.
- [ ] **Step 3:** Implement `Appearance`, extend `Options` and `Assets`; document the filter in `docs/hooks.md`.
- [ ] **Step 4:** Full runs on 7.4 and 8.3; commit (`feat(settings): validated appearance values and their CSS`).

### Task 7: Appearance settings screen

**Files:**
- Create: `assets/js/admin-appearance.js`, `tests/js/admin-appearance.test.js`
- Modify: `src/Settings/Settings_Page.php`, `bin/lint-js` and `bin/test-js` only if they list files explicitly, `tests/Unit/Settings/SettingsPageTest.php`

**Interfaces:**
- Consumes: `Appearance::sanitize()`, `Appearance::auto_contrast()`, `Appearance::contrast_ratio()`, `Options::appearance()`.
- Produces: a settings section "Appearance" (id `odavent_events_appearance`) with fields named `odavent_events_settings[appearance][accent|contrast|text|border|radius|spacing|button_style]`; colour fields use the built-in WordPress colour picker (`wp-color-picker`, enqueued only on this page's `admin_enqueue_scripts` hook) and "Text on accent" has an "Automatic" checkbox; a preview box (class `odv-appearance-preview`) built from the plugin's real classes (a button, a ticket card, a speaker card) that `admin-appearance.js` updates through the same custom properties as they change, and that shows the contrast ratio with a warning text when below 4.5; on save, if the effective accent/contrast ratio is below 4.5, `add_settings_error( ..., 'warning' )` with the ratio, and the values are still saved; when "Load default styles" is off the whole section is rendered disabled with the sentence "Turn on Default styles to use these settings."

- [ ] **Step 1: Write the failing tests:** `SettingsPageTest`: `test_appearance_fields_render_with_saved_values`, `test_fields_are_disabled_when_default_styles_are_off`, `test_low_contrast_saves_with_a_warning_and_a_good_pair_has_none`, `test_color_picker_assets_load_only_on_this_page`, `test_a_hand_made_post_with_hostile_appearance_values_is_sanitised` (through `Settings_Page::sanitize()`). JS: `test_preview_follows_the_inputs` (changing the accent input updates `--odv-accent` on the preview and the ratio text), `test_automatic_text_color_tracks_the_accent`.
- [ ] **Step 2:** `bin/test --filter SettingsPageTest` and `bin/test-js`; FAIL.
- [ ] **Step 3:** Implement the section, preview markup, admin script (plain JS like `assets/js/countdown.js`, no build) and the enqueue.
- [ ] **Step 4:** `bin/lint-js`, `bin/test-js`, full PHP runs on 7.4 and 8.3; commit (`feat(settings): appearance screen with live preview`).

### Task 8: Broken images degrade

**Files:**
- Create: `assets/js/image-fallback.js`, `tests/js/image-fallback.test.js`
- Modify: `templates/speakers.php`, `templates/sponsors.php`, `templates/event-header.php`, `src/Render/Assets.php` (`SCRIPTS`), `src/Blocks/Block_Registry.php` (`SCRIPTS` entry), `docs/theming.md` (template notes), tests for the three renderers

**Interfaces:**
- Produces: every `<img>` printed by those templates carries `data-odv-img="photo|logo|cover"`; speaker photos also `data-odv-initials`. `image-fallback.js` (deferred, no dependencies, loaded only when the HTML contains `data-odv-img`) listens to `error` in the capture phase on `document` and also handles images that already failed before it ran (`complete && naturalWidth === 0`): `photo` becomes the existing `odv-speakers__photo odv-speakers__photo--placeholder` span with the initials, `logo` of a sponsor becomes `<span class="odv-sponsors__name">` with the `alt` text, a header `logo` or `cover` is removed. No inline handlers.

- [ ] **Step 1: Write the failing tests:** JS `test_failed_speaker_photo_becomes_the_placeholder`, `test_failed_sponsor_logo_becomes_the_name`, `test_failed_cover_is_removed`, `test_image_that_failed_before_the_script_ran_is_handled`, `test_working_image_is_untouched`; PHP: templates print `data-odv-img`, and `test_script_is_enqueued_only_when_an_image_is_rendered`.
- [ ] **Step 2:** `bin/test-js` and the renderer tests; FAIL.
- [ ] **Step 3:** Implement the script, the attributes and the enqueue marker.
- [ ] **Step 4:** `bin/lint-js`, `bin/test-js`, full PHP runs; commit (`feat(images): fall back gracefully when an image cannot load`).

### Task 9: Documentation, strings and packaging

**Files:**
- Modify: `README.md` (add "For organizers" quick start and "For webmasters" pointers), `docs/shortcodes.md` (`[odavent_event_page]`), `docs/theming.md` (Appearance screen, precedence, `event-page` template and classes `.odv-event-page*`, the new image attribute), `docs/hooks.md`, `readme.txt`, `CHANGELOG.md` (`## [Unreleased]` with Added/Fixed), `languages/odavent-events.pot`, `languages/odavent-events-fr_FR.po`, `tests/Unit/Packaging/PackagingTest.php`, `tests/Unit/Packaging/ReleaseMetadataTest.php` if they reject an Unreleased section
- Create: `docs/widgets.md`

**Interfaces:** `docs/widgets.md` lists every widget (name, id base, the attributes it accepts, the same table as the matching shortcode), the block-theme caveat (no widget areas, use the blocks) and the cache behaviour from Task 5; `DocsTest` and `ShortcodesTest` must keep passing, with the docs list in `DocsTest` extended to include `docs/widgets.md`.

- [ ] **Step 1: Write the failing tests:** extend `DocsTest` (widgets doc exists, every widget id base appears in it, theming doc mentions `event-page` and `.odv-event-page__heading`), `ShortcodesTest` (the `odavent_event_page` section exists in `docs/shortcodes.md` with every attribute), `PackagingTest` (the built zip contains `blocks/event-page/block.json`, `assets/js/admin-appearance.js`, `assets/js/image-fallback.js`, `templates/event-page.php`).
- [ ] **Step 2:** `bin/test --filter 'DocsTest|ShortcodesTest|PackagingTest|ReleaseMetadataTest'`; FAIL.
- [ ] **Step 3:** Write the docs in plain language: the organizer part has no code (install, connect, add the Event page block or pattern, set colours, where to find widgets); the webmaster part has the tables. Run `bin/make-pot.sh`, translate every new string to French, run `php bin/check-translations.php`.
- [ ] **Step 4:** Full PHP runs on 7.4 and 8.3, `bin/test-js`, `bin/lint-js`, `bin/build-zip` (zip builds); commit (`docs: event page, widgets and appearance`).

### Task 10: Real-WordPress verification, sandbox and final review

**Files:**
- Modify: `bin/smoke.sh`, `docker/stub/*` (a fixture event with a partner of type `both`, a broken image URL, a cancelled twin), `tests/e2e/specs/frontend.spec.js`, `tests/e2e/specs/editor.spec.js`, `tests/e2e/specs/helpers.js`

- [ ] **Step 1: Extend the Docker smoke test** (new numbered section, same `check` style): an `event-page` page renders every default section and exactly one lifecycle notice for the cancelled twin; no Register link anywhere on it; the `both` heading reads "Sponsors and exhibitors"; a widget placed in a sidebar renders for a live event and, after the stub flips the event to cancelled and a refresh ping is sent, a cached page no longer shows a Register link; saving Appearance through `options.php` with hostile values keeps the stored values valid and the front end contains `--odv-accent` only for the valid one; no PHP warnings in `debug.log` after all of it.
- [ ] **Step 2: Extend the Playwright e2e:** insert the Event page block in the editor and toggle a section off (the preview updates); the pattern inserts; the Appearance preview follows the colour input; the front-end `image-fallback` swaps a broken sponsor logo for the name.
- [ ] **Step 3:** Run `bin/dev-up`, `bin/smoke.sh`, `bin/e2e` on WP 7.1, 6.9 and 6.1 as in 0.1.0; every check PASS. Fix what fails (new failing test first) and re-run.
- [ ] **Step 4:** Build the zip and install it into the owner's sandbox: `cd /Users/marceldjaman/code/odavent-wordpress && bin/build-zip`, then in `/Users/marceldjaman/code/odavent-wp-sandbox` run `docker compose exec -T wpcli wp --path=/var/www/html plugin install /zips/odavent-events-0.1.0.zip --force --activate` (settings and key are kept) and create a test page: `./add-event-page.sh` is for the ten blocks; add one page with `<!-- wp:odavent-events/event-page {"eventSlug":"annual-tech-summit-2026"} /-->`.
- [ ] **Step 5:** Final whole-branch review by a fresh reviewer (most capable model) against the spec and this plan; fix Critical and Important findings with tests; commit; report to the owner with the sandbox links. Do not push, tag or open a PR until the owner says so.

---

## Self-review

- **Spec coverage:** event page block, shortcode, headings, once-only notices, countdown, pattern (Tasks 2, 3); widgets and their cache/preview/style effects (Tasks 4, 5); Appearance with auto contrast, warning, disabled state, preview, filter (Tasks 6, 7); "Both" label (Task 1); broken images (Task 8); docs, French strings, changelog, packaging (Task 9); real-WordPress proof and the sandbox install (Task 10). Non-goals are untouched.
- **Types:** `parse_sections`, `section_labels`, `id_bases`, `Appearance::sanitize/css/auto_contrast/contrast_ratio`, `inEventPage` and the option key `appearance` are named identically wherever they are used.
- **Open for the owner, not blocking the build:** which release number carries this work (Task 1 is shippable as 0.1.0 on its own branch).

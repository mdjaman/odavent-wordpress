# Hooks

Every filter and action the plugin offers. All names start with `odavent_events_`. Nothing here is
required: the defaults are right for most sites.

Put the code in a small must-use plugin or your child theme's `functions.php`.

| Hook | Type | Purpose |
|---|---|---|
| [`odavent_events_allowed_html`](#odavent_events_allowed_html) | filter | Tags and attributes kept in organizer-written text. |
| [`odavent_events_locale`](#odavent_events_locale) | filter | Language requested from Odavent. |
| [`odavent_events_now`](#odavent_events_now) | filter | The current time used by the countdown. |
| [`odavent_events_page_has_content`](#odavent_events_page_has_content) | filter | Whether a page uses Odavent content (early stylesheet, no page cache for editors). |
| [`odavent_events_preview_capability`](#odavent_events_preview_capability) | filter | Who sees draft previews and editor notices. |
| [`odavent_events_purge_all_on_widget`](#odavent_events_purge_all_on_widget) | filter | Whether the whole-site page cache is purged while an Odavent widget is active. |
| [`odavent_events_purge_post_ids`](#odavent_events_purge_post_ids) | filter | Which posts have their page cache purged when an event changes. |
| [`odavent_events_template`](#odavent_events_template) | filter | The template file used for a block. |
| [`odavent_events_update_package_prefix`](#odavent_events_update_package_prefix) | filter | Where plugin update packages may come from. |
| [`odavent_events_view_data`](#odavent_events_view_data) | filter | The variables handed to a template. |
| [`odavent_events_purge_all`](#odavent_events_purge_all) | action | Purge the whole site in a page cache the plugin does not know. |
| [`odavent_events_purge_post`](#odavent_events_purge_post) | action | Purge a post in a page cache the plugin does not know. |
| [`odavent_events_refreshed`](#odavent_events_refreshed) | action | A signed refresh from Odavent was processed. |

## Filters

### `odavent_events_allowed_html`

```php
apply_filters( 'odavent_events_allowed_html', array $allowed ): array
```

The tags and attributes kept in text the organizer wrote on Odavent (event description, session and
speaker bios, ticket and sponsor descriptions). Same format as the `$allowed_html` argument of
`wp_kses()`.

Default: `p`, `br`, `strong`, `em`, `b`, `i`, `ul`, `ol`, `li`, `h3`, `h4`, `h5`, and `a` with `href` and
`rel`. A non-array return value is ignored.

Whatever you return, links are always rebuilt afterwards: the `href` must be an absolute `http://` or
`https://` URL, `target` is removed and `rel="nofollow noopener"` is forced. Plain-text fields (names,
titles) never go through this filter; they have every tag removed.

```php
add_filter( 'odavent_events_allowed_html', function ( array $allowed ): array {
	$allowed['blockquote'] = array();
	$allowed['h2']         = array();

	return $allowed;
} );
```

### `odavent_events_locale`

```php
apply_filters( 'odavent_events_locale', ?string $locale, string $wp_locale ): ?string
```

The language of the event content requested from Odavent (event names, descriptions, session titles...).
`$locale` is the default mapping and `$wp_locale` the WordPress locale it came from (`fr_FR`, `en_GB`,
`de_DE`...).

Default: `fr_*` becomes `fr`, `en_*` becomes `en`, any other locale becomes `null`, which asks for the
organization's default language on Odavent.

**Only `en` and `fr` are accepted.** Any other value you return (another language code, an empty string,
a non-string) is treated as `null`, the default language. This keeps the set of cached copies small and
purgeable. Use it so a multilingual plugin decides the language of a page:

```php
// WPML: follow the language of the page being viewed.
add_filter( 'odavent_events_locale', function ( ?string $locale ): ?string {
	$language = apply_filters( 'wpml_current_language', null );

	return in_array( $language, array( 'en', 'fr' ), true ) ? $language : $locale;
} );
```

### `odavent_events_now`

```php
apply_filters( 'odavent_events_now', int $timestamp ): int
```

The current Unix time, used by the countdown block to decide between upcoming, live and ended, and by the
refresh endpoint to judge how fresh a signed ping is. Default: `time()`. An integer (or digit string) is
accepted; anything else (a float, a word, an empty or negative string, `null`) falls back to `time()`, in the
countdown and in the refresh endpoint alike.

It exists for tests and for freezing the time in a demo. Do not use it to move the clock in production:
the refresh endpoint rejects pings whose timestamp is more than 5 minutes away from this value.

```php
// Preview the "live" state of the countdown on a staging site.
add_filter( 'odavent_events_now', function (): int {
	return strtotime( '2027-03-12 10:00:00 UTC' );
} );
```

### `odavent_events_page_has_content`

```php
apply_filters( 'odavent_events_page_has_content', bool $has, ?WP_Post $post ): bool
```

Whether the current page contains Odavent blocks or shortcodes. The plugin uses the answer to load its
stylesheet in the page head (so blocks do not flash unstyled) and to send no-cache headers to editors
who may see a draft preview.

`$has` is `true` when the current singular post's own content contains an `odavent-events/` block or an
`[odavent_` shortcode, or when a widget area holds Odavent content: one of the plugin's classic widgets, or
an Odavent block or shortcode inside a block widget, a Text widget or a Custom HTML widget placed in an
active sidebar (widgets can show on any page, so this holds on non-singular pages too). `$post` is the
current singular post, or `null` when the request is not a singular page.

Widgets also matter for the page cache: while such a widget is active, a refresh from Odavent purges the
whole-site cache (see [`odavent_events_purge_all_on_widget`](#odavent_events_purge_all_on_widget)).

It does **not** detect blocks that live in a block theme's templates or template parts (header, footer,
sidebar parts of the Site Editor), in synced patterns, or in custom fields such as ACF. Return `true` for
those pages:

```php
add_filter( 'odavent_events_page_has_content', function ( bool $has, $post ): bool {
	// The site's header template part shows the countdown on every page.
	return $has || ! is_admin();
}, 10, 2 );
```

Without it the stylesheet is still loaded when a block renders (a little later in the page), and the
page-cache protection for editors does not apply. See [operations.md](operations.md).

### `odavent_events_preview_capability`

```php
apply_filters( 'odavent_events_preview_capability', string $capability ): string
```

The capability a logged-in user needs to see draft previews and editor notices in blocks, and to list
Odavent events in the block editor. Default: `edit_posts`, which includes Contributors. A value that is
not a non-blank string is ignored.

```php
// Only people who can publish may see unpublished events.
add_filter( 'odavent_events_preview_capability', function (): string {
	return 'publish_pages';
} );
```

See [security.md](security.md#draft-previews).

### `odavent_events_purge_post_ids`

```php
apply_filters( 'odavent_events_purge_post_ids', int[] $post_ids, int $event_id ): int[]
```

The posts whose cached page is purged when a signed refresh arrives for `$event_id`. `$post_ids` holds
the published posts of public post types whose content contains an Odavent block or shortcode (at most
500). Add posts that show Odavent content without containing it themselves, or narrow the list. Returned
values are cleaned to unique positive integers; anything else is dropped.

```php
add_filter( 'odavent_events_purge_post_ids', function ( array $post_ids, int $event_id ): array {
	// The home page shows a countdown from a block-theme template part.
	$post_ids[] = (int) get_option( 'page_on_front' );

	return $post_ids;
}, 10, 2 );
```

### `odavent_events_purge_all_on_widget`

```php
apply_filters( 'odavent_events_purge_all_on_widget', bool $purge, int $event_id ): bool
```

A widget is not post content and can show on any page, so while a sidebar holds Odavent content (one of
the plugin's classic widgets, or an Odavent block or shortcode in a block, Text or Custom HTML widget) the plugin purges the whole-site page cache when a signed refresh arrives for `$event_id`
(WP Rocket `rocket_clean_domain()`, else W3 Total Cache `w3tc_flush_all()`, else WP Super Cache
`wp_cache_clear_cache()`, plus the `litespeed_purge_all` action and
[`odavent_events_purge_all`](#odavent_events_purge_all)). Default: `true`. Return `false` when the
widgets sit in an area your cache never stores, or when you would rather purge by hand.

```php
add_filter( 'odavent_events_purge_all_on_widget', '__return_false' );
```

### `odavent_events_template`

```php
apply_filters( 'odavent_events_template', string $path, string $name ): string
```

The template file used to render a block. `$name` is the template name (`speakers`, `tickets`...) and
`$path` the file found by the normal lookup (child theme, parent theme, plugin). Return another absolute
path to use a different file. If the result is not a readable file nothing is rendered.

The path is trusted developer input: never build it from request data. In most cases a file in your theme
at `odavent/{name}.php` is simpler, see [theming.md](theming.md).

```php
add_filter( 'odavent_events_template', function ( string $path, string $name ): string {
	return 'tickets' === $name ? __DIR__ . '/parts/odavent-tickets.php' : $path;
}, 10, 2 );
```

### `odavent_events_update_package_prefix`

```php
apply_filters( 'odavent_events_update_package_prefix', string $prefix ): string
```

The address every update package must start with. Default:
`https://github.com/mdjaman/odavent-wordpress/releases/download/`. Only for mirrors or forks that publish
their own releases. A value that is not a well-formed `https://host/path/` prefix (trailing slash, at
least one path segment) is ignored and the default is used.

The package address must still be exactly `{prefix}v{version}/{file}.zip` for the version offered, see
[security.md](security.md#updates).

```php
add_filter( 'odavent_events_update_package_prefix', function (): string {
	return 'https://github.com/my-fork/odavent-wordpress/releases/download/';
} );
```

### `odavent_events_view_data`

```php
apply_filters( 'odavent_events_view_data', array $vars, string $name ): array
```

The variables a template receives, as `name => value`. `$name` is the template name. Use it to add
variables to your own template override, or to adjust what is displayed. A non-array return value is
ignored. Names that are not valid PHP variable names, `this`, and names starting with `__` are dropped.
The filter runs after the template file is chosen, and only when a template exists.

Values in `$vars` are already cleaned for output; keep escaping what you print.

```php
add_filter( 'odavent_events_view_data', function ( array $vars, string $name ): array {
	if ( 'speakers' === $name ) {
		// Put featured speakers first.
		usort( $vars['speakers'], function ( $a, $b ) {
			return (int) $b['featured'] <=> (int) $a['featured'];
		} );
	}

	return $vars;
}, 10, 2 );
```

## Actions

### `odavent_events_purge_all`

```php
do_action( 'odavent_events_purge_all' )
```

Fires once after an Odavent event changed while an Odavent widget is active (unless
[`odavent_events_purge_all_on_widget`](#odavent_events_purge_all_on_widget) turned it off), after the
built-in whole-site purges. Use it to purge a page cache or CDN the plugin does not know.

```php
add_action( 'odavent_events_purge_all', function (): void {
	my_cdn_purge_everything();
} );
```

### `odavent_events_purge_post`

```php
do_action( 'odavent_events_purge_post', int $post_id )
```

Fires once for each post purged after an Odavent event changed (after the built-in purges for WP Rocket,
W3 Total Cache, WP Super Cache and LiteSpeed Cache). Use it to purge a page cache or CDN the plugin does
not know.

```php
add_action( 'odavent_events_purge_post', function ( int $post_id ): void {
	my_cdn_purge_url( get_permalink( $post_id ) );
} );
```

### `odavent_events_refreshed`

```php
do_action( 'odavent_events_refreshed', int $event_id, string $slug )
```

Fires after a signed refresh from Odavent has dropped the cached copy of an event and purged the pages
using it. `$slug` is the sanitised event slug, or an empty string when Odavent did not send one. It fires
only for requests that passed signature verification.

```php
add_action( 'odavent_events_refreshed', function ( int $event_id, string $slug ): void {
	error_log( "Odavent event {$event_id} ({$slug}) refreshed" );
}, 10, 2 );
```

## Hooks of other plugins the plugin calls

For completeness, when an event changes the plugin calls these if they exist: `rocket_clean_post()`
(WP Rocket), `w3tc_flush_post()` (W3 Total Cache), `wp_cache_post_change()` (WP Super Cache), and the
`litespeed_purge_post` action (LiteSpeed Cache), each with the post ID, after `clean_post_cache()`. While an
Odavent widget is active it also calls the first of `rocket_clean_domain()`, `w3tc_flush_all()` and
`wp_cache_clear_cache()` that exists, and fires the `litespeed_purge_all` action.

<?php
namespace Odavent\Events\Data;

/**
 * Maps the WordPress locale to an Odavent content locale.
 *
 * Odavent serves event content in `en` and `fr`. Anything else asks for the organization's
 * default language (no locale sent), cached under the `default` key part.
 */
final class Locale {

	/** Content locales Odavent serves. */
	const SUPPORTED = array( 'en', 'fr' );

	/** Cache key part used when no supported locale applies. */
	const DEFAULT_KEY = 'default';

	/**
	 * The locale to request from Odavent, or null for the organization's default language.
	 *
	 * `fr_*` -> `fr`, `en_*` -> `en`, anything else -> null. Multilingual plugins (WPML, Polylang)
	 * can return the page language through the `odavent_events_locale` filter; a value outside
	 * SUPPORTED counts as null, so the cache keys stay within a known, purgeable set.
	 */
	public static function current(): ?string {
		$wp_locale = '';
		if ( function_exists( 'determine_locale' ) ) {
			$wp_locale = determine_locale();
		} elseif ( function_exists( 'get_locale' ) ) {
			$wp_locale = get_locale();
		}
		$wp_locale = is_string( $wp_locale ) ? $wp_locale : '';

		$locale = apply_filters( 'odavent_events_locale', self::map( $wp_locale ), $wp_locale );

		return is_string( $locale ) && in_array( $locale, self::SUPPORTED, true ) ? $locale : null;
	}

	/**
	 * Maps a WordPress locale (fr_FR, en_GB, de_DE) to a supported content locale.
	 *
	 * @param string $wp_locale WordPress locale.
	 */
	public static function map( string $wp_locale ): ?string {
		foreach ( self::SUPPORTED as $supported ) {
			if ( $wp_locale === $supported || 0 === strpos( $wp_locale, $supported . '_' ) ) {
				return $supported;
			}
		}

		return null;
	}

	/**
	 * The locale part of cache keys for the current request.
	 */
	public static function key_part(): string {
		$locale = self::current();

		return null === $locale ? self::DEFAULT_KEY : $locale;
	}

	/**
	 * Every locale part a cache key can carry, for purging.
	 *
	 * @return string[]
	 */
	public static function all_key_parts(): array {
		return array_merge( self::SUPPORTED, array( self::DEFAULT_KEY ) );
	}
}

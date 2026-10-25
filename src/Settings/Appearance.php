<?php
namespace Odavent\Events\Settings;

/**
 * The organizer's Appearance choices (colours, corner radius, spacing, button style) and the CSS they produce.
 *
 * Only validated values ever reach the page: lower-case #rrggbb colours, an integer clamped to 0 to 24, and
 * keywords from fixed lists. No free text is written into CSS, and css() validates again, so stored data
 * from another version or a hand-edited option is never trusted.
 *
 * An empty value means "not changed by the organizer": nothing is emitted for it and the stylesheet's own
 * default (or the webmaster's CSS) applies.
 */
final class Appearance {

	const DEFAULTS = array(
		'accent'       => '',
		'contrast'     => '',
		'text'         => '',
		'border'       => '',
		'radius'       => '',
		'spacing'      => '',
		'button_style' => 'filled',
	);

	const MIN_RADIUS = 0;
	const MAX_RADIUS = 24;

	/** @var string[] Spacing keyword => the --odv-gap value. */
	const GAPS = array(
		'compact'     => '0.75rem',
		'comfortable' => '1rem',
		'roomy'       => '1.5rem',
	);

	const BUTTON_STYLES = array( 'filled', 'outline' );

	/** The stylesheet's own accent (assets/css/odavent.css), which buttons use while no accent is chosen. */
	const DEFAULT_ACCENT = '#3730a3';

	/** Closed button rules are left alone; hover fills the outlined button so its text stays readable. */
	const OUTLINE_CSS = '.odv-register .odv-register__button:not(.odv-register__button--disabled):not([aria-disabled="true"]),.odv-tickets .odv-tickets__register{background:transparent;color:var(--_accent)}'
		. '.odv-register .odv-register__button:not(.odv-register__button--disabled):not([aria-disabled="true"]):hover,.odv-tickets .odv-tickets__register:hover{background:var(--_accent);color:var(--_contrast)}';

	/**
	 * Validates submitted or stored Appearance values. Always returns every key; anything invalid becomes
	 * "not changed" (the radius is clamped instead when it is a number).
	 *
	 * @param mixed $input Raw values.
	 */
	public static function sanitize( $input ): array {
		$out = self::DEFAULTS;
		if ( ! is_array( $input ) ) {
			return $out;
		}

		foreach ( array( 'accent', 'contrast', 'text', 'border' ) as $field ) {
			$out[ $field ] = self::hex( $input[ $field ] ?? '' );
		}

		$out['radius'] = self::radius( $input['radius'] ?? '' );

		$spacing        = $input['spacing'] ?? '';
		$out['spacing'] = is_string( $spacing ) && isset( self::GAPS[ $spacing ] ) ? $spacing : '';

		$style               = $input['button_style'] ?? '';
		$out['button_style'] = is_string( $style ) && in_array( $style, self::BUTTON_STYLES, true ) ? $style : 'filled';

		return $out;
	}

	/**
	 * The CSS for the fields the organizer changed, or '' when none was (the filter is then not applied).
	 *
	 * @param array $appearance Appearance values (validated again here).
	 */
	public static function css( array $appearance ): string {
		$a    = self::sanitize( $appearance );
		$vars = '';

		if ( '' !== $a['accent'] ) {
			$vars .= '--odv-accent:' . $a['accent'] . ';';
		}
		if ( '' !== $a['contrast'] ) {
			$vars .= '--odv-accent-contrast:' . $a['contrast'] . ';';
		} elseif ( '' !== $a['accent'] ) {
			$vars .= '--odv-accent-contrast:' . self::auto_contrast( $a['accent'] ) . ';';
		}
		if ( '' !== $a['text'] ) {
			$vars .= '--odv-text:' . $a['text'] . ';';
		}
		if ( '' !== $a['border'] ) {
			$vars .= '--odv-border:' . $a['border'] . ';';
		}
		if ( '' !== $a['radius'] ) {
			$vars .= '--odv-radius:' . (int) $a['radius'] . 'px;';
		}
		if ( '' !== $a['spacing'] ) {
			$vars .= '--odv-gap:' . self::GAPS[ $a['spacing'] ] . ';';
		}

		$css = '' === $vars ? '' : ':root{' . $vars . '}';
		if ( 'outline' === $a['button_style'] ) {
			$css .= self::OUTLINE_CSS;
		}

		if ( '' === $css ) {
			return '';
		}

		/**
		 * Filters the final Appearance CSS added after the plugin's stylesheet.
		 *
		 * @param string $css        The generated CSS.
		 * @param array  $appearance The validated Appearance values.
		 */
		$filtered = apply_filters( 'odavent_events_appearance_css', $css, $a );

		return is_string( $filtered ) ? $filtered : $css;
	}

	/**
	 * Black or white, whichever has the higher WCAG contrast ratio against the colour.
	 *
	 * @param string $hex #rgb or #rrggbb; anything else gets white.
	 */
	public static function auto_contrast( string $hex ): string {
		$hex = self::hex( $hex );
		if ( '' === $hex ) {
			return '#ffffff';
		}

		return self::contrast_ratio( $hex, '#000000' ) > self::contrast_ratio( $hex, '#ffffff' ) ? '#000000' : '#ffffff';
	}

	/**
	 * WCAG 2.x contrast ratio between two colours, 1.0 to 21.0, rounded to two decimals.
	 * An invalid colour counts as no contrast (1.0).
	 *
	 * @param string $a #rgb or #rrggbb.
	 * @param string $b #rgb or #rrggbb.
	 */
	public static function contrast_ratio( string $a, string $b ): float {
		$a = self::hex( $a );
		$b = self::hex( $b );
		if ( '' === $a || '' === $b ) {
			return 1.0;
		}

		$la = self::luminance( $a );
		$lb = self::luminance( $b );

		return round( ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 ), 2 );
	}

	/**
	 * Lower-case #rrggbb from #rgb or #rrggbb (surrounding whitespace allowed), or ''.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function hex( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}

		if ( 1 !== preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/Di', trim( $value ), $m ) ) {
			return '';
		}

		$digits = strtolower( $m[1] );
		if ( 3 === strlen( $digits ) ) {
			$digits = $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2];
		}

		return '#' . $digits;
	}

	/**
	 * An integer clamped to 0 to 24, or '' when the value is not a plain whole number. A number of any length
	 * is clamped (a long run of digits is just "very large"), never rejected.
	 *
	 * @param mixed $value Raw value.
	 * @return int|string
	 */
	private static function radius( $value ) {
		if ( is_int( $value ) ) {
			$number = $value;
		} elseif ( is_string( $value ) && 1 === preg_match( '/^\s*(-?)(\d+)\s*$/D', $value, $m ) ) {
			$digits = ltrim( $m[2], '0' );
			if ( strlen( $digits ) > 9 ) {
				// Beyond what an integer holds on every platform: clamp by sign instead of casting.
				return '-' === $m[1] ? self::MIN_RADIUS : self::MAX_RADIUS;
			}
			$number = (int) ( $m[1] . ( '' === $digits ? '0' : $digits ) );
		} else {
			return '';
		}

		return max( self::MIN_RADIUS, min( self::MAX_RADIUS, $number ) );
	}

	/**
	 * WCAG relative luminance of a #rrggbb colour.
	 */
	private static function luminance( string $hex ): float {
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
}

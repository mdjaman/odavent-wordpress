<?php
namespace Odavent\Events\Render;

/**
 * Dates and prices as a visitor reads them: in the event's timezone, with WordPress's date settings.
 *
 * Dates arrive as ISO 8601 strings from the network. Anything that is not a plain, valid ISO date-time
 * gives '' (not today's date, and not PHP's lenient "tomorrow").
 */
final class Format {

	/** Currencies whose smallest unit is the whole unit (no decimals). */
	const ZERO_DECIMAL_CURRENCIES = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:?\d{2})?$/D';

	/** @var bool Tests only: pretend the intl extension is missing. */
	private static $intl_disabled = false;

	/**
	 * @internal Tests only.
	 *
	 * @param bool $disabled Whether to skip NumberFormatter.
	 */
	public static function disable_intl( bool $disabled ): void {
		self::$intl_disabled = $disabled;
	}

	/**
	 * A date and time in the event's timezone.
	 *
	 * @param mixed  $iso      ISO 8601 date-time (anything but a valid string gives '').
	 * @param mixed  $timezone IANA timezone of the event (anything invalid: UTC).
	 * @param string $format   PHP date format; '' for the site's date and time formats.
	 */
	public static function datetime( $iso, $timezone, string $format = '' ): string {
		$moment = self::parse( $iso );
		if ( null === $moment ) {
			return '';
		}

		if ( '' === $format ) {
			$format = self::date_format() . ' ' . self::time_format();
		}

		return self::render( $format, $moment, $timezone );
	}

	/**
	 * "Y-m-d" of the moment in the event's timezone, to group sessions by local day.
	 *
	 * @param mixed $iso      ISO 8601 date-time (anything but a valid string gives '').
	 * @param mixed $timezone IANA timezone of the event (anything invalid: UTC).
	 */
	public static function day_key( $iso, $timezone ): string {
		$moment = self::parse( $iso );

		return null === $moment ? '' : self::render( 'Y-m-d', $moment, $timezone );
	}

	/**
	 * The event's dates for display: one date with a time range on a single day, otherwise "start – end".
	 *
	 * @param array $event The payload's `event` object (starts_at, ends_at, timezone).
	 */
	public static function date_range( array $event ): string {
		$timezone = isset( $event['timezone'] ) && is_string( $event['timezone'] ) ? $event['timezone'] : 'UTC';
		$starts   = self::iso_of( $event, 'starts_at' );
		$start    = self::parse( $starts );
		if ( null === $start ) {
			return '';
		}

		$full = self::date_format() . ' ' . self::time_format();
		$ends = self::iso_of( $event, 'ends_at' );
		$end  = self::parse( $ends );
		if ( null === $end ) {
			return self::render( $full, $start, $timezone );
		}

		if ( self::render( 'Y-m-d', $start, $timezone ) === self::render( 'Y-m-d', $end, $timezone ) ) {
			return sprintf(
				/* translators: 1: date, 2: start time, 3: end time (one day event). */
				__( '%1$s, %2$s – %3$s', 'odavent-events' ),
				self::render( self::date_format(), $start, $timezone ),
				self::render( self::time_format(), $start, $timezone ),
				self::render( self::time_format(), $end, $timezone )
			);
		}

		return sprintf(
			/* translators: 1: start date and time, 2: end date and time. */
			__( '%1$s – %2$s', 'odavent-events' ),
			self::render( $full, $start, $timezone ),
			self::render( $full, $end, $timezone )
		);
	}

	/**
	 * A ticket price: "Free", or the amount with its currency.
	 *
	 * Prices arrive as int or float (JSON drops ".0"), so the value is cast, never assumed a float. Zero-
	 * decimal currencies (XOF, XAF, JPY, KRW...) show no decimals.
	 *
	 * @param int|float $price    Amount in the currency's main unit.
	 * @param string    $currency ISO 4217 code.
	 * @param bool      $is_free  Whether the ticket is flagged free.
	 */
	public static function price( $price, string $currency, bool $is_free ): string {
		$amount = is_numeric( $price ) ? (float) $price : 0.0;

		if ( $is_free || $amount <= 0 ) {
			return __( 'Free', 'odavent-events' );
		}

		if ( ! is_finite( $amount ) ) {
			return '';
		}

		$code = strtoupper( trim( $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $code ) ) {
			$code = '';
		}

		if ( '' !== $code && ! self::$intl_disabled && class_exists( 'NumberFormatter' ) ) {
			$formatter = new \NumberFormatter( (string) get_locale(), \NumberFormatter::CURRENCY );
			$formatted = $formatter->formatCurrency( $amount, $code );
			if ( is_string( $formatted ) && '' !== $formatted ) {
				return $formatted;
			}
		}

		$decimals = in_array( $code, self::ZERO_DECIMAL_CURRENCIES, true ) ? 0 : 2;

		return trim( number_format_i18n( $amount, $decimals ) . ' ' . $code );
	}

	/**
	 * The moment as a DateTimeImmutable in the event's timezone (invalid timezone: UTC), or null.
	 *
	 * @param mixed $iso      ISO 8601 date-time.
	 * @param mixed $timezone IANA timezone of the event.
	 */
	public static function moment( $iso, $timezone ): ?\DateTimeImmutable {
		$moment = self::parse( $iso );
		if ( null === $moment ) {
			return null;
		}

		return $moment->setTimezone( self::zone( $timezone ) );
	}

	/**
	 * @param array  $event Event object.
	 * @param string $key   Key holding an ISO string.
	 */
	private static function iso_of( array $event, string $key ): ?string {
		return isset( $event[ $key ] ) && is_string( $event[ $key ] ) ? $event[ $key ] : null;
	}

	private static function parse( $iso ): ?\DateTimeImmutable {
		if ( ! is_string( $iso ) || ! preg_match( self::ISO_PATTERN, $iso ) ) {
			return null;
		}

		try {
			$moment = new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception $e ) {
			return null;
		}

		// "2026-02-31" is accepted by PHP and rolled over to March: treat the warning as invalid.
		$errors = \DateTimeImmutable::getLastErrors();
		if ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) {
			return null;
		}

		return $moment;
	}

	private static function render( string $format, \DateTimeImmutable $moment, $timezone ): string {
		$text = wp_date( $format, $moment->getTimestamp(), self::zone( $timezone ) );

		return is_string( $text ) ? $text : '';
	}

	private static function zone( $timezone ): \DateTimeZone {
		if ( ! is_string( $timezone ) ) {
			return new \DateTimeZone( 'UTC' );
		}

		try {
			return new \DateTimeZone( $timezone );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}

	private static function date_format(): string {
		$format = get_option( 'date_format' );

		return is_string( $format ) && '' !== $format ? $format : 'F j, Y';
	}

	private static function time_format(): string {
		$format = get_option( 'time_format' );

		return is_string( $format ) && '' !== $format ? $format : 'g:i a';
	}
}

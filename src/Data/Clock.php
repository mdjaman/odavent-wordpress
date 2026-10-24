<?php
namespace Odavent\Events\Data;

/**
 * The plugin's idea of "now": the `odavent_events_now` filter over time(), normalised in one place so
 * the countdown and the refresh endpoint agree on what a bad filter value means.
 */
final class Clock {

	/**
	 * Current Unix time. Filter `odavent_events_now` (int $timestamp) lets tests and caching setups fix it.
	 * An integer or a string of digits is used; anything else (a float, a word, a negative or an empty
	 * string, null, an array) falls back to time().
	 */
	public static function now(): int {
		$now = apply_filters( 'odavent_events_now', time() );

		if ( is_int( $now ) ) {
			return $now;
		}

		return is_string( $now ) && 1 === preg_match( '/^[0-9]{1,18}$/D', $now ) ? (int) $now : time();
	}
}

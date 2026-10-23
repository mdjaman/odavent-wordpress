<?php
namespace Odavent\Events\Refresh;

/**
 * The refresh ping signature, as Odavent's WordpressSite::sign() produces it:
 *
 *     X-Odavent-Signature: t={unix timestamp},v1={hex HMAC-SHA256 of "{t}.{raw body}" keyed by the site key}
 *
 * Parsing is strict and cheap (no hashing), so malformed headers are rejected before any work.
 */
final class Signature {

	/** Seconds a signed timestamp may differ from our clock, in either direction. */
	const TOLERANCE = 300;

	/** Longest header worth looking at; Odavent's is 80 bytes. */
	const MAX_HEADER_LENGTH = 256;

	/**
	 * @param string $body      Raw request body.
	 * @param int    $timestamp Unix time of signing.
	 * @param string $key       Site key.
	 */
	public static function sign( string $body, int $timestamp, string $key ): string {
		return 't=' . $timestamp . ',v1=' . hash_hmac( 'sha256', $timestamp . '.' . $body, $key );
	}

	/**
	 * Splits a header into its timestamp and v1 signature.
	 *
	 * Comma-separated `name=value` pairs; spaces/tabs around pairs and names in any case are
	 * accepted, unknown names are ignored. Null unless there is exactly one `t` (1-12 digits,
	 * no leading zero) and exactly one `v1` (64 lowercase hex characters).
	 *
	 * @param string $header Header value.
	 * @return array{t: int, v1: string}|null
	 */
	public static function parse( string $header ): ?array {
		if ( '' === $header || strlen( $header ) > self::MAX_HEADER_LENGTH ) {
			return null;
		}

		$t  = null;
		$v1 = null;

		foreach ( explode( ',', $header ) as $part ) {
			$pair = explode( '=', trim( $part, " \t" ), 2 );
			if ( 2 !== count( $pair ) ) {
				return null;
			}

			$name  = strtolower( $pair[0] );
			$value = $pair[1];

			if ( 't' === $name ) {
				if ( null !== $t || 1 !== preg_match( '/^[1-9][0-9]{0,11}$/D', $value ) ) {
					return null;
				}
				$t = (int) $value;
			} elseif ( 'v1' === $name ) {
				if ( null !== $v1 || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $value ) ) {
					return null;
				}
				$v1 = $value;
			}
		}

		if ( null === $t || null === $v1 ) {
			return null;
		}

		return array(
			't'  => $t,
			'v1' => $v1,
		);
	}

	/**
	 * Whether the header is a fresh, valid signature of $body under $key.
	 *
	 * Order: parse (cheap), freshness, then the HMAC compared in constant time.
	 *
	 * @param string $header    Header value.
	 * @param string $body      Raw request body, exactly as received.
	 * @param string $key       Site key ('' never verifies).
	 * @param int    $now       Current unix time.
	 * @param int    $tolerance Allowed clock difference in seconds.
	 */
	public static function verify( string $header, string $body, string $key, int $now, int $tolerance = self::TOLERANCE ): bool {
		$parsed = self::parse( $header );
		if ( null === $parsed || '' === $key ) {
			return false;
		}

		if ( abs( $now - $parsed['t'] ) > $tolerance ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $parsed['t'] . '.' . $body, $key );

		return hash_equals( $expected, $parsed['v1'] );
	}
}

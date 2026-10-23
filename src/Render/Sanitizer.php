<?php
namespace Odavent\Events\Render;

/**
 * The plugin's cleaning of organizer-written text, HTML and URLs.
 *
 * Everything in an Odavent payload is untrusted. These methods are the first layer; templates still
 * escape at the point of output (esc_html, esc_attr, esc_url).
 */
final class Sanitizer {

	/** Schemes any link or media URL may use. */
	const PROTOCOLS = array( 'http', 'https' );

	/** Longest URL accepted; longer ones are treated as hostile or broken. */
	const MAX_URL_LENGTH = 4096;

	/**
	 * Plain text: tags removed (script and style with their content), trimmed.
	 *
	 * @param mixed $text Untrusted text (anything but a string or number gives '').
	 */
	public static function text( $text ): string {
		$text = self::string_or_null( $text );
		if ( null === $text || '' === $text ) {
			return '';
		}

		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Organizer HTML reduced to the allow-list. Links get rel="nofollow noopener", lose target, and keep
	 * an href only when it is an absolute http(s) URL.
	 *
	 * @param mixed $html Untrusted HTML (anything but a string or number gives '').
	 */
	public static function html( $html ): string {
		$html = self::string_or_null( $html );
		if ( null === $html || '' === $html ) {
			return '';
		}

		$clean = wp_kses( $html, self::allowed_html(), self::PROTOCOLS );

		$hardened = preg_replace_callback(
			'/<a\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/i',
			array( self::class, 'harden_anchor' ),
			$clean
		);

		return is_string( $hardened ) ? $hardened : '';
	}

	/**
	 * An absolute http(s) URL, or '' for anything else.
	 *
	 * Browsers ignore tabs and newlines inside a URL and trim leading control characters and spaces, so
	 * `java\tscript:` is the same URL as `javascript:`. Tabs and newlines are removed here before the
	 * scheme is checked; any other control character, space, quote, angle bracket or backslash rejects the
	 * URL. Protocol-relative (//host) and relative URLs are rejected: only `http://` and `https://` pass.
	 *
	 * @param mixed $url Untrusted URL (anything but a string gives '').
	 */
	public static function url( $url ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}

		$url = preg_replace( '/[\t\r\n]+/', '', $url );
		$url = trim( (string) $url, "\0..\x20" );

		if ( '' === $url || strlen( $url ) > self::MAX_URL_LENGTH ) {
			return '';
		}

		if ( preg_match( '/[\x00-\x20\x7f"<>\\\\]/', $url ) ) {
			return '';
		}

		if ( ! preg_match( '#^https?://[^/?\#]#i', $url ) ) {
			return '';
		}

		return esc_url_raw( $url, self::PROTOCOLS );
	}

	/**
	 * Payload values come from the network: arrays, booleans and objects are not text.
	 *
	 * @param mixed $value Any payload value.
	 */
	private static function string_or_null( $value ): ?string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_int( $value ) || is_float( $value ) ? (string) $value : null;
	}

	/**
	 * The allowed tags and attributes, filterable. `target` can never be allowed: it is removed from links
	 * afterwards whatever the filter says.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function allowed_html(): array {
		$default = array(
			'p'      => array(),
			'br'     => array(),
			'strong' => array(),
			'em'     => array(),
			'b'      => array(),
			'i'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'a'      => array(
				'href' => true,
				'rel'  => true,
			),
			'h3'     => array(),
			'h4'     => array(),
			'h5'     => array(),
		);

		$allowed = apply_filters( 'odavent_events_allowed_html', $default );

		return is_array( $allowed ) ? $allowed : $default;
	}

	/**
	 * Rebuilds an <a ...> tag from wp_kses output: href re-validated, target and rel dropped, rel forced.
	 *
	 * @param string[] $match preg match: [1] is the attribute string.
	 */
	private static function harden_anchor( array $match ): string {
		$out = '<a';

		preg_match_all(
			'/([a-z_:][-a-z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?/i',
			$match[1],
			$attributes,
			PREG_SET_ORDER
		);

		foreach ( $attributes as $attribute ) {
			$name = strtolower( $attribute[1] );
			if ( 'target' === $name || 'rel' === $name ) {
				continue;
			}

			$value = html_entity_decode( $attribute[2] ?? ( '' !== ( $attribute[3] ?? '' ) ? $attribute[3] : ( $attribute[4] ?? '' ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

			if ( 'href' === $name ) {
				$safe = self::url( $value );
				if ( '' !== $safe ) {
					$out .= ' href="' . esc_url( $safe, self::PROTOCOLS ) . '"';
				}
				continue;
			}

			$out .= ' ' . $name . '="' . esc_attr( $value ) . '"';
		}

		return $out . ' rel="nofollow noopener">';
	}
}

<?php
namespace Odavent\Events\Tests\Support;

use Brain\Monkey\Functions;

/**
 * Test doubles for the WordPress functions the render layer calls, so unit tests run without WordPress.
 *
 * The sanitising doubles are NOT no-ops: wp_kses() really parses the HTML and keeps only the allowed
 * tags and attributes, and esc_url() / esc_url_raw() really reject other schemes. They approximate
 * WordPress; they are not WordPress. What each one approximates is stated on the function. Real
 * behaviour is checked later by the smoke test against a running WordPress.
 */
final class WpStubs {

	/** @var array<string, string> Options returned by get_option(). */
	public static $options = array(
		'date_format' => 'Y-m-d',
		'time_format' => 'H:i',
	);

	/** @var string */
	public static $stylesheet_dir = '/nonexistent/child';

	/** @var string */
	public static $template_dir = '/nonexistent/parent';

	/** @var bool wp_kses() keeps the quote style the author used (single quotes) instead of always writing double quotes. */
	public static $kses_keep_quotes = false;

	/** @var string */
	public static $home = 'https://blog.example.com';

	/**
	 * Installs every double. Call after TestCase::setUp() (it replaces the escape stubs for URLs).
	 */
	public static function install(): void {
		self::$options        = array(
			'date_format' => 'Y-m-d',
			'time_format' => 'H:i',
		);
		self::$stylesheet_dir = '/nonexistent/child';
		self::$template_dir   = '/nonexistent/parent';
		self::$home           = 'https://blog.example.com';
		self::$kses_keep_quotes = false;

		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return self::$options[ $name ] ?? $default;
			}
		);
		Functions\when( 'get_stylesheet_directory' )->alias(
			function () {
				return self::$stylesheet_dir;
			}
		);
		Functions\when( 'get_template_directory' )->alias(
			function () {
				return self::$template_dir;
			}
		);
		Functions\when( 'home_url' )->alias(
			function ( $path = '' ) {
				return rtrim( self::$home, '/' ) . '/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'plugins_url' )->alias(
			function ( $path = '' ) {
				return 'https://blog.example.com/wp-content/plugins/odavent-events/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias(
			function ( $url, $component = -1 ) {
				return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals, '.', ',' );
			}
		);
		Functions\when( 'wp_date' )->alias( array( self::class, 'wp_date' ) );
		Functions\when( 'wp_json_encode' )->alias(
			function ( $data, $options = 0, $depth = 512 ) {
				return json_encode( $data, $options, $depth );
			}
		);
		Functions\when( 'wp_strip_all_tags' )->alias( array( self::class, 'wp_strip_all_tags' ) );
		Functions\when( 'wp_kses' )->alias( array( self::class, 'wp_kses' ) );
		Functions\when( 'esc_url' )->alias(
			function ( $url, $protocols = null ) {
				return self::esc_url( $url, $protocols, 'display' );
			}
		);
		Functions\when( 'esc_url_raw' )->alias(
			function ( $url, $protocols = null ) {
				return self::esc_url( $url, $protocols, 'db' );
			}
		);
		Functions\when( 'add_query_arg' )->alias( array( self::class, 'add_query_arg' ) );
	}

	/**
	 * wp_date(): formats a Unix timestamp in the given timezone. WordPress also translates month and
	 * day names; this double does not (tests use numeric formats).
	 */
	public static function wp_date( $format, $timestamp = null, $timezone = null ) {
		$dt = new \DateTimeImmutable( '@' . (int) $timestamp );
		$dt = $dt->setTimezone( $timezone instanceof \DateTimeZone ? $timezone : new \DateTimeZone( 'UTC' ) );

		return $dt->format( $format );
	}

	/**
	 * Same algorithm as WordPress's wp_strip_all_tags() without remove_breaks.
	 */
	public static function wp_strip_all_tags( $text, $remove_breaks = false ) {
		$text = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );

		return trim( $text );
	}

	/**
	 * Approximates add_query_arg(): existing query values are re-encoded with urlencode(), new values
	 * are inserted AS GIVEN (WordPress does not encode them: callers must), a #fragment is kept last.
	 */
	public static function add_query_arg( $args, $url ) {
		$fragment = '';
		$hash     = strpos( $url, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $url, $hash );
			$url      = substr( $url, 0, $hash );
		}

		$query = '';
		$mark  = strpos( $url, '?' );
		if ( false !== $mark ) {
			$query = substr( $url, $mark + 1 );
			$url   = substr( $url, 0, $mark );
		}

		parse_str( $query, $existing );
		$existing = array_map( 'urlencode', $existing );

		foreach ( $args as $key => $value ) {
			$existing[ $key ] = $value;
		}

		$pairs = array();
		foreach ( $existing as $key => $value ) {
			$pairs[] = $key . '=' . $value;
		}

		return $url . ( $pairs ? '?' . implode( '&', $pairs ) : '' ) . $fragment;
	}

	/**
	 * Approximates esc_url()/esc_url_raw(): drops characters outside WordPress's URL character set (this
	 * removes tabs, newlines, <, > and quotes), adds http:// to scheme-less values, and returns ''
	 * when the scheme is not in $protocols (default http/https/mailto, the common subset of WordPress's
	 * list). 'display' also entity-encodes & and '. Not covered: the %0d/%0a handling and the
	 * IDN/path special cases of the real function.
	 */
	public static function esc_url( $url, $protocols = null, $context = 'display' ) {
		$url = (string) $url;
		if ( '' === $url ) {
			return '';
		}

		$url = str_replace( ' ', '%20', ltrim( $url ) );
		$url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\x80-\\xff]|i', '', $url );
		if ( '' === $url ) {
			return '';
		}

		$url = str_replace( ';//', '://', $url );

		if ( false === strpos( $url, ':' ) && ! in_array( $url[0], array( '/', '#', '?' ), true ) ) {
			$url = 'http://' . $url;
		}

		if ( 'display' === $context ) {
			$url = str_replace( array( '&amp;', '&' ), array( '&', '&#038;' ), $url );
			$url = str_replace( "'", '&#039;', $url );
		}

		$protocols = is_array( $protocols ) ? $protocols : array( 'http', 'https', 'mailto' );

		return self::bad_protocol( $url, $protocols ) ? '' : $url;
	}

	/**
	 * Whether a URL has a scheme that is not allowed (entities decoded, whitespace and control
	 * characters removed first, as browsers and wp_kses_bad_protocol() do).
	 */
	private static function bad_protocol( string $url, array $protocols ): bool {
		$decoded = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$decoded = preg_replace( '/[\x00-\x20\x7f]+/', '', $decoded );

		if ( preg_match( '/^([^\/?#:]*):/', $decoded, $m ) ) {
			return ! in_array( strtolower( $m[1] ), $protocols, true );
		}

		return false;
	}

	/**
	 * Approximates wp_kses(): parses the HTML, unwraps (keeps the text of) every element that is not
	 * allowed, removes comments, removes every attribute that is not allowed for its element, and
	 * removes an href whose scheme is not in $protocols. Text is re-serialised with entities escaped.
	 * Differences from WordPress: output formatting (attribute order, entity style, whitespace) and
	 * that a bad href is dropped whole instead of having its scheme stripped. With $kses_keep_quotes, attribute
	 * values are written with single quotes when the input used them (real kses keeps the author's quotes).
	 *
	 * @param string $html      Untrusted HTML.
	 * @param array  $allowed   Map of tag => map of allowed attributes.
	 * @param array  $protocols Allowed URL schemes.
	 */
	public static function wp_kses( $html, $allowed, $protocols = array() ): string {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}

		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="kses-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );

		$root = $doc->getElementById( 'kses-root' );
		if ( null === $root ) {
			return '';
		}

		self::kses_node( $root, array_change_key_case( $allowed ), $protocols );

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $doc->saveHTML( $child );
		}

		if ( self::$kses_keep_quotes && false !== strpos( $html, "='" ) ) {
			$out = preg_replace_callback(
				'/<[a-z][^>]*>/i',
				static function ( $tag ) {
					return preg_replace_callback(
						'/(\s[a-z-]+)="([^"]*)"/i',
						static function ( $attr ) {
							return $attr[1] . "='" . str_replace( "'", '&#039;', $attr[2] ) . "'";
						},
						$tag[0]
					);
				},
				$out
			);
		}

		return $out;
	}

	private static function kses_node( \DOMNode $node, array $allowed, array $protocols ): void {
		foreach ( iterator_to_array( $node->childNodes ) as $child ) {
			if ( $child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction ) {
				$node->removeChild( $child );
				continue;
			}

			if ( ! $child instanceof \DOMElement ) {
				continue;
			}

			self::kses_node( $child, $allowed, $protocols );

			$tag = strtolower( $child->tagName );
			if ( ! isset( $allowed[ $tag ] ) ) {
				while ( $child->firstChild ) {
					$node->insertBefore( $child->firstChild, $child );
				}
				$node->removeChild( $child );
				continue;
			}

			$attrs = array_change_key_case( (array) $allowed[ $tag ] );
			foreach ( iterator_to_array( $child->attributes ) as $attr ) {
				$name = strtolower( $attr->name );
				if ( ! isset( $attrs[ $name ] ) ) {
					$child->removeAttribute( $attr->name );
				} elseif ( 'href' === $name && self::bad_protocol( $attr->value, $protocols ) ) {
					$child->removeAttribute( $attr->name );
				}
			}
		}
	}
}

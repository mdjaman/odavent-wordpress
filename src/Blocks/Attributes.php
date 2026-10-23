<?php
namespace Odavent\Events\Blocks;

/**
 * Reading block attributes the same way for blocks and shortcodes.
 *
 * Block attributes arrive typed (WordPress validated them against block.json); shortcode attributes
 * arrive as strings. Every reader tolerates both and never throws.
 */
final class Attributes {

	/**
	 * A boolean attribute. Strings "false", "0", "no", "off" and "" are false.
	 *
	 * @param array  $atts    Attributes.
	 * @param string $key     Attribute name.
	 * @param bool   $default Value when absent or unreadable.
	 */
	public static function flag( array $atts, string $key, bool $default ): bool {
		if ( ! array_key_exists( $key, $atts ) ) {
			return $default;
		}

		$value = $atts[ $key ];
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 0 !== $value;
		}
		if ( is_string( $value ) ) {
			return ! in_array( strtolower( trim( $value ) ), array( '', '0', 'false', 'no', 'off' ), true );
		}

		return $default;
	}

	/**
	 * A text attribute, trimmed; $default when absent, not text, or blank.
	 *
	 * @param array  $atts    Attributes.
	 * @param string $key     Attribute name.
	 * @param string $default Fallback.
	 */
	public static function text( array $atts, string $key, string $default = '' ): string {
		$value = $atts[ $key ] ?? null;
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			return $default;
		}

		$value = trim( $value );

		return '' === $value ? $default : $value;
	}

	/**
	 * A positive integer attribute (int, whole float or digit string), or 0.
	 *
	 * @param array  $atts Attributes.
	 * @param string $key  Attribute name.
	 */
	public static function positive_int( array $atts, string $key ): int {
		$value = $atts[ $key ] ?? null;
		if ( is_string( $value ) && preg_match( '/^\s*[0-9]{1,18}\s*\z/', $value ) ) {
			$value = (int) $value;
		} elseif ( is_float( $value ) && floor( $value ) === $value && $value < PHP_INT_MAX ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value > 0 ? $value : 0;
	}

	/**
	 * The root element's class list: the block's own class plus the editor's "Additional CSS class(es)"
	 * (`className`, or `class` from a shortcode), each cleaned with sanitize_html_class().
	 *
	 * @param string $root Block root class, e.g. odv-header.
	 * @param array  $atts Attributes.
	 */
	public static function root_classes( string $root, array $atts ): string {
		$classes = array( $root );
		foreach ( array( 'className', 'class' ) as $key ) {
			$extra = $atts[ $key ] ?? null;
			if ( ! is_string( $extra ) ) {
				continue;
			}
			foreach ( preg_split( '/\s+/', $extra, -1, PREG_SPLIT_NO_EMPTY ) as $candidate ) {
				$clean = sanitize_html_class( $candidate );
				if ( is_string( $clean ) && '' !== $clean ) {
					$classes[] = $clean;
				}
			}
		}

		return implode( ' ', array_unique( $classes ) );
	}

	/**
	 * The page the block is on, for "return" links: get_permalink(), else the home page.
	 */
	public static function permalink(): string {
		$permalink = function_exists( 'get_permalink' ) ? get_permalink() : false;

		return is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * The event id for the root element's data-odv-event (0 when the payload has none).
	 *
	 * @param array $event The payload's event object.
	 */
	public static function event_id( array $event ): int {
		$id = $event['id'] ?? null;

		return is_int( $id ) && $id > 0 ? $id : 0;
	}
}

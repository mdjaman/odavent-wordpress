<?php
namespace Odavent\Events\Render;

use Odavent\Events\Data\Event_View;

/**
 * The link to Odavent's registration page, tagged so Odavent can tell which WordPress site sent the visitor.
 */
final class Register_Url {

	/**
	 * Registration URL with tracking parameters, or '' when the event has no usable http(s) one.
	 *
	 * Parameters: utm_source=wordpress, utm_medium=plugin, utm_campaign={site host}, return={page to come
	 * back to}, and ticket={id} when a ticket is given. An existing query string is kept.
	 *
	 * @param Event_View  $view      The event.
	 * @param mixed      $ticket_id Ticket to preselect: a positive integer or digits; anything else is ignored.
	 * @param mixed|null $return    Where Odavent should send the visitor back to (default: the home page).
	 */
	public static function build( Event_View $view, $ticket_id = null, $return = null ): string {
		$register = $view->event()['register_url'] ?? null;
		$base     = self::safe( is_string( $register ) ? $register : null );
		if ( '' === $base ) {
			return '';
		}

		$back = self::safe( $return );
		if ( '' === $back ) {
			$back = home_url( '/' );
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// add_query_arg() does not encode the values it is given.
		$args = array(
			'utm_source'   => 'wordpress',
			'utm_medium'   => 'plugin',
			'utm_campaign' => rawurlencode( is_string( $host ) ? $host : '' ),
			'return'       => rawurlencode( $back ),
		);

		$ticket = self::ticket_id( $ticket_id );
		if ( null !== $ticket ) {
			$args['ticket'] = (string) $ticket;
		}

		return add_query_arg( $args, $base );
	}

	/**
	 * A positive integer from an int, a whole float or a digit string; null for anything else.
	 *
	 * @param mixed $value Candidate ticket id (it comes from the payload).
	 */
	private static function ticket_id( $value ): ?int {
		if ( is_float( $value ) && floor( $value ) === $value && $value > 0 && $value < PHP_INT_MAX ) {
			$value = (int) $value;
		} elseif ( is_string( $value ) && preg_match( '/^[0-9]{1,18}\z/', $value ) ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value > 0 ? $value : null;
	}

	/**
	 * An http(s) URL or ''. Same rules as Sanitizer::url().
	 *
	 * @param mixed $url Untrusted URL.
	 */
	public static function safe( $url ): string {
		return Sanitizer::url( $url );
	}
}

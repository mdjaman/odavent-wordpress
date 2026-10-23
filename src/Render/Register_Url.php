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
	 * @param int|null    $ticket_id Ticket to preselect.
	 * @param string|null $return    Where Odavent should send the visitor back to (default: the home page).
	 */
	public static function build( Event_View $view, ?int $ticket_id = null, ?string $return = null ): string {
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

		if ( null !== $ticket_id ) {
			$args['ticket'] = rawurlencode( (string) $ticket_id );
		}

		return add_query_arg( $args, $base );
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

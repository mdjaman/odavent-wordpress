<?php
namespace Odavent\Events\Render;

use Odavent\Events\Data\Event_View;

/**
 * schema.org/Event structured data as a JSON-LD <script> element.
 *
 * Every string is cleaned first (Sanitizer), then encoded with JSON_HEX_TAG | JSON_HEX_AMP so that
 * the characters less-than, greater-than and ampersand are written as unicode escapes (backslash-u
 * 003C, 003E, 0026): nothing in the data can end the script element or open a new one.
 */
final class Schema {

	const STATUS_URLS = array(
		Event_View::LIVE      => 'https://schema.org/EventScheduled',
		Event_View::ENDED     => 'https://schema.org/EventScheduled',
		Event_View::ARCHIVED  => 'https://schema.org/EventScheduled',
		Event_View::PAUSED    => 'https://schema.org/EventScheduled', // Registration is paused, not the event.
		Event_View::CANCELLED => 'https://schema.org/EventCancelled',
	);

	/**
	 * The script element, or '' when there is nothing to describe (no content, a draft preview, no
	 * valid start date).
	 *
	 * @param Event_View $view      The event.
	 * @param mixed      $permalink URL of the WordPress page showing the event (get_permalink() may give false).
	 */
	public static function event_json_ld( Event_View $view, $permalink ): string {
		$status = self::STATUS_URLS[ $view->state() ] ?? null;
		if ( null === $status || ! $view->has_content() ) {
			return '';
		}

		$event    = $view->event();
		$timezone = isset( $event['timezone'] ) && is_string( $event['timezone'] ) ? $event['timezone'] : 'UTC';

		$start = Format::moment( self::string_of( $event, 'starts_at' ), $timezone );
		if ( null === $start ) {
			return '';
		}

		$url  = Sanitizer::url( $permalink );
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Event',
			'name'        => Sanitizer::text( self::string_of( $event, 'name' ) ),
			'startDate'   => $start->format( 'c' ),
			'eventStatus' => $status,
		);

		$end = Format::moment( self::string_of( $event, 'ends_at' ), $timezone );
		if ( null !== $end ) {
			$data['endDate'] = $end->format( 'c' );
		}

		$location = self::location( $event['venue'] ?? null );
		if ( null !== $location ) {
			$data['location'] = $location;
		}

		$image = Sanitizer::url( self::string_of( $event, 'cover_url' ) );
		if ( '' === $image ) {
			$image = Sanitizer::url( self::string_of( $event, 'logo_url' ) );
		}
		if ( '' !== $image ) {
			$data['image'] = array( $image );
		}

		// Offers only while registration is really offered: a cancelled, paused, closed or stale event must
		// not advertise tickets as InStock.
		$offers = $view->can_register() ? self::offers( $view->tickets(), self::string_of( $event, 'currency' ), $url ) : array();
		if ( array() !== $offers ) {
			$data['offers'] = $offers;
		}

		if ( '' !== $url ) {
			$data['url'] = $url;
		}

		$json = wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			return '';
		}

		return '<script type="application/ld+json">' . $json . '</script>';
	}

	/**
	 * @param mixed $venue The payload's venue object.
	 * @return array|null A schema.org Place, or null without a venue.
	 */
	private static function location( $venue ): ?array {
		if ( ! is_array( $venue ) ) {
			return null;
		}

		$name = Sanitizer::text( self::string_of( $venue, 'name' ) );

		$address = array();
		foreach ( array(
			'address' => 'streetAddress',
			'city'    => 'addressLocality',
			'country' => 'addressCountry',
		) as $key => $property ) {
			$value = Sanitizer::text( self::string_of( $venue, $key ) );
			if ( '' !== $value ) {
				$address[ $property ] = $value;
			}
		}

		if ( '' === $name && array() === $address ) {
			return null;
		}

		$place = array(
			'@type' => 'Place',
			'name'  => $name,
		);
		if ( array() !== $address ) {
			$place['address'] = array_merge( array( '@type' => 'PostalAddress' ), $address );
		}

		return $place;
	}

	/**
	 * @param array[]     $tickets  Ticket rows.
	 * @param string|null $currency Currency code of the event (missing or malformed: no offers at all, since an
	 *                              Offer with a price but no priceCurrency is flagged by Google Rich Results).
	 * @param string      $url      Page URL ('' to omit).
	 * @return array[]
	 */
	private static function offers( array $tickets, ?string $currency, string $url ): array {
		$currency = strtoupper( trim( (string) $currency ) );
		if ( ! preg_match( '/^[A-Z]{3}$/D', $currency ) ) {
			return array();
		}

		$offers = array();
		foreach ( $tickets as $ticket ) {
			if ( true !== ( $ticket['on_sale'] ?? null ) || ! isset( $ticket['price'] ) || ! is_numeric( $ticket['price'] ) ) {
				continue;
			}

			$price = $ticket['price'] + 0;
			// A negative price, NaN or INF (JSON 1e400 decodes to INF) is not an offer; INF would also make
			// wp_json_encode fail and drop the whole block.
			if ( $price < 0 || ( is_float( $price ) && ! is_finite( $price ) ) ) {
				continue;
			}
			if ( is_float( $price ) ) {
				$rounded = round( $price, 2 );
				if ( 0.0 === $rounded && $price > 0 ) {
					continue; // A positive price that rounds to nothing is not a free offer.
				}
				$price = $rounded;
				if ( floor( $price ) === $price && abs( $price ) < PHP_INT_MAX ) {
					$price = (int) $price;
				}
			}

			$offer = array(
				'@type'         => 'Offer',
				'name'          => Sanitizer::text( self::string_of( $ticket, 'name' ) ),
				'price'         => $price,
				'availability'  => true === ( $ticket['is_full'] ?? null ) ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
			);
			$offer['priceCurrency'] = $currency;
			if ( '' !== $url ) {
				$offer['url'] = $url;
			}

			$offers[] = $offer;
		}

		return $offers;
	}

	/**
	 * @param array  $row Payload object.
	 * @param string $key Key expected to hold a string.
	 */
	private static function string_of( array $row, string $key ): ?string {
		return isset( $row[ $key ] ) && is_string( $row[ $key ] ) ? $row[ $key ] : null;
	}
}

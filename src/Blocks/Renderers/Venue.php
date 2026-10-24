<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * The venue as text, with an optional link to an OpenStreetMap search for it.
 */
final class Venue implements Renderer {

	const MAP_SEARCH = 'https://www.openstreetmap.org/search?query=';

	public function render( Event_View $view, array $atts ): string {
		$event = $view->event();
		$venue = $event['venue'] ?? null;
		if ( ! is_array( $venue ) ) {
			return '';
		}

		$name   = Sanitizer::text( $venue['name'] ?? null );
		$place  = array();
		foreach ( array( 'address', 'city', 'country' ) as $key ) {
			$part = Sanitizer::text( $venue[ $key ] ?? null );
			if ( '' !== $part ) {
				$place[] = $part;
			}
		}

		if ( '' === $name && array() === $place ) {
			return '';
		}

		$map_url = '';
		if ( Attributes::flag( $atts, 'showMapLink', true ) ) {
			$query   = array() !== $place ? implode( ', ', $place ) : $name;
			$map_url = self::MAP_SEARCH . rawurlencode( $query );
		}

		return Template::render(
			'venue',
			array(
				'classes'  => Attributes::root_classes( 'odv-venue', $atts ),
				'event_id' => Attributes::event_id( $event ),
				'name'     => $name,
				'address'  => implode( ', ', $place ),
				'map_url'  => $map_url,
				'view'     => $view,
			)
		);
	}
}

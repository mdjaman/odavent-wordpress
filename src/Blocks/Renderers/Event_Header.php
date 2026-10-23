<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Schema;
use Odavent\Events\Render\Template;

/**
 * Name, dates (event timezone), venue, cover, logo, description and schema.org JSON-LD.
 */
final class Event_Header implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$event = $view->event();

		$name        = Sanitizer::text( $event['name'] ?? null );
		$dates       = Format::date_range( $event );
		$venue       = Attributes::flag( $atts, 'showVenue', true ) ? self::venue_line( $event['venue'] ?? null ) : '';
		$cover       = Attributes::flag( $atts, 'showCover', true ) ? Sanitizer::url( $event['cover_url'] ?? null ) : '';
		$logo        = Attributes::flag( $atts, 'showLogo', true ) ? Sanitizer::url( $event['logo_url'] ?? null ) : '';
		$description = Attributes::flag( $atts, 'showDescription', true ) ? Sanitizer::html( $event['description'] ?? null ) : '';

		if ( '' === $name && '' === $dates && '' === $venue && '' === $cover && '' === $logo && '' === trim( $description ) ) {
			return '';
		}

		$json_ld = '';
		if ( Attributes::flag( $atts, 'showJsonLd', true ) && $view->has_content() && ! $view->is_preview() ) {
			$json_ld = Schema::event_json_ld( $view, Attributes::permalink() );
		}

		return Template::render(
			'event-header',
			array(
				'classes'     => Attributes::root_classes( 'odv-header', $atts ),
				'event_id'    => Attributes::event_id( $event ),
				'name'        => $name,
				'dates'       => $dates,
				'venue'       => $venue,
				'cover_url'   => $cover,
				'logo_url'    => $logo,
				'description' => $description,
				'json_ld'     => $json_ld,
				'view'        => $view,
			)
		);
	}

	/**
	 * "Name, address, city, country" from the parts that are present.
	 *
	 * @param mixed $venue The payload's venue object.
	 */
	private static function venue_line( $venue ): string {
		if ( ! is_array( $venue ) ) {
			return '';
		}

		$parts = array();
		foreach ( array( 'name', 'address', 'city', 'country' ) as $key ) {
			$part = Sanitizer::text( $venue[ $key ] ?? null );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		return implode( ', ', $parts );
	}
}

<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * "Add to calendar" links (Google, Outlook, .ics file) built by Odavent. A link is shown only when its
 * URL is a valid http(s) URL; with none, nothing is shown.
 */
final class Add_To_Calendar implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$event    = $view->event();
		$calendar = isset( $event['calendar'] ) && is_array( $event['calendar'] ) ? $event['calendar'] : array();

		$links = array();
		foreach ( array(
			array( 'google', 'showGoogle', __( 'Google Calendar', 'odavent-events' ), false ),
			array( 'outlook', 'showOutlook', __( 'Outlook', 'odavent-events' ), false ),
			array( 'ics', 'showIcs', __( 'Download .ics file', 'odavent-events' ), true ),
		) as $link ) {
			list( $key, $option, $label, $download ) = $link;
			if ( ! Attributes::flag( $atts, $option, true ) ) {
				continue;
			}

			$url = Sanitizer::url( $calendar[ $key ] ?? null );
			if ( '' !== $url ) {
				$links[] = array(
					'url'      => $url,
					'label'    => $label,
					'download' => $download,
				);
			}
		}

		if ( array() === $links ) {
			return '';
		}

		return Template::render(
			'add-to-calendar',
			array(
				'classes'  => Attributes::root_classes( 'odv-calendar', $atts ),
				'event_id' => Attributes::event_id( $event ),
				'links'    => $links,
				'view'     => $view,
			)
		);
	}
}

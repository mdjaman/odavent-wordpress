<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Render\Template;

/**
 * "Already registered? Get my ticket": a link to Odavent's find-registration page.
 */
final class Find_Registration implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$event = $view->event();
		$url   = Register_Url::safe( $event['find_registration_url'] ?? null );
		if ( '' === $url ) {
			return '';
		}

		return Template::render(
			'find-registration',
			array(
				'classes'  => Attributes::root_classes( 'odv-find-registration', $atts ),
				'event_id' => Attributes::event_id( $event ),
				'url'      => $url,
				'label'    => Attributes::text( $atts, 'label', __( 'Already registered? Get my ticket', 'odavent-events' ) ),
				'view'     => $view,
			)
		);
	}
}

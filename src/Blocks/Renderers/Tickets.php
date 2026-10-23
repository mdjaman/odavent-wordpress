<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Lifecycle;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * The ticket list. A ticket links to registration only while the event takes registrations, the ticket
 * is on sale and it is not full.
 */
final class Tickets implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$event            = $view->event();
		$can_register     = $view->can_register();
		$show_description = Attributes::flag( $atts, 'showDescription', true );
		$show_remaining   = Attributes::flag( $atts, 'showAvailability', false );
		$permalink        = Attributes::permalink();
		$label            = Attributes::text( $atts, 'buttonLabel', __( 'Register', 'odavent-events' ) );
		$currency         = $event['currency'] ?? null;

		$rows      = array();
		$any_links = false;
		foreach ( $view->tickets() as $ticket ) {
			$name = Sanitizer::text( $ticket['name'] ?? null );
			if ( '' === $name ) {
				continue;
			}

			$full    = true === ( $ticket['is_full'] ?? null );
			$on_sale = true === ( $ticket['on_sale'] ?? null );

			$status = '';
			if ( $full ) {
				$status = __( 'Sold out', 'odavent-events' );
			} elseif ( ! $on_sale ) {
				$status = __( 'Not on sale', 'odavent-events' );
			}

			$remaining = '';
			$spots     = $ticket['spots_remaining'] ?? null;
			if ( $show_remaining && ! $full && is_int( $spots ) && $spots > 0 ) {
				/* translators: %s: number of places left for a ticket. */
				$remaining = sprintf( _n( '%s spot left', '%s spots left', $spots, 'odavent-events' ), number_format_i18n( $spots ) );
			}

			$url = '';
			if ( $can_register && $on_sale && ! $full ) {
				$url = Register_Url::build( $view, $ticket['id'] ?? null, $permalink );
			}
			$any_links = $any_links || '' !== $url;

			$rows[] = array(
				'name'        => $name,
				'description' => $show_description ? Sanitizer::html( $ticket['description'] ?? null ) : '',
				'price'       => Format::price( $ticket['price'] ?? null, $currency, $ticket['is_free'] ?? null ),
				'sold_out'    => $full,
				'status'      => $status,
				'remaining'   => $remaining,
				'url'         => $url,
			);
		}

		if ( array() === $rows ) {
			return '';
		}

		return Template::render(
			'tickets',
			array(
				'classes'      => Attributes::root_classes( 'odv-tickets', $atts ),
				'event_id'     => Attributes::event_id( $event ),
				'tickets'      => $rows,
				'button_label' => $label,
				'message'      => $view->is_stale() ? '' : Lifecycle::closed_message( $view ),
				'stale_notice' => Lifecycle::stale_notice_html( $view ),
				'notice'       => $any_links ? __( 'Registration and payment are handled securely by Odavent.', 'odavent-events' ) : '',
				'view'         => $view,
			)
		);
	}
}

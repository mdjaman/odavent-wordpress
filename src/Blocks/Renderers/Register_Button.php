<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Lifecycle;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Render\Template;

/**
 * The Register button: a link to Odavent's registration page, only while registration is open.
 *
 * When the event is ended, paused, cancelled, archived or a draft preview, the registry's lifecycle notice
 * above this block says why, so the block itself renders nothing. A live event whose registration is
 * closed, a chosen ticket that cannot be bought, or an event whose tickets are all sold out or off sale
 * gets a short status line instead of the button. A stale copy gets the stale notice instead.
 */
final class Register_Button implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$status       = '';
		$url          = '';
		$stale_notice = Lifecycle::stale_notice_html( $view );

		// A stale copy never offers Register (can_register() is false): the template shows the stale notice.
		if ( '' === $stale_notice ) {
			if ( ! $view->can_register() ) {
				if ( null !== Lifecycle::notice( $view ) ) {
					return '';
				}
				$status = Lifecycle::closed_message( $view );
			} else {
				$ticket_id = Attributes::positive_int( $atts, 'ticketId' );
				$ticket    = $ticket_id > 0 ? self::find_ticket( $view, $ticket_id ) : null;

				if ( null !== $ticket && true === ( $ticket['is_full'] ?? null ) ) {
					$status = __( 'Sold out', 'odavent-events' );
				} elseif ( null !== $ticket && true !== ( $ticket['on_sale'] ?? null ) ) {
					$status = __( 'This ticket is not on sale.', 'odavent-events' );
				} elseif ( null === $ticket && ! self::has_buyable_ticket( $view ) ) {
					$status = __( 'Sold out', 'odavent-events' );
				} else {
					$url = Register_Url::build( $view, null !== $ticket ? $ticket_id : null, Attributes::permalink() );
					if ( '' === $url ) {
						return '';
					}
				}
			}
		}

		return Template::render(
			'register-button',
			array(
				'classes'      => Attributes::root_classes( 'odv-register', $atts ),
				'event_id'     => Attributes::event_id( $view->event() ),
				'url'          => $url,
				'label'        => Attributes::text( $atts, 'label', __( 'Register', 'odavent-events' ) ),
				'status'       => $status,
				'stale_notice' => $stale_notice,
				'notice'       => __( 'Registration and payment are handled securely by Odavent.', 'odavent-events' ),
				'view'         => $view,
			)
		);
	}

	/**
	 * True when the event lists no tickets (Odavent decides at checkout) or at least one is on sale and not full.
	 */
	private static function has_buyable_ticket( Event_View $view ): bool {
		$tickets = $view->tickets();
		if ( array() === $tickets ) {
			return true;
		}

		foreach ( $tickets as $ticket ) {
			if ( true === ( $ticket['on_sale'] ?? null ) && true !== ( $ticket['is_full'] ?? null ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The ticket row with this id, or null.
	 */
	private static function find_ticket( Event_View $view, int $ticket_id ): ?array {
		foreach ( $view->tickets() as $ticket ) {
			if ( ( $ticket['id'] ?? null ) === $ticket_id ) {
				return $ticket;
			}
		}

		return null;
	}
}

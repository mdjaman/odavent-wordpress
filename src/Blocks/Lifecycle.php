<?php
namespace Odavent\Events\Blocks;

use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Template;

/**
 * What a page says about an event that is not simply open for registration.
 */
final class Lifecycle {

	/**
	 * The notice shown above the header and Register blocks, or null (live event, or nothing to say).
	 *
	 * @return array{kind: string, message: string}|null
	 */
	public static function notice( Event_View $view ): ?array {
		switch ( $view->notice_key() ) {
			case 'ended':
				return array(
					'kind'    => 'ended',
					'message' => __( 'This event has ended.', 'odavent-events' ),
				);
			case 'paused':
				return array(
					'kind'    => 'paused',
					'message' => __( 'Registration is temporarily closed.', 'odavent-events' ),
				);
			case 'cancelled':
				return array(
					'kind'    => 'cancelled',
					'message' => __( 'This event has been cancelled.', 'odavent-events' ),
				);
			case 'draft_preview':
				return array(
					'kind'    => 'draft',
					'message' => __( 'Draft preview: this event is not published on Odavent yet. Visitors do not see it.', 'odavent-events' ),
				);
			default:
				return null;
		}
	}

	/**
	 * Why there is no Register link ('' when registration is open). Shown in place of links.
	 */
	public static function closed_message( Event_View $view ): string {
		if ( $view->can_register() ) {
			return '';
		}

		switch ( $view->notice_key() ) {
			case 'ended':
				return __( 'This event has ended.', 'odavent-events' );
			case 'paused':
				return __( 'Registration is temporarily closed.', 'odavent-events' );
			case 'cancelled':
				return __( 'This event has been cancelled.', 'odavent-events' );
			case 'draft_preview':
				return __( 'Register links appear here once the event is published.', 'odavent-events' );
			default:
				return __( 'Registration is closed.', 'odavent-events' );
		}
	}

	/**
	 * The lifecycle notice as HTML, or ''.
	 */
	public static function notice_html( Event_View $view ): string {
		$notice = self::notice( $view );

		return null === $notice ? '' : self::render_notice( $notice['kind'], $notice['message'] );
	}

	/**
	 * A notice through templates/notice.php.
	 *
	 * @param string $kind    Modifier: ended, paused, cancelled, draft, unavailable, choose, error.
	 * @param string $message Plain text.
	 */
	public static function render_notice( string $kind, string $message ): string {
		return Template::render(
			'notice',
			array(
				'kind'    => sanitize_html_class( $kind ),
				'message' => $message,
			)
		);
	}
}

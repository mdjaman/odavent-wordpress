<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Lifecycle;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Clock;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * A countdown to the event's start. The first values are computed here (so the block is right without
 * JavaScript and before it loads); countdown.js then ticks from the data attributes alone, and switches
 * to the "live" and "ended" labels at the right moments, which also corrects a cached page.
 *
 * It promises a start to wait for, so it is hidden when that promise is unreliable: a cancelled or archived
 * event, a paused event whose start has passed, a stale copy, or a missing/invalid start date. A paused event
 * that is still upcoming carries data-odv-paused so the script hides the block itself when the start passes
 * (a page left open, or served from a page cache, must not announce "Live now" for a paused event).
 *
 * On an event page a paused event renders nothing, and neither does an ended one when the page's single notice
 * at the top already says so. An event over by time that Odavent has not flagged ended yet has no notice, so its
 * countdown shows "Event ended" there, as the block does on its own.
 */
final class Countdown implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		if ( $view->is_stale() || in_array( $view->state(), array( Event_View::CANCELLED, Event_View::ARCHIVED ), true ) ) {
			return '';
		}

		$event  = $view->event();
		$starts = Format::moment( $event['starts_at'] ?? null, 'UTC' );
		if ( null === $starts ) {
			return '';
		}

		$ends   = Format::moment( $event['ends_at'] ?? null, 'UTC' );
		$now    = self::now();
		$begins = $starts->getTimestamp();

		$in_page = Attributes::flag( $atts, Event_Page::IN_PAGE, false );
		if ( Event_View::PAUSED === $view->state() && ( $in_page || $now >= $begins ) ) {
			return '';
		}

		$flagged_ended = Event_View::ENDED === $view->state();
		if ( $flagged_ended || ( null !== $ends && $now >= $ends->getTimestamp() ) ) {
			$state = 'ended';
		} elseif ( $now >= $begins ) {
			$state = 'live';
		} else {
			$state = 'upcoming';
		}

		// The page's notice already says ended (or why not open); without one, "Event ended" is the only hint.
		if ( $in_page && 'ended' === $state && null !== Lifecycle::notice( $view ) ) {
			return '';
		}

		$left = max( 0, $begins - $now );

		return Template::render(
			'countdown',
			array(
				'classes'     => Attributes::root_classes( 'odv-countdown', $atts ),
				'event_id'    => Attributes::event_id( $event ),
				'starts_at'   => $starts->format( 'c' ),
				'ends_at'     => null !== $ends ? $ends->format( 'c' ) : '',
				'state'       => $state,
				'ended_flag'  => $flagged_ended,
				'paused'      => Event_View::PAUSED === $view->state(),
				'label_live'  => self::label( $atts, 'labelLive', __( 'Live now', 'odavent-events' ) ),
				'label_ended' => self::label( $atts, 'labelEnded', __( 'Event ended', 'odavent-events' ) ),
				'days'        => (string) intdiv( $left, 86400 ),
				'hours'       => sprintf( '%02d', intdiv( $left % 86400, 3600 ) ),
				'minutes'     => sprintf( '%02d', intdiv( $left % 3600, 60 ) ),
				'seconds'     => sprintf( '%02d', $left % 60 ),
				'view'        => $view,
			)
		);
	}

	/**
	 * A label from the block settings as plain text; the default when it is blank.
	 *
	 * @param array  $atts    Block attributes.
	 * @param string $key     Attribute name.
	 * @param string $default Translated default.
	 */
	private static function label( array $atts, string $key, string $default ): string {
		$text = Sanitizer::text( Attributes::text( $atts, $key ) );

		return '' === $text ? $default : $text;
	}

	/**
	 * The current time (see Clock: the `odavent_events_now` filter, junk falls back to time()).
	 */
	private static function now(): int {
		return Clock::now();
	}
}

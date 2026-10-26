<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * The event agenda: sessions grouped by day (the event's day, not UTC), sorted by start time, with
 * breakout rooms nested under their parent session and optional track filter buttons.
 *
 * The agenda can be narrowed to one session (`sessionId`, with its rooms), one track or one day (`day`,
 * YYYY-MM-DD in the event's timezone). Rooms follow their parent session, as the track filter buttons do.
 *
 * Without JavaScript everything is visible; assets/js/agenda-filter.js only hides sessions on request.
 */
final class Agenda implements Renderer {

	public function render( Event_View $view, array $atts ): string {
		$event    = $view->event();
		$timezone = $event['timezone'] ?? null;

		$show_speakers    = Attributes::flag( $atts, 'showSpeakers', true );
		$show_description = Attributes::flag( $atts, 'showDescription', false );
		$speakers_by_id   = $show_speakers ? $view->speakers_by_id() : array();

		// One node per session that has a name, in payload order (the index is the tie-break of the sort).
		$nodes = array();
		foreach ( $view->sessions() as $session ) {
			$name = Sanitizer::text( $session['name'] ?? null );
			if ( '' === $name ) {
				continue;
			}

			$start = Format::moment( $session['starts_at'] ?? null, $timezone );
			$end   = Format::moment( $session['ends_at'] ?? null, $timezone );

			$nodes[] = array(
				'index'       => count( $nodes ),
				'id'          => isset( $session['id'] ) && is_int( $session['id'] ) ? $session['id'] : null,
				'parent_id'   => isset( $session['parent_id'] ) && is_int( $session['parent_id'] ) ? $session['parent_id'] : null,
				'timestamp'   => null !== $start ? $start->getTimestamp() : PHP_INT_MAX,
				'day_key'     => Format::day_key( $session['starts_at'] ?? null, $timezone ),
				'day'         => Format::date( $session['starts_at'] ?? null, $timezone ),
				'time'        => self::time_range( $session, $timezone ),
				'name'        => $name,
				'description' => $show_description ? Sanitizer::html( $session['description'] ?? null ) : '',
				'location'    => Sanitizer::text( $session['location'] ?? null ),
				'track'       => Sanitizer::text( $session['track'] ?? null ),
				'speakers'    => $show_speakers ? self::speaker_names( $session['speaker_ids'] ?? null, $speakers_by_id ) : '',
				'rooms'       => array(),
			);
		}

		if ( array() === $nodes ) {
			return '';
		}

		$top_level = self::narrow( self::nest( $nodes ), $atts );
		if ( array() === $top_level ) {
			return '';
		}

		$days = array();
		foreach ( $top_level as $node ) {
			$key = $node['day_key'];
			if ( ! isset( $days[ $key ] ) ) {
				$days[ $key ] = array(
					'title'    => $node['day'],
					'sessions' => array(),
				);
			}
			$days[ $key ]['sessions'][] = $node;
		}

		// Track filter: the tracks of the top-level sessions (a room follows its parent), in agenda order.
		$tracks = array();
		foreach ( $top_level as $node ) {
			if ( '' !== $node['track'] && ! in_array( $node['track'], $tracks, true ) ) {
				$tracks[] = $node['track'];
			}
		}
		$filters = Attributes::flag( $atts, 'trackFilter', true ) && count( $tracks ) > 1 ? $tracks : array();

		return Template::render(
			'agenda',
			array(
				'classes'  => Attributes::root_classes( 'odv-agenda', $atts ),
				'event_id' => Attributes::event_id( $event ),
				'tracks'   => $filters,
				'days'     => array_values( $days ),
				'view'     => $view,
			)
		);
	}

	/**
	 * Applies the `sessionId`, `track` and `day` attributes to the nested sessions. An id that matches a
	 * breakout room shows that room alone. A value that is not usable (a day that is not YYYY-MM-DD, an id
	 * that is not a number) is ignored, like any other bad attribute; a usable one that matches nothing
	 * leaves nothing to show.
	 *
	 * @param array[] $top_level Nested sessions.
	 * @param array   $atts      Attributes.
	 * @return array[]
	 */
	private static function narrow( array $top_level, array $atts ): array {
		$session_id = Attributes::positive_int( $atts, 'sessionId' );
		if ( $session_id > 0 ) {
			$found = self::find( $top_level, $session_id );

			return null === $found ? array() : array( $found );
		}

		$track = self::fold( Attributes::text( $atts, 'track' ) );
		$day   = Attributes::text( $atts, 'day' );
		$day   = 1 === preg_match( '/^[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $day ) ? $day : '';

		return array_values(
			array_filter(
				$top_level,
				static function ( $node ) use ( $track, $day ) {
					return ( '' === $track || self::fold( $node['track'] ) === $track )
						&& ( '' === $day || $node['day_key'] === $day );
				}
			)
		);
	}

	/**
	 * The session with this id anywhere in the tree (rooms included), or null.
	 *
	 * @param array[] $nodes Nested sessions.
	 * @param int     $id    Session id.
	 */
	private static function find( array $nodes, int $id ): ?array {
		foreach ( $nodes as $node ) {
			if ( $node['id'] === $id ) {
				return $node;
			}
			$room = self::find( $node['rooms'], $id );
			if ( null !== $room ) {
				return $room;
			}
		}

		return null;
	}

	/**
	 * Trimmed text with ASCII letters lower-cased (never strtolower(): it depends on the process locale and
	 * must not touch UTF-8 bytes), for comparing a track name.
	 *
	 * @param string $text Text.
	 */
	private static function fold( string $text ): string {
		return strtr( trim( $text ), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );
	}

	/**
	 * Attaches each session to its parent and returns the top-level sessions, all sorted by start time
	 * (ties keep payload order). A session is top level when it has no parent, its parent is not in the
	 * list, it names itself, or it sits on a loop of parents (every member of the loop is then top level,
	 * so nothing is lost and nothing can loop).
	 *
	 * @param array[] $nodes Session nodes, indexed 0..n-1.
	 * @return array[]
	 */
	private static function nest( array $nodes ): array {
		$index_of = array();
		foreach ( $nodes as $node ) {
			if ( null !== $node['id'] && ! isset( $index_of[ $node['id'] ] ) ) {
				$index_of[ $node['id'] ] = $node['index'];
			}
		}

		$parent_of = array();
		foreach ( $nodes as $node ) {
			$parent = null !== $node['parent_id'] && isset( $index_of[ $node['parent_id'] ] ) ? $index_of[ $node['parent_id'] ] : null;
			if ( $parent === $node['index'] ) {
				$parent = null;
			}
			$parent_of[ $node['index'] ] = $parent;
		}

		// A node is on a loop when climbing from it comes back to it.
		$limit  = count( $nodes );
		$on_loop = array();
		foreach ( $parent_of as $start => $parent ) {
			$cursor = $parent;
			for ( $step = 0; null !== $cursor && $step <= $limit; $step++ ) {
				if ( $cursor === $start ) {
					$on_loop[ $start ] = true;
					break;
				}
				$cursor = $parent_of[ $cursor ];
			}
		}

		$children  = array();
		$top_level = array();
		foreach ( $nodes as $node ) {
			$parent = isset( $on_loop[ $node['index'] ] ) ? null : $parent_of[ $node['index'] ];
			if ( null === $parent ) {
				$top_level[] = $node['index'];
			} else {
				$children[ $parent ][] = $node['index'];
			}
		}

		$build = function ( array $indexes, ?string $parent_day ) use ( &$build, $nodes, $children ): array {
			usort(
				$indexes,
				static function ( $a, $b ) use ( $nodes ) {
					return array( $nodes[ $a ]['timestamp'], $a ) <=> array( $nodes[ $b ]['timestamp'], $b );
				}
			);

			$built = array();
			foreach ( $indexes as $index ) {
				$node = $nodes[ $index ];
				if ( null !== $parent_day && '' !== $node['time'] && $node['day_key'] !== $parent_day ) {
					// A room on another day than its parent says which day.
					$node['time'] = $node['day'] . ' ' . $node['time'];
				}
				$node['rooms'] = isset( $children[ $index ] ) ? $build( $children[ $index ], $node['day_key'] ) : array();
				$built[]       = $node;
			}

			return $built;
		};

		return $build( $top_level, null );
	}

	/**
	 * "09:00 – 10:30" in the event's timezone; the end carries its date when it is on another day.
	 *
	 * @param array $session  Session row.
	 * @param mixed $timezone Event timezone.
	 */
	private static function time_range( array $session, $timezone ): string {
		$starts = $session['starts_at'] ?? null;
		$ends   = $session['ends_at'] ?? null;

		$start = Format::time( $starts, $timezone );
		if ( '' === $start ) {
			return '';
		}

		if ( '' === Format::time( $ends, $timezone ) ) {
			return $start;
		}

		$end = Format::day_key( $starts, $timezone ) === Format::day_key( $ends, $timezone )
			? Format::time( $ends, $timezone )
			: Format::datetime( $ends, $timezone );

		return sprintf(
			/* translators: 1: start time, 2: end time (a session's time range). */
			__( '%1$s – %2$s', 'odavent-events' ),
			$start,
			$end
		);
	}

	/**
	 * The names of a session's speakers, comma separated, unknown ids skipped.
	 *
	 * @param mixed $ids            Payload `speaker_ids`.
	 * @param array $speakers_by_id Speaker rows keyed by id.
	 */
	private static function speaker_names( $ids, array $speakers_by_id ): string {
		if ( ! is_array( $ids ) ) {
			return '';
		}

		$names = array();
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || ! isset( $speakers_by_id[ $id ] ) ) {
				continue;
			}
			$name = Sanitizer::text( $speakers_by_id[ $id ]['name'] ?? null );
			if ( '' !== $name && ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		return implode( ', ', $names );
	}
}

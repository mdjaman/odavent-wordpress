<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * Sponsors, exhibitors and other partners, grouped by type. Odavent already orders the list (featured
 * first); the order inside a group is the payload's. Groups holding a featured (paid) placement come first,
 * so a featured exhibitor is never shown below every plain sponsor.
 *
 * A logo links to the tracked `click_url` (featured placements) or else the partner's `website`. Both are
 * paid-for third-party links: rel="sponsored noopener". Without a usable logo the name is shown as text.
 */
final class Sponsors implements Renderer {

	const MIN_COLUMNS = 1;
	const MAX_COLUMNS = 6;

	/** Group order for the known types; any other type follows, in order of appearance. */
	const KNOWN_TYPES = array( 'sponsor', 'both', 'exhibitor', 'media', 'partner' );

	public function render( Event_View $view, array $atts ): string {
		$event            = $view->event();
		$show_description = Attributes::flag( $atts, 'showDescription', false );
		$group_by_type    = Attributes::flag( $atts, 'groupByType', true );

		// Every partner with a name, in payload order (Odavent puts featured placements first).
		$partners = array();
		foreach ( $view->partners() as $partner ) {
			$name = Sanitizer::text( $partner['name'] ?? null );
			if ( '' === $name ) {
				continue;
			}

			$link = Sanitizer::url( $partner['click_url'] ?? null );
			if ( '' === $link ) {
				$link = Sanitizer::url( $partner['website'] ?? null );
			}

			$partners[] = array(
				'type'        => self::type_key( $partner['type'] ?? null ),
				'name'        => $name,
				'logo_url'    => Sanitizer::url( $partner['logo_url'] ?? null ),
				'link_url'    => $link,
				'description' => $show_description ? Sanitizer::html( $partner['description'] ?? null ) : '',
				'featured'    => true === ( $partner['is_featured'] ?? null ),
			);
		}

		if ( array() === $partners ) {
			return '';
		}

		if ( $group_by_type ) {
			// Groups with a featured partner first, each part in the fixed order; payload order inside each group.
			$buckets  = array();
			$featured = array();
			foreach ( $partners as $partner ) {
				$buckets[ $partner['type'] ][] = $partner;
				if ( $partner['featured'] ) {
					$featured[ $partner['type'] ] = true;
				}
			}
			$types  = self::ordered_types( array_keys( $buckets ) );
			$first  = array();
			$others = array();
			foreach ( $types as $type ) {
				if ( isset( $featured[ $type ] ) ) {
					$first[] = $type;
				} else {
					$others[] = $type;
				}
			}
			$groups = array();
			foreach ( array_merge( $first, $others ) as $type ) {
				$groups[] = array(
					'title' => self::type_label( $type ),
					'items' => $buckets[ $type ],
				);
			}
		} else {
			// One flat list, exactly the payload order: a featured exhibitor is never moved behind a sponsor.
			$groups = array(
				array(
					'title' => '',
					'items' => $partners,
				),
			);
		}

		return Template::render(
			'sponsors',
			array(
				'classes'    => Attributes::root_classes( 'odv-sponsors', $atts ),
				'event_id'   => Attributes::event_id( $event ),
				'list_class' => 'odv-sponsors__list odv-sponsors__list--cols-' . Attributes::bounded_int( $atts, 'columns', self::MIN_COLUMNS, self::MAX_COLUMNS, 4 ),
				'groups'     => $groups,
				'view'       => $view,
			)
		);
	}

	/**
	 * The grouping key of a partner type: lower case, a missing type is "partner".
	 *
	 * @param mixed $type Payload `type`.
	 */
	private static function type_key( $type ): string {
		// ASCII-only folding: strtolower() depends on the process locale and must not touch UTF-8 bytes.
		$text = strtr( Sanitizer::text( $type ), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz' );

		return '' === $text ? 'partner' : $text;
	}

	/**
	 * Known types first in their fixed order, then the others as they appeared.
	 *
	 * @param string[] $present Type keys present.
	 * @return string[]
	 */
	private static function ordered_types( array $present ): array {
		$ordered = array_values( array_intersect( self::KNOWN_TYPES, $present ) );

		return array_merge( $ordered, array_values( array_diff( $present, self::KNOWN_TYPES ) ) );
	}

	/**
	 * A group heading: translated for the known types, the humanised type otherwise (escaped on output).
	 *
	 * @param string $type Type key.
	 */
	private static function type_label( string $type ): string {
		switch ( $type ) {
			case 'sponsor':
				return __( 'Sponsors', 'odavent-events' );
			case 'both':
				return __( 'Sponsors and exhibitors', 'odavent-events' );
			case 'exhibitor':
				return __( 'Exhibitors', 'odavent-events' );
			case 'media':
				return __( 'Media partners', 'odavent-events' );
			case 'partner':
				return __( 'Partners', 'odavent-events' );
		}

		$label = trim( (string) preg_replace( '/[_\s]+/', ' ', str_replace( '-', ' ', $type ) ) );

		return '' === $label ? __( 'Partners', 'odavent-events' ) : ucfirst( $label );
	}
}

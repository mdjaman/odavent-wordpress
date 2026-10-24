<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Template;

/**
 * Speaker cards in the payload's order (Odavent lists featured speakers first). A card without a usable
 * photo gets the speaker's initials instead.
 */
final class Speakers implements Renderer {

	const MIN_COLUMNS = 2;
	const MAX_COLUMNS = 4;

	public function render( Event_View $view, array $atts ): string {
		$event        = $view->event();
		$show_bio     = Attributes::flag( $atts, 'showBio', false );
		$show_company = Attributes::flag( $atts, 'showCompany', true );
		$only_id      = Attributes::positive_int( $atts, 'speakerId' );
		$limit        = Attributes::positive_int( $atts, 'limit' );

		$cards = array();
		foreach ( $view->speakers() as $speaker ) {
			$name = Sanitizer::text( $speaker['name'] ?? null );
			if ( '' === $name ) {
				continue;
			}
			if ( $only_id > 0 && ( $speaker['id'] ?? null ) !== $only_id ) {
				continue;
			}

			$cards[] = array(
				'name'      => $name,
				'role'      => Sanitizer::text( $speaker['job_title'] ?? null ),
				'company'   => $show_company ? Sanitizer::text( $speaker['company'] ?? null ) : '',
				'bio'       => $show_bio ? Sanitizer::html( $speaker['bio'] ?? null ) : '',
				'photo_url' => Sanitizer::url( $speaker['photo_url'] ?? null ),
				'initials'  => self::initials( $name ),
				'featured'  => true === ( $speaker['is_featured'] ?? null ),
			);

			if ( $only_id > 0 || ( $limit > 0 && count( $cards ) >= $limit ) ) {
				break;
			}
		}

		if ( array() === $cards ) {
			return '';
		}

		$columns = Attributes::bounded_int( $atts, 'columns', self::MIN_COLUMNS, self::MAX_COLUMNS, 3 );

		return Template::render(
			'speakers',
			array(
				'classes'    => Attributes::root_classes( 'odv-speakers', $atts ),
				'event_id'   => Attributes::event_id( $event ),
				'list_class' => $only_id > 0 ? 'odv-speakers__list odv-speakers__list--single' : 'odv-speakers__list odv-speakers__list--cols-' . $columns,
				'speakers'   => $cards,
				'view'       => $view,
			)
		);
	}

	/**
	 * Up to two capital letters: the first letter or digit of the first and of the last word. Never relies
	 * on the string's byte length, and falls back to ASCII when the text is not valid UTF-8.
	 *
	 * @param string $name Plain-text name.
	 */
	private static function initials( string $name ): string {
		$found = array();
		$count = preg_match_all( '/[\\p{L}\\p{N}]+/u', $name, $found );
		if ( false === $count ) {
			// Not valid UTF-8: keep the ASCII letters and digits.
			$count = preg_match_all( '/[A-Za-z0-9]+/', $name, $found );
		}
		$words = is_int( $count ) && $count > 0 && isset( $found[0] ) ? $found[0] : array();

		if ( array() === $words ) {
			return '?';
		}

		$picked = array( self::first_letter( reset( $words ) ) );
		if ( count( $words ) > 1 ) {
			$picked[] = self::first_letter( end( $words ) );
		}

		$initials = implode( '', array_filter( $picked, 'strlen' ) );

		return '' === $initials ? '?' : $initials;
	}

	private static function first_letter( string $word ): string {
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strtoupper' ) ) {
			$letter = mb_substr( $word, 0, 1, 'UTF-8' );

			return is_string( $letter ) ? mb_strtoupper( $letter, 'UTF-8' ) : '';
		}

		// No mbstring: take one whole character with PCRE (never half a multi-byte one); only ASCII is capitalised.
		return 1 === preg_match( '/^./su', $word, $match ) ? strtoupper( $match[0] ) : strtoupper( substr( $word, 0, 1 ) );
	}
}

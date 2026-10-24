<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Agenda;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class AgendaTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Agenda() )->render( Event_View::from_payload( $payload ), $atts );
	}

	private function session( int $id, array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => $id,
				'parent_id'   => null,
				'name'        => 'Session ' . $id,
				'description' => null,
				'starts_at'   => '2026-11-12T09:00:00+00:00',
				'ends_at'     => '2026-11-12T10:00:00+00:00',
				'location'    => null,
				'track'       => null,
				'speaker_ids' => array(),
			),
			$overrides
		);
	}

	private function with_sessions( array $sessions, array $event = array() ): array {
		$payload             = $this->payload( $event );
		$payload['sessions'] = $sessions;

		return $payload;
	}

	/**
	 * @return \DOMXPath
	 */
	private function xpath( string $html ): \DOMXPath {
		return new \DOMXPath( $this->dom( $html ) );
	}

	/**
	 * Session names in document order.
	 *
	 * @return string[]
	 */
	private function names( string $html ): array {
		$out = array();
		foreach ( $this->xpath( $html )->query( '//*[contains(@class,"odv-agenda__title")]' ) as $node ) {
			$out[] = $node->textContent;
		}

		return $out;
	}

	/**
	 * Day headings in order.
	 *
	 * @return string[]
	 */
	private function days( string $html ): array {
		$out = array();
		foreach ( $this->xpath( $html )->query( '//*[contains(@class,"odv-agenda__day-title")]' ) as $node ) {
			$out[] = $node->textContent;
		}

		return $out;
	}

	public function test_it_groups_sessions_by_day_with_a_heading_each(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-agenda" data-odv-event="42">', trim( $html ) );
		$this->assertSame( array( '2026-11-12', '2026-11-13' ), $this->days( $html ) );
		$this->assertSame( 2, substr_count( $html, '<div class="odv-agenda__day">' ) );
		$this->assertSame(
			array(
				'Ouverture et mot de bienvenue',
				'L\'IA au service des PME africaines',
				'Atelier : lever des fonds en Afrique de l\'Ouest',
				'Atelier : lever des fonds, salle fintech',
				'Table ronde : paiements mobiles',
				'Clôture et remise des prix',
			),
			$this->names( $html )
		);
	}

	public function test_the_day_is_the_events_day_not_utc(): void {
		// 23:30 and 00:30 UTC are on two UTC days, but both on 13 November in Auckland (UTC+13).
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'starts_at' => '2026-11-12T23:30:00+00:00', 'ends_at' => '2026-11-13T00:00:00+00:00' ) ),
				$this->session( 2, array( 'starts_at' => '2026-11-13T00:30:00+00:00', 'ends_at' => '2026-11-13T01:00:00+00:00' ) ),
			),
			array( 'timezone' => 'Pacific/Auckland' )
		);
		$html = $this->render( $payload );

		$this->assertSame( array( '2026-11-13' ), $this->days( $html ) );

		// And the other way round: one UTC day, two event days (New York is UTC-5).
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'starts_at' => '2026-11-13T03:00:00+00:00' ) ),
				$this->session( 2, array( 'starts_at' => '2026-11-13T09:00:00+00:00' ) ),
			),
			array( 'timezone' => 'America/New_York' )
		);
		$this->assertSame( array( '2026-11-12', '2026-11-13' ), $this->days( $this->render( $payload ) ) );
	}

	public function test_times_are_in_the_event_timezone(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'starts_at' => '2026-11-12T09:00:00+00:00', 'ends_at' => '2026-11-12T10:30:00+00:00' ) ) ), array( 'timezone' => 'Pacific/Auckland' ) );

		$this->assertStringContainsString( '<p class="odv-agenda__time">22:00 – 23:30</p>', $this->render( $payload ) );
	}

	public function test_a_session_ending_another_day_shows_the_end_date(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'starts_at' => '2026-11-12T22:00:00+00:00', 'ends_at' => '2026-11-13T02:00:00+00:00' ) ) ), array( 'timezone' => 'UTC' ) );

		$this->assertStringContainsString( '22:00 – 2026-11-13 02:00', $this->render( $payload ) );
	}

	public function test_a_session_without_an_end_shows_only_the_start(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'ends_at' => null ) ) ), array( 'timezone' => 'UTC' ) );

		$this->assertStringContainsString( '<p class="odv-agenda__time">09:00</p>', $this->render( $payload ) );
	}

	public function test_sessions_are_sorted_by_start_with_ties_in_payload_order(): void {
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'name' => 'Late', 'starts_at' => '2026-11-12T15:00:00+00:00' ) ),
				$this->session( 2, array( 'name' => 'Tie A', 'starts_at' => '2026-11-12T09:00:00+00:00' ) ),
				$this->session( 3, array( 'name' => 'Early', 'starts_at' => '2026-11-12T08:00:00+00:00' ) ),
				$this->session( 4, array( 'name' => 'Tie B', 'starts_at' => '2026-11-12T09:00:00+00:00' ) ),
				$this->session( 5, array( 'name' => 'Tie C', 'starts_at' => '2026-11-12T09:00:00+00:00' ) ),
			)
		);

		$this->assertSame( array( 'Early', 'Tie A', 'Tie B', 'Tie C', 'Late' ), $this->names( $this->render( $payload ) ) );
	}

	public function test_the_sort_is_stable_for_many_sessions_starting_together(): void {
		// PHP before 8.0 sorts more than 16 items with an unstable algorithm: the payload order must be the tie-break.
		$sessions = array();
		$expected = array();
		for ( $i = 1; $i <= 60; $i++ ) {
			$sessions[] = $this->session( $i, array( 'name' => 'Same ' . $i ) );
			$expected[] = 'Same ' . $i;
		}

		$this->assertSame( $expected, $this->names( $this->render( $this->with_sessions( $sessions ) ) ) );
	}

	public function test_a_breakout_nests_inside_its_parents_rooms_list(): void {
		$html  = $this->render( $this->payload() );
		$xpath = $this->xpath( $html );

		$rooms = $xpath->query( '//li[contains(@class,"odv-agenda__session")]//ul[contains(@class,"odv-agenda__rooms")]//li' );
		$this->assertSame( 1, $rooms->length );
		$this->assertStringContainsString( 'salle fintech', $rooms->item( 0 )->textContent );
		$this->assertStringContainsString( 'odv-agenda__session--child', $rooms->item( 0 )->getAttribute( 'class' ) );

		$parent = $xpath->query( '//ul[contains(@class,"odv-agenda__rooms")]/ancestor::li[1]' )->item( 0 );
		$this->assertStringContainsString( 'Atelier : lever des fonds en Afrique', $parent->textContent );
		$this->assertSame( 5, $xpath->query( '//ul[contains(@class,"odv-agenda__list")]/li' )->length, 'top level sessions only' );
	}

	public function test_a_session_whose_parent_is_not_in_the_list_is_top_level(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'parent_id' => 999 ) ), $this->session( 2, array( 'parent_id' => 'x' ) ) ) );
		$html    = $this->render( $payload );

		$this->assertStringNotContainsString( 'odv-agenda__rooms', $html );
		$this->assertSame( array( 'Session 1', 'Session 2' ), $this->names( $html ) );
	}

	public function test_self_and_cyclic_parents_do_not_loop_and_nothing_is_lost(): void {
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'parent_id' => 1 ) ),
				$this->session( 2, array( 'parent_id' => 3 ) ),
				$this->session( 3, array( 'parent_id' => 2 ) ),
				$this->session( 4, array( 'parent_id' => 2 ) ),
			)
		);
		$html = $this->render( $payload );

		$this->assertEqualsCanonicalizing( array( 'Session 1', 'Session 2', 'Session 3', 'Session 4' ), $this->names( $html ) );
		$xpath = $this->xpath( $html );
		$this->assertSame( 1, $xpath->query( '//ul[contains(@class,"odv-agenda__rooms")]//li' )->length, 'only 4 (child of the cycle member 2) is nested' );
		$this->assertStringContainsString( 'Session 4', $xpath->query( '//ul[contains(@class,"odv-agenda__rooms")]//li' )->item( 0 )->textContent );
	}

	public function test_a_long_chain_of_rooms_nests_without_a_depth_problem(): void {
		$sessions = array( $this->session( 1 ) );
		for ( $i = 2; $i <= 40; $i++ ) {
			$sessions[] = $this->session( $i, array( 'parent_id' => $i - 1 ) );
		}

		$this->assertCount( 40, $this->names( $this->render( $this->with_sessions( $sessions ) ) ) );
	}

	public function test_speaker_names_are_resolved_and_unknown_ids_skipped(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'speaker_ids' => array( 301, 999, 302, 301, 'x', null, array( 1 ) ) ) ) ) );
		$html    = $this->render( $payload );

		$this->assertStringContainsString( '<p class="odv-agenda__speakers">Marcel Djaman, Aïcha Koné</p>', $html );
		$this->assertStringNotContainsString( '999', $html );

		$payload = $this->with_sessions( array( $this->session( 1, array( 'speaker_ids' => array( 999 ) ) ) ) );
		$this->assertStringNotContainsString( 'odv-agenda__speakers', $this->render( $payload ) );
	}

	public function test_speakers_can_be_hidden(): void {
		$html = $this->render( $this->payload(), array( 'showSpeakers' => false ) );

		$this->assertStringNotContainsString( 'odv-agenda__speakers', $html );
		$this->assertStringNotContainsString( 'Marcel Djaman', $html );
	}

	public function test_descriptions_are_optional(): void {
		$this->assertStringNotContainsString( 'odv-agenda__description', $this->render( $this->payload() ) );

		$html = $this->render( $this->payload(), array( 'showDescription' => true ) );
		$this->assertStringContainsString( '<div class="odv-agenda__description">Cérémonie d\'ouverture avec les organisateurs.</div>', $html );
		$this->assertSame( 5, substr_count( $html, 'odv-agenda__description' ), 'one session has none' );
	}

	public function test_location_and_track_are_shown_as_text(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringContainsString( '<p class="odv-agenda__location">Salle Baobab</p>', $html );
		$this->assertStringContainsString( '<p class="odv-agenda__track">Atelier</p>', $html );
	}

	public function test_every_session_carries_data_track_even_when_empty(): void {
		$payload = $this->with_sessions( array( $this->session( 1, array( 'track' => 'Main "stage"' ) ), $this->session( 2 ), $this->session( 3, array( 'track' => '  ' ) ), $this->session( 4, array( 'parent_id' => 1 ) ) ) );
		$html    = $this->render( $payload );

		$xpath = $this->xpath( $html );
		$items = $xpath->query( '//li[contains(@class,"odv-agenda__session")]' );
		$this->assertSame( 4, $items->length );
		foreach ( $items as $item ) {
			$this->assertTrue( $item->hasAttribute( 'data-track' ), $item->textContent );
		}
		$this->assertStringContainsString( '<li class="odv-agenda__session" data-track="Main &quot;stage&quot;">', $html );
		$this->assertStringContainsString( 'data-track=""', $html );
	}

	public function test_track_filter_buttons_need_two_tracks_and_the_attribute(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringContainsString( '<div class="odv-agenda__filters" role="group" aria-label="Filter by track">', $html );
		$xpath   = $this->xpath( $html );
		$buttons = $xpath->query( '//div[contains(@class,"odv-agenda__filters")]/button' );
		$this->assertSame( 3, $buttons->length, 'All + two tracks' );
		foreach ( $buttons as $button ) {
			$this->assertSame( 'button', $button->getAttribute( 'type' ) );
			$this->assertSame( 'odv-agenda__filter', $button->getAttribute( 'class' ) );
		}
		$this->assertSame( 'All', $buttons->item( 0 )->textContent );
		$this->assertSame( 'true', $buttons->item( 0 )->getAttribute( 'aria-pressed' ) );
		$this->assertSame( '', $buttons->item( 0 )->getAttribute( 'data-track' ) );
		$this->assertSame( 'Scène principale', $buttons->item( 1 )->getAttribute( 'data-track' ) );
		$this->assertSame( 'false', $buttons->item( 1 )->getAttribute( 'aria-pressed' ) );
		$this->assertSame( 'Atelier', $buttons->item( 2 )->textContent );

		$this->assertStringNotContainsString( 'odv-agenda__filters', $this->render( $this->payload(), array( 'trackFilter' => false ) ) );

		$one = $this->with_sessions( array( $this->session( 1, array( 'track' => 'A' ) ), $this->session( 2, array( 'track' => 'A' ) ), $this->session( 3 ) ) );
		$this->assertStringNotContainsString( 'odv-agenda__filters', $this->render( $one ), 'one distinct track' );

		$none = $this->with_sessions( array( $this->session( 1 ), $this->session( 2, array( 'track' => '' ) ) ) );
		$this->assertStringNotContainsString( 'odv-agenda__filters', $this->render( $none ) );
	}

	public function test_a_room_on_another_day_than_its_parent_shows_its_date(): void {
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'starts_at' => '2026-11-12T09:00:00+00:00', 'ends_at' => '2026-11-12T10:00:00+00:00' ) ),
				$this->session( 2, array( 'parent_id' => 1, 'name' => 'Same day room', 'starts_at' => '2026-11-12T09:30:00+00:00', 'ends_at' => '2026-11-12T10:00:00+00:00' ) ),
				$this->session( 3, array( 'parent_id' => 1, 'name' => 'Next day room', 'starts_at' => '2026-11-13T09:30:00+00:00', 'ends_at' => '2026-11-13T10:00:00+00:00' ) ),
			),
			array( 'timezone' => 'UTC' )
		);
		$html = $this->render( $payload );

		$this->assertStringContainsString( '<p class="odv-agenda__time">09:30 – 10:00</p>', $html, 'same day: time only' );
		$this->assertStringContainsString( '<p class="odv-agenda__time">2026-11-13 09:30 – 10:00</p>', $html, 'another day: date and time' );
		$this->assertStringContainsString( '<p class="odv-agenda__time">09:00 – 10:00</p>', $html, 'the parent shows no date' );
	}

	public function test_days_and_local_times_across_daylight_saving_changes(): void {
		// Europe/Paris springs forward on 2026-03-29 at 01:00 UTC (02:00 -> 03:00) and falls back on 2026-10-25 at 01:00 UTC (03:00 -> 02:00).
		$sessions = array(
			$this->session( 1, array( 'name' => 'Spring A', 'starts_at' => '2026-03-28T23:30:00+00:00', 'ends_at' => '2026-03-29T00:15:00+00:00' ) ),
			$this->session( 2, array( 'name' => 'Spring B', 'starts_at' => '2026-03-29T00:30:00+00:00', 'ends_at' => '2026-03-29T01:15:00+00:00' ) ),
			$this->session( 3, array( 'name' => 'Spring C', 'starts_at' => '2026-03-29T01:30:00+00:00', 'ends_at' => '2026-03-29T02:00:00+00:00' ) ),
			$this->session( 4, array( 'name' => 'Spring D', 'starts_at' => '2026-03-29T21:30:00+00:00', 'ends_at' => '2026-03-29T22:15:00+00:00' ) ),
			$this->session( 5, array( 'name' => 'Spring E', 'starts_at' => '2026-03-29T22:30:00+00:00', 'ends_at' => '2026-03-29T23:00:00+00:00' ) ),
		);
		$html = $this->render( $this->with_sessions( $sessions, array( 'timezone' => 'Europe/Paris' ) ) );

		$this->assertSame( array( '2026-03-29', '2026-03-30' ), $this->days( $html ), 'A, B, C, D on the 29th; E on the 30th' );
		foreach ( array(
			'00:30 – 01:15' => 'Spring A',
			'01:30 – 03:15' => 'Spring B',
			'03:30 – 04:00' => 'Spring C',
			'23:30 – 2026-03-30 00:15' => 'Spring D',
			'00:30 – 01:00' => 'Spring E',
		) as $range => $name ) {
			$this->assertMatchesRegularExpression( '#<p class="odv-agenda__time">' . preg_quote( $range, '#' ) . '</p>\s*<h4 class="odv-agenda__title">' . $name . '</h4>#', $html, $name );
		}

		$sessions = array(
			$this->session( 1, array( 'name' => 'Fall A', 'starts_at' => '2026-10-24T22:30:00+00:00', 'ends_at' => '2026-10-24T23:30:00+00:00' ) ),
			$this->session( 2, array( 'name' => 'Fall B', 'starts_at' => '2026-10-25T00:30:00+00:00', 'ends_at' => '2026-10-25T01:00:00+00:00' ) ),
			$this->session( 3, array( 'name' => 'Fall C', 'starts_at' => '2026-10-25T01:30:00+00:00', 'ends_at' => '2026-10-25T02:30:00+00:00' ) ),
			$this->session( 4, array( 'name' => 'Fall D', 'starts_at' => '2026-10-25T22:30:00+00:00', 'ends_at' => '2026-10-25T23:00:00+00:00' ) ),
			$this->session( 5, array( 'name' => 'Fall E', 'starts_at' => '2026-10-25T23:30:00+00:00', 'ends_at' => '2026-10-26T00:00:00+00:00' ) ),
		);
		$html = $this->render( $this->with_sessions( $sessions, array( 'timezone' => 'Europe/Paris' ) ) );

		$this->assertSame( array( '2026-10-25', '2026-10-26' ), $this->days( $html ), 'A to D on the 25th; E on the 26th' );
		foreach ( array(
			'00:30 – 01:30' => 'Fall A',
			'02:30 – 02:00' => 'Fall B',
			'02:30 – 03:30' => 'Fall C',
			'23:30 – 2026-10-26 00:00' => 'Fall D',
			'00:30 – 01:00' => 'Fall E',
		) as $range => $name ) {
			$this->assertMatchesRegularExpression( '#<p class="odv-agenda__time">' . preg_quote( $range, '#' ) . '</p>\s*<h4 class="odv-agenda__title">' . $name . '</h4>#', $html, $name );
		}
	}

	public function test_filters_come_before_the_days(): void {
		$html = $this->render( $this->payload() );

		$this->assertLessThan( strpos( $html, 'odv-agenda__day' ), strpos( $html, 'odv-agenda__filters' ) );
	}

	public function test_sessions_without_a_usable_start_go_last_without_a_day_heading(): void {
		$payload = $this->with_sessions(
			array(
				$this->session( 1, array( 'name' => 'Whenever', 'starts_at' => 'soon', 'ends_at' => null ) ),
				$this->session( 2, array( 'name' => 'Dated' ) ),
			)
		);
		$html = $this->render( $payload );

		$this->assertSame( array( 'Dated', 'Whenever' ), $this->names( $html ) );
		$this->assertSame( array( '2026-11-12' ), $this->days( $html ) );
		$this->assertSame( 2, substr_count( $html, '<div class="odv-agenda__day">' ) );
	}

	public function test_no_sessions_renders_nothing(): void {
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-minimal' ) ) );
		$this->assertSame( '', $this->render( $this->with_sessions( array() ) ) );
		$this->assertSame( '', $this->render( $this->with_sessions( array( $this->session( 1, array( 'name' => '  ' ) ), array( 'id' => 2 ), 'junk' ) ) ) );

		$payload             = $this->payload();
		$payload['sessions'] = 'nope';
		$this->assertSame( '', $this->render( $payload ) );
	}

	public function test_hostile_sessions_are_escaped(): void {
		$html = $this->render( $this->payload( array(), 'payload-v1-hostile' ), array( 'showDescription' => true ) );

		$this->assertNotSame( '', $html );
		$this->assertDomSafe( $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringContainsString( 'data-track="track ', $html );
		$this->assertStringNotContainsString( 'data-track="track <', $html );
	}

	public function test_it_renders_in_every_state(): void {
		foreach ( array( 'live', 'ended open', 'paused', 'cancelled', 'archived' ) as $state ) {
			$html = $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/agenda', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'odv-agenda__session', $html, $state );
			$this->assertStringNotContainsString( 'odv-notice', $html, $state );
		}
	}

	public function test_the_filter_script_loads_only_with_filter_buttons(): void {
		$this->registry( $this->payload() )->render_block( 'odavent-events/agenda', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events-agenda-filter' ), $this->enqueued_scripts );

		$this->enqueued_scripts = array();
		$this->registry( $this->payload() )->render_block( 'odavent-events/agenda', array( 'eventId' => 42, 'trackFilter' => false ) );
		$this->assertSame( array(), $this->enqueued_scripts );
	}
}

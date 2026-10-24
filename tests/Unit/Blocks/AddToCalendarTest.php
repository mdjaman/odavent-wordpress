<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Add_To_Calendar;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class AddToCalendarTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Add_To_Calendar() )->render( Event_View::from_payload( $payload ), $atts );
	}

	private function with_calendar( $calendar ): array {
		return $this->payload( array( 'calendar' => $calendar ) );
	}

	public function test_it_links_google_outlook_and_the_ics_file(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-calendar" data-odv-event="42">', trim( $html ) );
		$this->assertSame( 3, substr_count( $html, 'odv-calendar__link' ) );
		$this->assertMatchesRegularExpression( '#<a class="odv-calendar__link" href="https://calendar\.google\.com/calendar/render\?action=TEMPLATE[^"]*" rel="noopener">Google Calendar</a>#', $html );
		$this->assertMatchesRegularExpression( '#<a class="odv-calendar__link" href="https://outlook\.live\.com/[^"]*" rel="noopener">Outlook</a>#', $html );
		$this->assertLessThan( strpos( $html, 'Outlook' ), strpos( $html, 'Google Calendar' ) );
		$this->assertLessThan( strpos( $html, '.ics file' ), strpos( $html, 'Outlook' ) );
	}

	public function test_the_ics_link_downloads(): void {
		$html = $this->render( $this->payload() );

		$this->assertMatchesRegularExpression( '#<a class="odv-calendar__link" href="https://odavent\.com/events/sommet-tech-abidjan-2026/calendar\.ics" download rel="noopener">#', $html );
		$this->assertSame( 1, substr_count( $html, ' download' ), 'Only the .ics link has download' );
	}

	public function test_each_link_can_be_turned_off(): void {
		$html = $this->render( $this->payload(), array( 'showGoogle' => false ) );
		$this->assertStringNotContainsString( 'Google Calendar', $html );
		$this->assertStringContainsString( 'Outlook', $html );

		$html = $this->render( $this->payload(), array( 'showOutlook' => false, 'showIcs' => false ) );
		$this->assertStringContainsString( 'Google Calendar', $html );
		$this->assertStringNotContainsString( 'Outlook', $html );
		$this->assertStringNotContainsString( 'download', $html );

		$this->assertSame( '', $this->render( $this->payload(), array( 'showGoogle' => false, 'showOutlook' => false, 'showIcs' => false ) ) );
	}

	public function test_only_valid_http_links_are_kept(): void {
		$html = $this->render(
			$this->with_calendar(
				array(
					'google'  => 'javascript:alert(1)',
					'outlook' => 'https://outlook.live.com/calendar/0/deeplink/compose?x=1&y=2',
					'ics'     => '/relative/calendar.ics',
				)
			)
		);

		$this->assertStringNotContainsString( 'Google Calendar', $html );
		$this->assertStringNotContainsString( '.ics file', $html );
		$this->assertStringContainsString( 'Outlook', $html );
		$this->assertStringContainsString( 'x=1&#038;y=2', $html, 'ampersands are escaped in the attribute' );
		$this->assertDomSafe( $html );
	}

	public function test_nothing_valid_renders_nothing(): void {
		foreach ( array( null, array(), 'x', array( 'google' => 5, 'outlook' => array( 'a' ), 'ics' => null ), array( 'google' => 'data:text/html,hi', 'outlook' => 'ftp://x.test/a', 'ics' => '' ) ) as $calendar ) {
			$this->assertSame( '', $this->render( $this->with_calendar( $calendar ) ), json_encode( $calendar ) );
		}
	}

	public function test_hostile_links_are_dropped(): void {
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-hostile' ) ) );
	}

	public function test_it_renders_for_live_ended_and_paused_events(): void {
		foreach ( array( 'live', 'ended open', 'paused' ) as $state ) {
			$html = $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/add-to-calendar', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'odv-calendar__link', $html, $state );
			$this->assertStringNotContainsString( 'odv-notice', $html, $state );
		}
	}

	public function test_it_renders_nothing_for_a_cancelled_or_archived_event(): void {
		foreach ( array( 'cancelled', 'archived' ) as $state ) {
			$this->assertSame( '', $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/add-to-calendar', array( 'eventId' => 42 ) ), $state );
			$this->assertSame( '', $this->render( $this->payload( self::state_overrides()[ $state ] ) ), $state );
		}
	}

	public function test_a_stale_copy_of_a_live_event_still_offers_the_links_but_not_of_a_cancelled_one(): void {
		$live = Event_View::from_payload( $this->payload( self::state_overrides()['live'] ), false, true );
		$this->assertStringContainsString( 'odv-calendar__link', ( new Add_To_Calendar() )->render( $live, array() ) );

		$cancelled = Event_View::from_payload( $this->payload( self::state_overrides()['cancelled'] ), false, true );
		$this->assertSame( '', ( new Add_To_Calendar() )->render( $cancelled, array() ) );
	}
}

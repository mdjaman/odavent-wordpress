<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Brain\Monkey\Filters;
use Odavent\Events\Blocks\Renderers\Countdown;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class CountdownTest extends BlockTestCase {

	const STARTS = '2026-11-12T09:00:00+00:00';
	const ENDS   = '2026-11-13T18:00:00+00:00';

	/** @var int|null Frozen clock. */
	private $now = null;

	protected function setUp(): void {
		parent::setUp();
		$this->now = null;
	}

	private function freeze( string $iso ): void {
		$this->now = ( new \DateTimeImmutable( $iso ) )->getTimestamp();
		Filters\expectApplied( 'odavent_events_now' )->zeroOrMoreTimes()->andReturnUsing(
			function () {
				return $this->now;
			}
		);
	}

	private function render( array $payload, array $atts = array(), bool $stale = false ): string {
		return ( new Countdown() )->render( Event_View::from_payload( $payload, true, $stale ), $atts );
	}

	/**
	 * Value of the span for a unit.
	 */
	private function unit( string $html, string $unit ): string {
		$this->assertSame( 1, preg_match( '#<span class="odv-countdown__value" data-unit="' . $unit . '">(.*?)</span>#', $html, $m ), $unit );

		return $m[1];
	}

	public function test_it_carries_the_event_dates_in_data_attributes(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-countdown" data-odv-event="42" data-starts-at="' . self::STARTS . '" data-ends-at="' . self::ENDS . '"', trim( $html ) );
	}

	public function test_dates_are_normalised_to_iso_8601_with_offset(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );
		$html = $this->render( $this->payload( array( 'starts_at' => '2026-11-12 10:00:00+0100', 'ends_at' => '2026-11-12T12:00Z' ) ) );

		$this->assertStringContainsString( 'data-starts-at="2026-11-12T09:00:00+00:00"', $html );
		$this->assertStringContainsString( 'data-ends-at="2026-11-12T12:00:00+00:00"', $html );
	}

	public function test_the_initial_values_are_computed_from_the_filtered_clock(): void {
		// 2 days, 3 hours, 4 minutes, 5 seconds before the start.
		$this->freeze( '2026-11-10T05:55:55+00:00' );
		$html = $this->render( $this->payload() );

		$this->assertSame( '2', $this->unit( $html, 'days' ) );
		$this->assertSame( '03', $this->unit( $html, 'hours' ) );
		$this->assertSame( '04', $this->unit( $html, 'minutes' ) );
		$this->assertSame( '05', $this->unit( $html, 'seconds' ) );
		$this->assertStringContainsString( 'data-state="upcoming"', $html );
		$this->assertStringContainsString( '<span class="odv-countdown__label">Days</span>', $html );
		$this->assertStringContainsString( '<span class="odv-countdown__label">Seconds</span>', $html );
	}

	public function test_a_start_this_very_second_is_live_not_zero(): void {
		$this->freeze( self::STARTS );

		$this->assertStringContainsString( 'data-state="live"', $this->render( $this->payload() ) );
	}

	public function test_live_and_ended_show_the_labels_not_the_numbers(): void {
		$this->freeze( '2026-11-12T12:00:00+00:00' );
		$html = $this->render( $this->payload() );
		$this->assertStringContainsString( 'data-state="live"', $html );
		$this->assertStringNotContainsString( 'odv-countdown__value', $html );
		$this->assertMatchesRegularExpression( '#<p class="odv-countdown__message">Live now</p>#', $html );

		$this->freeze( '2026-11-14T00:00:00+00:00' );
		$html = $this->render( $this->payload() );
		$this->assertStringContainsString( 'data-state="ended"', $html );
		$this->assertMatchesRegularExpression( '#<p class="odv-countdown__message">Event ended</p>#', $html );
	}

	public function test_an_event_flagged_ended_shows_ended_whatever_the_clock_says(): void {
		$this->freeze( '2026-11-10T00:00:00+00:00' );
		$html = $this->render( $this->payload( array( 'ended' => true ) ) );

		$this->assertStringContainsString( 'data-state="ended"', $html );
		$this->assertStringContainsString( 'data-ended="true"', $html );
	}

	public function test_without_an_end_date_the_event_stays_live_after_it_starts(): void {
		$this->freeze( '2027-01-01T00:00:00+00:00' );
		$html = $this->render( $this->payload( array( 'ends_at' => null ) ) );

		$this->assertStringContainsString( 'data-state="live"', $html );
		$this->assertStringNotContainsString( 'data-ends-at', $html );
	}

	public function test_labels_can_be_customised_and_ride_in_data_attributes(): void {
		$this->freeze( '2026-11-12T12:00:00+00:00' );
		$html = $this->render( $this->payload(), array( 'labelLive' => 'On air "now"', 'labelEnded' => '<b>Over</b>' ) );

		$this->assertStringContainsString( 'data-label-live="On air &quot;now&quot;"', $html );
		$this->assertStringContainsString( 'data-label-ended="Over"', $html, 'tags removed' );
		$this->assertStringContainsString( '>On air &quot;now&quot;</p>', $html );
		$this->assertStringNotContainsString( '<b>', $html );

		$html = $this->render( $this->payload(), array( 'labelLive' => '   ', 'labelEnded' => 5 ) );
		$this->assertStringContainsString( 'data-label-live="Live now"', $html );
		$this->assertStringContainsString( 'data-label-ended="5"', $html );
	}

	public function test_nothing_for_cancelled_archived_or_stale_events(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );

		foreach ( array( 'cancelled', 'archived' ) as $state ) {
			$this->assertSame( '', $this->render( $this->payload( self::state_overrides()[ $state ] ) ), $state );
		}
		$this->assertSame( '', $this->render( $this->payload(), array(), true ), 'stale' );
		$this->assertNotSame( '', $this->render( $this->payload() ), 'control: the same payload, fresh' );
	}

	public function test_a_paused_event_counts_down_until_its_start_then_disappears(): void {
		$paused = $this->payload( self::state_overrides()['paused'] );

		$this->freeze( '2026-11-10T09:00:00+00:00' );
		$this->assertStringContainsString( 'data-state="upcoming"', $this->render( $paused ) );

		$this->freeze( '2026-11-12T09:00:01+00:00' );
		$this->assertSame( '', $this->render( $paused ) );
	}

	public function test_only_a_paused_event_carries_the_paused_flag_for_the_script(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );

		$this->assertStringContainsString( ' data-odv-paused="1"', $this->render( $this->payload( self::state_overrides()['paused'] ) ) );
		foreach ( array( 'live', 'live closed', 'ended open', 'draft' ) as $state ) {
			$this->assertStringNotContainsString( 'data-odv-paused', $this->render( $this->payload( self::state_overrides()[ $state ] ) ), $state );
		}
	}

	public function test_nothing_without_a_usable_start_date(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );

		foreach ( array( null, '', 'tomorrow', '<script>alert(1)</script>', 12345, array( 'x' ), true, '2026-02-31T10:00:00Z' ) as $starts ) {
			$this->assertSame( '', $this->render( $this->payload( array( 'starts_at' => $starts ) ) ), json_encode( $starts ) );
		}
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-minimal' ) ) );
	}

	public function test_a_junk_clock_filter_falls_back_to_real_time(): void {
		Filters\expectApplied( 'odavent_events_now' )->andReturn( 'banana' );

		$html = $this->render( $this->payload( array( 'starts_at' => '2999-01-01T00:00:00Z', 'ends_at' => null ) ) );

		$this->assertStringContainsString( 'data-state="upcoming"', $html );
		$this->assertGreaterThan( 1000, (int) $this->unit( $html, 'days' ) );
	}

	public function test_hostile_event_data_cannot_break_out(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );
		$html = $this->render( $this->payload( array(), 'payload-v1-hostile' ), array( 'labelLive' => '"><script>alert(1)</script>', 'className' => '"><script>' ) );

		$this->assertNotSame( '', $html );
		$this->assertDomSafe( $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_the_registry_enqueues_the_script_only_when_the_block_renders(): void {
		$registry = $this->registry( $this->payload() );
		$registry->render_block( 'odavent-events/countdown', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events-countdown' ), $this->enqueued_scripts );

		$this->enqueued_scripts = array();
		$this->registry( $this->payload( self::state_overrides()['cancelled'] ) )->render_block( 'odavent-events/countdown', array( 'eventId' => 42 ) );
		$this->assertSame( array(), $this->enqueued_scripts );
	}

	public function test_it_carries_no_inline_script_or_style(): void {
		$this->freeze( '2026-11-10T09:00:00+00:00' );
		$html = $this->render( $this->payload() );

		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( 'style=', $html );
		$this->assertDoesNotMatchRegularExpression( '/\sid=/', $html );
	}
}

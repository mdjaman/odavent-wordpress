<?php
namespace Odavent\Events\Tests\Unit\Data;

use Brain\Monkey\Functions;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\TestCase;

class EventViewTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	private function with_status( string $status, $ended = false, $registration_open = true ): array {
		$payload                              = $this->fixture( 'payload-v1-full' );
		$payload['event']['status']            = $status;
		$payload['event']['ended']             = $ended;
		$payload['event']['registration_open'] = $registration_open;

		return $payload;
	}

	/** Data providers run before setUp() loads the plugin autoloader: state values as literals. */
	public function status_map(): array {
		return array(
			'draft (preview)'     => array( 'draft', false, true, 'draft' ),
			'draft (visitor)'     => array( 'draft', false, false, 'draft' ),
			'paused'              => array( 'paused', false, false, 'paused' ),
			'cancelled'           => array( 'cancelled', false, false, 'cancelled' ),
			'archived'            => array( 'archived', false, false, 'archived' ),
			'archived and ended'  => array( 'archived', true, false, 'archived' ),
			'published ended'     => array( 'published', true, false, 'ended' ),
			'published'           => array( 'published', false, false, 'live' ),
			'published ended "1"' => array( 'published', '1', false, 'live' ),
			'unknown status'      => array( 'scheduled', false, false, 'unavailable' ),
		);
	}

	/**
	 * @dataProvider status_map
	 */
	public function test_status_maps_to_state( string $status, $ended, bool $preview, string $expected ): void {
		$view = Event_View::from_payload( $this->with_status( $status, $ended ), $preview );

		$this->assertSame( $expected, $view->state() );
	}

	public function test_draft_without_preview_keeps_no_payload(): void {
		$view = Event_View::from_payload( $this->with_status( 'draft' ) );

		$this->assertSame( Event_View::DRAFT, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertFalse( $view->is_preview() );
		$this->assertSame( array(), $view->event() );
		$this->assertSame( array(), $view->speakers() );
		$this->assertSame( array(), $view->tickets() );
		$this->assertNull( $view->notice_key() );
	}

	public function test_draft_with_preview_keeps_the_payload(): void {
		$view = Event_View::from_payload( $this->with_status( 'draft' ), true );

		$this->assertTrue( $view->has_content() );
		$this->assertTrue( $view->is_preview() );
		$this->assertSame( 'draft_preview', $view->notice_key() );
		$this->assertNotEmpty( $view->speakers() );
	}

	public function test_unknown_status_and_invalid_payloads_have_no_content(): void {
		$bad = array(
			$this->with_status( 'scheduled' ),
			array(),
			array( 'event' => array() ),
			array( 'event' => 'x' ),
			array( 'event' => array( 'id' => '42', 'status' => 'published' ) ),
			array( 'event' => array( 'id' => 0, 'status' => 'published' ) ),
			array( 'event' => array( 'id' => 42 ) ),
		);

		foreach ( $bad as $i => $payload ) {
			$view = Event_View::from_payload( $payload );
			$this->assertSame( Event_View::UNAVAILABLE, $view->state(), "payload #{$i}" );
			$this->assertFalse( $view->has_content(), "payload #{$i}" );
			$this->assertSame( array(), $view->event(), "payload #{$i}" );
		}
	}

	public function test_is_valid_payload(): void {
		$this->assertTrue( Event_View::is_valid_payload( $this->fixture( 'payload-v1-full' ) ) );
		$this->assertTrue( Event_View::is_valid_payload( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->assertFalse( Event_View::is_valid_payload( array() ) );
		$this->assertFalse( Event_View::is_valid_payload( array( 'event' => array() ) ) );
		$this->assertFalse( Event_View::is_valid_payload( array( 'event' => array( 'id' => '7' ) ) ) );
		$this->assertFalse( Event_View::is_valid_payload( array( 'event' => array( 'id' => 7.0 ) ) ) );
		$this->assertFalse( Event_View::is_valid_payload( array( 'event' => array( 'id' => -3 ) ) ) );
	}

	public function can_register_map(): array {
		return array(
			'live open'        => array( 'published', false, true, true ),
			'live closed'      => array( 'published', false, false, false ),
			'live open "true"' => array( 'published', false, 'true', false ),
			'ended open'       => array( 'published', true, true, true ),
			'ended closed'     => array( 'published', true, false, false ),
			'paused'           => array( 'paused', false, true, false ),
			'cancelled'        => array( 'cancelled', false, true, false ),
			'archived'         => array( 'archived', true, true, false ),
			'draft'            => array( 'draft', false, true, false ),
		);
	}

	/**
	 * @dataProvider can_register_map
	 */
	public function test_can_register( string $status, bool $ended, $open, bool $expected ): void {
		$view = Event_View::from_payload( $this->with_status( $status, $ended, $open ), true );

		$this->assertSame( $expected, $view->can_register() );
	}

	public function test_empty_views_cannot_register_and_have_no_content(): void {
		foreach ( array( Event_View::GONE, Event_View::UNAVAILABLE, Event_View::DRAFT, Event_View::LIVE ) as $state ) {
			$view = Event_View::empty( $state );
			$this->assertSame( $state, $view->state() );
			$this->assertFalse( $view->can_register(), $state );
			$this->assertFalse( $view->has_content(), $state );
			$this->assertFalse( $view->is_preview(), $state );
			$this->assertFalse( $view->is_stale(), $state );
		}
	}

	public function test_empty_with_unknown_state_is_unavailable(): void {
		$this->assertSame( Event_View::UNAVAILABLE, Event_View::empty( 'bogus' )->state() );
	}

	public function notice_map(): array {
		return array(
			'live'        => array( 'published', false, null ),
			'ended'       => array( 'published', true, 'ended' ),
			'archived'    => array( 'archived', false, 'ended' ),
			'paused'      => array( 'paused', false, 'paused' ),
			'cancelled'   => array( 'cancelled', false, 'cancelled' ),
			'draft (pvw)' => array( 'draft', false, 'draft_preview' ),
		);
	}

	/**
	 * @dataProvider notice_map
	 */
	public function test_notice_key( string $status, bool $ended, ?string $expected ): void {
		$view = Event_View::from_payload( $this->with_status( $status, $ended ), true );

		$this->assertSame( $expected, $view->notice_key() );
	}

	public function test_notice_key_of_empty_views(): void {
		$this->assertSame( 'unavailable', Event_View::empty( Event_View::UNAVAILABLE )->notice_key() );
		$this->assertNull( Event_View::empty( Event_View::GONE )->notice_key() );
		$this->assertNull( Event_View::empty( Event_View::DRAFT )->notice_key() );
	}

	public function test_stale_flag(): void {
		$view = Event_View::from_payload( $this->fixture( 'payload-v1-full' ), false, true );

		$this->assertTrue( $view->is_stale() );
		$this->assertSame( Event_View::LIVE, $view->state() );
	}

	public function test_accessors_on_minimal_fixture(): void {
		$payload                    = $this->fixture( 'payload-v1-minimal' );
		$payload['event']['status'] = 'published';
		$view                       = Event_View::from_payload( $payload );

		$this->assertTrue( $view->has_content() );
		$this->assertSame( 7, $view->event()['id'] );
		$this->assertSame( 'Small Meetup', $view->event()['name'] );
		$this->assertSame( array(), $view->tickets() );
		$this->assertSame( array(), $view->sessions() );
		$this->assertSame( array(), $view->speakers() );
		$this->assertSame( array(), $view->partners() );
		$this->assertSame( array(), $view->speakers_by_id() );
		$this->assertFalse( $view->can_register() );
	}

	public function test_accessors_on_full_fixture(): void {
		$payload = $this->fixture( 'payload-v1-full' );
		$view    = Event_View::from_payload( $payload );

		$this->assertSame( $payload['event'], $view->event() );
		$this->assertSame( $payload['tickets'], $view->tickets() );
		$this->assertSame( $payload['sessions'], $view->sessions() );
		$this->assertSame( $payload['speakers'], $view->speakers() );
		$this->assertSame( $payload['partners'], $view->partners() );

		$by_id = $view->speakers_by_id();
		$this->assertCount( count( $payload['speakers'] ), $by_id );
		foreach ( $payload['speakers'] as $speaker ) {
			$this->assertSame( $speaker, $by_id[ $speaker['id'] ] );
		}
	}

	public function test_lists_tolerate_non_arrays_and_junk_rows(): void {
		$payload             = $this->fixture( 'payload-v1-full' );
		$payload['tickets']  = 'nope';
		$payload['sessions'] = null;
		$payload['speakers'] = array( 'x', 5, null, array( 'id' => 9, 'name' => 'Ok' ), array( 'id' => '10' ), array( 'name' => 'no id' ) );
		unset( $payload['partners'] );

		$view = Event_View::from_payload( $payload );

		$this->assertSame( array(), $view->tickets() );
		$this->assertSame( array(), $view->sessions() );
		$this->assertSame( array(), $view->partners() );
		$this->assertSame(
			array(
				array( 'id' => 9, 'name' => 'Ok' ),
				array( 'id' => '10' ),
				array( 'name' => 'no id' ),
			),
			$view->speakers()
		);
		$this->assertSame( array( 9 => array( 'id' => 9, 'name' => 'Ok' ) ), $view->speakers_by_id() );
	}

	public function fixtures_through_accessors(): array {
		return array(
			'hostile'           => array( 'payload-v1-hostile', false ),
			'hostile preview'   => array( 'payload-v1-hostile', true ),
			'minimal'           => array( 'payload-v1-minimal', false ),
			'minimal preview'   => array( 'payload-v1-minimal', true ),
			'full'              => array( 'payload-v1-full', false ),
		);
	}

	/**
	 * Every accessor on every fixture, PHPUnit is strict on warnings and notices.
	 *
	 * @dataProvider fixtures_through_accessors
	 */
	public function test_every_accessor_runs_cleanly( string $fixture, bool $preview ): void {
		$view = Event_View::from_payload( $this->fixture( $fixture ), $preview );

		$this->assertIsString( $view->state() );
		$this->assertIsBool( $view->is_preview() );
		$this->assertIsBool( $view->is_stale() );
		$this->assertIsBool( $view->has_content() );
		$this->assertIsArray( $view->event() );
		$this->assertIsArray( $view->tickets() );
		$this->assertIsArray( $view->sessions() );
		$this->assertIsArray( $view->speakers() );
		$this->assertIsArray( $view->partners() );
		$this->assertIsArray( $view->speakers_by_id() );
		$this->assertIsBool( $view->can_register() );
		$notice = $view->notice_key();
		$this->assertTrue( null === $notice || is_string( $notice ) );
	}

	public function test_accessors_on_a_payload_with_only_an_event(): void {
		$view = Event_View::from_payload(
			array(
				'event' => array(
					'id'     => 3,
					'status' => 'published',
				),
			)
		);

		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertSame( array(), $view->tickets() );
		$this->assertSame( array(), $view->speakers_by_id() );
		$this->assertFalse( $view->can_register() );
	}
}

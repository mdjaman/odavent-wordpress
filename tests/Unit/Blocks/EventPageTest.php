<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Blocks\Renderers\Event_Page;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Shortcodes\Shortcodes;
use Odavent\Events\Tests\Support\BlockTestCase;

/**
 * The event page: every section through the existing renderers, in a fixed order, with headings, and the
 * lifecycle and stale notices only once.
 */
class EventPageTest extends BlockTestCase {

	const DEFAULT_ORDER = array( 'header', 'countdown', 'register', 'tickets', 'find-registration', 'speakers', 'agenda', 'sponsors', 'venue', 'calendar' );

	protected function setUp(): void {
		parent::setUp();
		Format::disable_intl( true );
		Event_Page::reset_ids();
		// Before the full payload's start (2026-11-12), so its countdown is upcoming whatever today is.
		Filters\expectApplied( 'odavent_events_now' )->zeroOrMoreTimes()->andReturn( strtotime( '2026-11-01T00:00:00Z' ) );
		// For the shortcode spelling: tags stripped, whitespace collapsed, trimmed (as in ShortcodesTest).
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
			}
		);
	}

	protected function tearDown(): void {
		Format::disable_intl( false );
		Event_Page::reset_ids();
		parent::tearDown();
	}

	private function page( ?array $payload, array $atts = array( 'eventId' => 42 ), bool $stale = false ): string {
		return $this->registry( $payload, Event_View::UNAVAILABLE, array(), 0, $stale )->render_block( 'odavent-events/event-page', $atts );
	}

	/**
	 * The section slugs of the wrappers, in document order.
	 *
	 * @return string[]
	 */
	private function section_slugs( string $html ): array {
		preg_match_all( '#<div class="odv-event-page__section odv-event-page__section--([a-z-]+)" id="odv-([a-z-]+)-\d+(?:-\d+)?">#', $html, $m );
		foreach ( $m[1] as $i => $slug ) {
			$this->assertSame( $slug, $m[2][ $i ], 'the id carries the section slug' );
		}

		return $m[1];
	}

	/**
	 * The headings, in document order.
	 *
	 * @return string[]
	 */
	private function headings( string $html ): array {
		preg_match_all( '#<h2 class="odv-event-page__heading">(.*?)</h2>#s', $html, $m );

		return $m[1];
	}

	public function test_default_order_and_ids(): void {
		$html = $this->page( $this->payload() );

		$this->assertSame( self::DEFAULT_ORDER, $this->section_slugs( $html ) );
		$this->assertSame( self::DEFAULT_ORDER, Event_Page::DEFAULT_SECTIONS );
		$this->assertStringStartsWith( '<div class="odv-event-page" data-odv-event="42">', trim( $html ) );
		foreach ( self::DEFAULT_ORDER as $slug ) {
			$this->assertStringContainsString( '<div class="odv-event-page__section odv-event-page__section--' . $slug . '" id="odv-' . $slug . '-42">', $html );
		}

		// Each section is the existing block's markup.
		foreach ( array( 'odv-header__title', 'odv-countdown', 'odv-register__button', 'odv-tickets__item', 'odv-find-registration', 'odv-speakers__item', 'odv-agenda__session', 'odv-sponsors__item', 'odv-venue__name', 'odv-calendar__link' ) as $marker ) {
			$this->assertStringContainsString( $marker, $html );
		}
		$this->assertStringNotContainsString( 'odv-notice', $html, 'a live event has no notice' );
	}

	public function test_section_renderers_map_to_the_existing_renderers(): void {
		$this->assertSame( self::DEFAULT_ORDER, array_keys( Event_Page::SECTION_RENDERERS ) );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Add_To_Calendar', Event_Page::SECTION_RENDERERS['calendar'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Event_Header', Event_Page::SECTION_RENDERERS['header'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Register_Button', Event_Page::SECTION_RENDERERS['register'] );
		foreach ( Event_Page::SECTION_RENDERERS as $class ) {
			$this->assertTrue( is_subclass_of( $class, Renderer::class ), $class );
		}
		$this->assertSame( self::DEFAULT_ORDER, array_keys( Event_Page::section_labels() ) );
	}

	public function test_headings_are_translated_and_only_on_the_five_sections(): void {
		Functions\when( '__' )->alias(
			static function ( $text, $domain = 'default' ) {
				return 'odavent-events' === $domain ? 'FR:' . $text : $text;
			}
		);

		$html = $this->page( $this->payload() );

		$this->assertSame( array( 'FR:Tickets', 'FR:Speakers', 'FR:Agenda', 'FR:Sponsors and partners', 'FR:Venue' ), $this->headings( $html ) );
		foreach ( array( 'tickets' => 'Tickets', 'speakers' => 'Speakers', 'agenda' => 'Agenda', 'sponsors' => 'Sponsors and partners', 'venue' => 'Venue' ) as $slug => $title ) {
			$this->assertStringContainsString( 'id="odv-' . $slug . '-42">' . "\n\t\t" . '<h2 class="odv-event-page__heading">FR:' . $title . '</h2>', $html, $slug . ': heading first inside its own wrapper' );
		}
		$this->assertSame( 'FR:Sponsors and partners', Event_Page::section_labels()['sponsors'] );
	}

	public function test_sections_attribute_limits_and_keeps_fixed_order(): void {
		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'agenda,speakers' ) );

		$this->assertSame( array( 'speakers', 'agenda' ), $this->section_slugs( $html ) );
		$this->assertStringNotContainsString( 'odv-header', $html );
		$this->assertStringNotContainsString( 'odv-tickets', $html );

		$this->assertSame( array( 'speakers', 'agenda' ), Event_Page::parse_sections( 'agenda,speakers' ) );
		$this->assertSame( array( 'header', 'calendar' ), Event_Page::parse_sections( 'calendar, header' ) );
	}

	public function hostile_sections(): array {
		return array(
			'unknown names'   => array( 'nope,unknown, gallery' ),
			'script'          => array( '"><script>alert(1)</script>' ),
			'5,000 chars'     => array( str_repeat( 'x', 5000 ) ),
			'only separators' => array( ' , ,, ' ),
			'blank'           => array( '' ),
			'spaces'          => array( '   ' ),
			'speakers glued'  => array( 'speakersagenda' ),
		);
	}

	/**
	 * @dataProvider hostile_sections
	 */
	public function test_hostile_sections_fall_back_to_the_default_and_never_echo_the_raw_value( string $raw ): void {
		$this->assertSame( self::DEFAULT_ORDER, Event_Page::parse_sections( $raw ) );

		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => $raw ) );
		$this->assertSame( self::DEFAULT_ORDER, $this->section_slugs( $html ) );
		if ( '' !== trim( $raw, ' ,' ) ) {
			$this->assertStringNotContainsString( $raw, $html );
		}
		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( 'nope', $html );

		// The shortcode spelling of the same value.
		Event_Page::reset_ids();
		$out = ( new Shortcodes( $this->registry( $this->payload() ) ) )->handle( 'event-page', array( 'event_id' => '42', 'sections' => $raw ) );
		$this->assertSame( self::DEFAULT_ORDER, $this->section_slugs( $out ) );
	}

	public function test_case_spaces_and_duplicates_are_normalised(): void {
		$this->assertSame( array( 'speakers', 'agenda' ), Event_Page::parse_sections( ' AGENDA , Speakers,speakers,agenda ' ) );
		$this->assertSame( array( 'speakers' ), Event_Page::parse_sections( str_repeat( 'speakers,', 600 ) ) );
		$this->assertSame( array( 'find-registration' ), Event_Page::parse_sections( 'nope,FIND-REGISTRATION' ) );

		// Not a string (an old or hand-written block comment): the default, never an error.
		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => array( 'speakers' => '<b>' ) ) );
		$this->assertNotSame( '', $html );
		$this->assertStringNotContainsString( '<b>', $html );
	}

	public function test_an_event_without_speakers_partners_or_tickets_has_no_empty_heading_or_wrapper(): void {
		$html = $this->page( $this->payload( array( 'status' => 'published' ), 'payload-v1-minimal' ) );

		$this->assertNotSame( '', $html );
		$this->assertSame( array( 'header', 'register', 'find-registration', 'calendar' ), $this->section_slugs( $html ) );
		$this->assertSame( array(), $this->headings( $html ) );
		$this->assertStringNotContainsString( 'odv-event-page__heading', $html );
		foreach ( array( 'speakers', 'tickets', 'agenda', 'sponsors', 'venue', 'countdown' ) as $slug ) {
			$this->assertStringNotContainsString( 'id="odv-' . $slug . '-', $html, $slug );
			$this->assertStringNotContainsString( 'odv-event-page__section--' . $slug, $html, $slug );
		}

		// Every wrapper holds the section's own markup.
		$doc   = $this->dom( $html );
		$xpath = new \DOMXPath( $doc );
		foreach ( $xpath->query( '//div[contains(@class, "odv-event-page__section ")]' ) as $wrapper ) {
			$this->assertNotSame( '', trim( $wrapper->textContent . $doc->saveHTML( $wrapper ) ) );
			$this->assertGreaterThan( 0, $xpath->query( './/*[starts-with(@class, "odv-")]', $wrapper )->length, $wrapper->getAttribute( 'id' ) );
		}
	}

	public function test_nothing_to_show_renders_nothing(): void {
		$html = $this->page( $this->payload( array( 'status' => 'published' ), 'payload-v1-minimal' ), array( 'eventId' => 42, 'sections' => 'speakers,sponsors,tickets' ) );

		$this->assertSame( '', $html );
		$this->assertSame( array(), $this->enqueued_styles );
	}

	public function test_show_headings_false_removes_all_headings(): void {
		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'showHeadings' => false ) );

		$this->assertStringNotContainsString( 'odv-event-page__heading', $html );
		$this->assertSame( self::DEFAULT_ORDER, $this->section_slugs( $html ) );

		$out = ( new Shortcodes( $this->registry( $this->payload() ) ) )->handle( 'event-page', array( 'event_id' => '42', 'show_headings' => 'no' ) );
		$this->assertStringNotContainsString( 'odv-event-page__heading', $out );
	}

	public function test_second_event_page_gets_unique_ids(): void {
		$first  = $this->page( $this->payload() );
		$second = $this->page( $this->payload() );
		$third  = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'speakers' ) );

		$this->assertStringContainsString( 'id="odv-speakers-42"', $first );
		$this->assertStringContainsString( 'id="odv-speakers-42-2"', $second );
		$this->assertStringContainsString( 'id="odv-speakers-42-3"', $third );

		preg_match_all( '/\sid="([^"]+)"/', $first . $second . $third, $m );
		$this->assertSame( count( $m[1] ), count( array_unique( $m[1] ) ), 'no id twice on the page' );
		$this->assertCount( 21, $m[1] );
	}

	public function test_pass_through_settings_reach_their_sections(): void {
		$html = $this->page(
			$this->payload(),
			array(
				'eventId'          => 42,
				'speakersColumns'  => 2,
				'showSpeakerBio'   => true,
				'sponsorsColumns'  => 6,
				'showAvailability' => true,
				'className'        => 'my-page',
			)
		);

		$this->assertStringStartsWith( '<div class="odv-event-page my-page" data-odv-event="42">', trim( $html ) );
		$this->assertSame( 1, substr_count( $html, 'my-page' ), 'the class goes on the page, not on every section' );
		$this->assertStringContainsString( 'odv-speakers__list--cols-2', $html );
		$this->assertStringContainsString( 'odv-speakers__bio', $html );
		$this->assertStringContainsString( 'odv-sponsors__list--cols-6', $html );
		$this->assertStringContainsString( '118 spots left', $html );

		$defaults = $this->page( $this->payload() );
		$this->assertStringContainsString( 'odv-speakers__list--cols-3', $defaults );
		$this->assertStringNotContainsString( 'odv-speakers__bio', $defaults );
		$this->assertStringContainsString( 'odv-sponsors__list--cols-4', $defaults );
		$this->assertStringNotContainsString( 'spots left', $defaults );

		// Out-of-range values are clamped by the sections, as for the individual blocks.
		$clamped = $this->page( $this->payload(), array( 'eventId' => 42, 'speakersColumns' => 99, 'sponsorsColumns' => 0 ) );
		$this->assertStringContainsString( 'odv-speakers__list--cols-4', $clamped );
		$this->assertStringContainsString( 'odv-sponsors__list--cols-1', $clamped );
	}

	public function test_the_shortcode_matches_the_block(): void {
		$out  = ( new Shortcodes( $this->registry( $this->payload() ) ) )->handle( 'event-page', array( 'event_id' => '42', 'sections' => 'speakers,agenda', 'show_headings' => 'no', 'speakers_columns' => '2' ) );
		Event_Page::reset_ids();
		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'speakers,agenda', 'showHeadings' => false, 'speakersColumns' => 2 ) );

		$this->assertSame( $html, $out );
		$this->assertSame( array( 'speakers', 'agenda' ), $this->section_slugs( $out ) );
		$this->assertStringContainsString( 'odv-speakers__list--cols-2', $out );
	}

	public function lifecycle_states(): array {
		return array(
			'ended closed' => array( 'ended closed', 'This event has ended.', false ),
			'ended open'   => array( 'ended open', 'This event has ended.', true ),
			'archived'     => array( 'archived', 'This event has ended.', false ),
			'paused'       => array( 'paused', 'Registration is temporarily closed.', false ),
			'cancelled'    => array( 'cancelled', 'This event has been cancelled.', false ),
		);
	}

	/**
	 * @dataProvider lifecycle_states
	 */
	public function test_ended_event_through_the_registry_shows_the_ended_notice_exactly_once( string $state, string $notice, bool $links ): void {
		$html = $this->page( $this->payload( self::state_overrides()[ $state ] ) );

		$this->assertSame( 1, substr_count( $html, $notice ), $state );
		$this->assertSame( 1, substr_count( $html, 'class="odv-notice ' ), $state . ': one notice in all' );
		$this->assertStringStartsWith( '<div class="odv-notice odv-notice--', trim( $html ), 'above the whole page' );
		$this->assertStringNotContainsString( 'Event ended', $html );
		$this->assertStringNotContainsString( 'Live now', $html );
		$this->assertStringNotContainsString( 'odv-countdown', $html, 'no countdown for an event that is not upcoming or live' );
		$this->assertStringNotContainsString( 'odv-tickets__message', $html, 'the tickets do not repeat the notice' );

		// Register links exactly as for the individual blocks.
		$this->assertSame( $links, false !== strpos( $html, 'odv-register__button' ), $state );
		$this->assertSame( $links, false !== strpos( $html, 'odv-tickets__register' ), $state );

		// The rest of the page keeps its content.
		foreach ( array( 'odv-header__title', 'odv-tickets__item', 'odv-speakers__item', 'odv-agenda__session', 'odv-sponsors__item' ) as $marker ) {
			$this->assertStringContainsString( $marker, $html, $state );
		}
	}

	public function test_a_draft_preview_says_so_once_and_visitors_see_nothing(): void {
		$this->assertSame( '', $this->page( $this->payload( self::state_overrides()['draft'] ) ) );

		$this->editor = true;
		$html         = $this->page( $this->payload( self::state_overrides()['draft'] ) );

		$this->assertSame( 1, substr_count( $html, 'class="odv-notice ' ) );
		$this->assertStringStartsWith( '<div class="odv-notice odv-notice--draft"', trim( $html ) );
		$this->assertStringNotContainsString( 'once the event is published', $html );
		$this->assertStringNotContainsString( 'odv-register__button', $html );
	}

	public function test_stale_copy_shows_one_stale_notice_and_no_register_link(): void {
		$html = $this->page( $this->payload( self::state_overrides()['live'] ), array( 'eventId' => 42 ), true );

		$this->assertSame( 1, substr_count( $html, 'odv-notice--stale' ) );
		$this->assertSame( 1, substr_count( $html, 'Registration details could not be refreshed.' ) );
		$this->assertStringStartsWith( '<div class="odv-event-page" data-odv-event="42">' . "\n\t" . '<div class="odv-notice odv-notice--stale"', trim( $html ) );
		$this->assertStringNotContainsString( 'odv-register__button', $html );
		$this->assertStringNotContainsString( 'odv-tickets__register', $html );
		$this->assertStringNotContainsString( 'id="odv-register-', $html, 'the register section has nothing left to show' );
		$this->assertStringNotContainsString( 'odv-countdown', $html );
		$this->assertStringContainsString( 'odv-tickets__item', $html );

		// A stale cancelled copy: each notice once.
		$cancelled = $this->page( $this->payload( self::state_overrides()['cancelled'] ), array( 'eventId' => 42 ), true );
		$this->assertSame( 1, substr_count( $cancelled, 'This event has been cancelled.' ) );
		$this->assertSame( 1, substr_count( $cancelled, 'odv-notice--stale' ) );

		// No registration section chosen: nothing to say about registration.
		$speakers = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'speakers' ), true );
		$this->assertStringContainsString( 'odv-speakers__item', $speakers );
		$this->assertStringNotContainsString( 'odv-notice', $speakers );
	}

	public function test_live_event_closed_status_is_kept(): void {
		$html = $this->page( $this->payload( self::state_overrides()['live closed'] ) );

		$this->assertSame( 1, substr_count( $html, 'Registration is closed.' ), 'once, under the Register section' );
		$this->assertStringContainsString( '<p class="odv-register__status">Registration is closed.</p>', $html );
		$this->assertStringNotContainsString( 'odv-tickets__message', $html );
		$this->assertStringContainsString( 'odv-tickets__item', $html );
		$this->assertStringNotContainsString( 'odv-notice', $html );
		$this->assertStringContainsString( 'odv-countdown', $html, 'a live event keeps its countdown' );

		// Without the Register section on the page, the tickets keep their line.
		Event_Page::reset_ids();
		$tickets = $this->page( $this->payload( self::state_overrides()['live closed'] ), array( 'eventId' => 42, 'sections' => 'tickets' ) );
		$this->assertSame( 1, substr_count( $tickets, 'Registration is closed.' ) );
		$this->assertStringContainsString( '<p class="odv-tickets__message">Registration is closed.</p>', $tickets );
	}

	public function test_the_secure_payment_line_is_shown_once_on_an_open_event(): void {
		$line = 'Registration and payment are handled securely by Odavent.';
		foreach ( array( 'live', 'ended open' ) as $state ) {
			Event_Page::reset_ids();
			$html = $this->page( $this->payload( self::state_overrides()[ $state ] ) );

			$this->assertSame( 1, substr_count( $html, $line ), $state );
			$this->assertStringContainsString( 'odv-register__button', $html, $state );
			$this->assertStringContainsString( 'odv-tickets__register', $html, $state . ': the tickets keep their links' );
			$this->assertSame( 1, substr_count( $html, '<p class="odv-register__notice">' ), $state . ': under the Register button' );
		}

		// Without the Register section, the tickets keep the line.
		Event_Page::reset_ids();
		$tickets = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'tickets' ) );
		$this->assertSame( 1, substr_count( $tickets, $line ) );
		$this->assertStringContainsString( 'odv-tickets__register', $tickets );
	}

	public function test_tickets_keep_their_lines_when_the_register_section_renders_nothing(): void {
		$throwing = new class() implements Renderer {
			public function render( Event_View $view, array $atts ): string {
				throw new \RuntimeException( 'boom' );
			}
		};
		$empty = new class() implements Renderer {
			public function render( Event_View $view, array $atts ): string {
				return '';
			}
		};

		foreach ( array( 'empty' => $empty, 'throwing' => $throwing ) as $case => $register ) {
			Event_Page::reset_ids();
			$registry = $this->registry( $this->payload( self::state_overrides()['live closed'] ) );
			$registry->use_renderer( 'event-page', new Event_Page( array( 'register' => $register ) ) );
			$html = $registry->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );

			$this->assertStringNotContainsString( 'id="odv-register-', $html, $case );
			$this->assertStringContainsString( '<p class="odv-tickets__message">Registration is closed.</p>', $html, $case );
		}

		Event_Page::reset_ids();
		$registry = $this->registry( $this->payload() );
		$registry->use_renderer( 'event-page', new Event_Page( array( 'register' => $empty ) ) );
		$html = $registry->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );
		$this->assertSame( 1, substr_count( $html, 'Registration and payment are handled securely by Odavent.' ) );
	}

	public function test_a_stale_copy_with_only_the_register_section_still_explains(): void {
		$html = $this->page( $this->payload(), array( 'eventId' => 42, 'sections' => 'register' ), true );

		$this->assertStringStartsWith( '<div class="odv-event-page" data-odv-event="42">' . "\n\t" . '<div class="odv-notice odv-notice--stale"', trim( $html ) );
		$this->assertSame( 1, substr_count( $html, 'Registration details could not be refreshed.' ) );
		$this->assertStringNotContainsString( 'odv-event-page__section', $html );
		$this->assertStringNotContainsString( 'odv-register__button', $html );
		$this->assertSame( array( 'odavent-events' ), $this->enqueued_styles );

		// A fresh copy with nothing to show still renders nothing.
		$this->assertSame( '', $this->page( $this->payload( array( 'status' => 'published' ), 'payload-v1-minimal' ), array( 'eventId' => 42, 'sections' => 'tickets' ) ) );
	}

	public function test_a_paused_upcoming_event_has_no_countdown_on_the_page_but_keeps_it_as_a_block(): void {
		$payload = $this->payload( self::state_overrides()['paused'] );

		$this->assertStringNotContainsString( 'odv-countdown', $this->page( $payload ) );
		$this->assertStringContainsString( 'data-odv-paused', $this->registry( $payload )->render_block( 'odavent-events/countdown', array( 'eventId' => 42 ) ) );
	}

	public function test_the_in_page_attribute_cannot_be_set_on_a_block(): void {
		$registry = $this->registry( $this->payload( self::state_overrides()['ended closed'] ) );

		$tickets = $registry->render_block( 'odavent-events/tickets', array( 'eventId' => 42, 'inEventPage' => true ) );
		$this->assertStringContainsString( '<p class="odv-tickets__message">This event has ended.</p>', $tickets );

		$countdown = $registry->render_block( 'odavent-events/countdown', array( 'eventId' => 42, 'inEventPage' => true ) );
		$this->assertStringContainsString( 'odv-countdown', $countdown );

		$stale = $this->registry( $this->payload(), Event_View::UNAVAILABLE, array(), 0, true )->render_block( 'odavent-events/register-button', array( 'eventId' => 42, 'inEventPage' => true ) );
		$this->assertStringContainsString( 'odv-notice--stale', $stale );
	}

	public function test_the_register_shown_attribute_cannot_be_set_on_a_block_or_a_shortcode(): void {
		$line = 'Registration and payment are handled securely by Odavent.';
		$atts = array( 'eventId' => 42, 'inEventPage' => true, 'inEventPageRegisterShown' => true );

		$closed = $this->registry( $this->payload( self::state_overrides()['live closed'] ) )->render_block( 'odavent-events/tickets', $atts );
		$this->assertStringContainsString( '<p class="odv-tickets__message">Registration is closed.</p>', $closed );

		$open = $this->registry( $this->payload() )->render_block( 'odavent-events/tickets', $atts );
		$this->assertStringContainsString( $line, $open );

		$shortcodes = new Shortcodes( $this->registry( $this->payload() ) );
		foreach ( array( 'in_event_page_register_shown', 'inEventPageRegisterShown', 'in_event_page' ) as $name ) {
			$this->assertArrayNotHasKey( 'inEventPageRegisterShown', $shortcodes->to_block_attributes( 'tickets', array( $name => 'yes' ) ), $name );
			$this->assertArrayNotHasKey( 'inEventPage', $shortcodes->to_block_attributes( 'tickets', array( $name => 'yes' ) ), $name );
			$this->assertStringContainsString( $line, $shortcodes->handle( 'tickets', array( 'event_id' => '42', $name => 'yes', 'in_event_page' => 'yes' ) ), $name );
		}
		$this->assertSame( array( 'inEventPage', 'inEventPageRegisterShown' ), Event_Page::INTERNAL_ATTRIBUTES );
	}

	public function test_one_failing_section_does_not_hide_the_others(): void {
		$throwing = new class() implements Renderer {
			public function render( Event_View $view, array $atts ): string {
				throw new \RuntimeException( 'boom <secret>' );
			}
		};

		$registry = $this->registry( $this->payload() );
		$registry->use_renderer( 'event-page', new Event_Page( array( 'speakers' => $throwing ) ) );
		$html = $registry->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );

		$this->assertSame( array( 'header', 'countdown', 'register', 'tickets', 'find-registration', 'agenda', 'sponsors', 'venue', 'calendar' ), $this->section_slugs( $html ) );
		$this->assertStringNotContainsString( 'boom', $html );
		$this->assertStringNotContainsString( 'odv-notice', $html, 'visitors see no error' );
		$this->assertNotContains( 'Speakers', $this->headings( $html ) );

		// Editors get the usual error notice where the section would be, never the error text.
		$this->editor = true;
		Event_Page::reset_ids();
		$html = $registry->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );
		$this->assertStringContainsString( 'odv-agenda__session', $html );
		$this->assertSame( 1, substr_count( $html, 'odv-notice--error' ) );
		$this->assertStringNotContainsString( 'boom', $html );
	}

	public function test_hostile_payload_is_safe_on_the_page(): void {
		foreach ( array( false, true ) as $editor ) {
			$this->editor = $editor;
			Event_Page::reset_ids();
			$html = $this->page( $this->payload( array(), 'payload-v1-hostile' ), array( 'eventId' => 42, 'showSpeakerBio' => true, 'showAvailability' => true, 'className' => '"><script>' ) );

			$this->assertNotSame( '', $html );
			$this->assertDomSafe( preg_replace( '#<script type="application/ld\+json">.*?</script>#s', '', $html ) );
			$this->assertSafeHtml( $html );
		}
	}
}

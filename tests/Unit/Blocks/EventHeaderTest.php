<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Event_Header;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class EventHeaderTest extends BlockTestCase {

	private function render( array $payload, array $atts = array(), bool $preview = false ): string {
		return ( new Event_Header() )->render( Event_View::from_payload( $payload, $preview ), $atts );
	}

	public function test_it_shows_name_dates_in_the_event_timezone_venue_images_and_description(): void {
		$html = $this->render(
			$this->payload(
				array(
					'timezone'  => 'America/New_York',
					'starts_at' => '2026-11-12T14:00:00+00:00',
					'ends_at'   => '2026-11-12T20:00:00+00:00',
				)
			)
		);

		$this->assertStringContainsString( '<h2 class="odv-header__title">Sommet Tech d&#039;Abidjan 2026</h2>', $html );
		// 14:00 UTC is 09:00 in New York (EST in November).
		$this->assertStringContainsString( '2026-11-12, 09:00 – 15:00', $html );
		$this->assertStringContainsString( 'class="odv-header__dates"', $html );
		$this->assertStringContainsString( 'Sofitel Abidjan Hôtel Ivoire, Boulevard Hassan II, Cocody, Abidjan, Côte d&#039;Ivoire', $html );
		$this->assertStringContainsString( 'class="odv-header__cover" src="https://odavent.com/storage/media/42/cover-large.webp"', $html );
		$this->assertStringContainsString( 'class="odv-header__logo" src="https://odavent.com/storage/media/41/logo-medium.webp" alt="Sommet Tech d&#039;Abidjan 2026"', $html );
		$this->assertStringContainsString( 'class="odv-header__cover" src="https://odavent.com/storage/media/42/cover-large.webp" alt=""', $html );
		$this->assertStringContainsString( '<div class="odv-header__description">Deux jours', $html );
		$this->assertStringContainsString( '<script type="application/ld+json">', $html );
		$this->assertStringContainsString( '"url":"https://blog.example.com/event-page/"', $html );
	}

	public function test_each_part_can_be_turned_off(): void {
		$html = $this->render(
			$this->payload(),
			array(
				'showCover'       => false,
				'showLogo'        => false,
				'showDescription' => false,
				'showVenue'       => false,
				'showJsonLd'      => false,
			)
		);

		$this->assertStringContainsString( 'odv-header__title', $html );
		$this->assertStringNotContainsString( 'odv-header__cover', $html );
		$this->assertStringNotContainsString( 'odv-header__logo', $html );
		$this->assertStringNotContainsString( 'odv-header__description', $html );
		$this->assertStringNotContainsString( 'odv-header__meta', $html );
		$this->assertStringNotContainsString( 'ld+json', $html );
	}

	public function test_shortcode_style_string_flags_are_understood(): void {
		$html = $this->render( $this->payload(), array( 'showJsonLd' => 'false', 'showCover' => '0', 'showLogo' => 'no' ) );

		$this->assertStringNotContainsString( 'ld+json', $html );
		$this->assertStringNotContainsString( 'odv-header__cover', $html );
		$this->assertStringNotContainsString( 'odv-header__logo', $html );
	}

	public function test_a_draft_preview_never_emits_json_ld(): void {
		$html = $this->render( $this->payload( array( 'status' => 'draft' ) ), array(), true );

		$this->assertStringContainsString( 'odv-header__title', $html );
		$this->assertStringNotContainsString( 'ld+json', $html );
	}

	public function test_missing_parts_leave_no_empty_elements(): void {
		$html = $this->render( $this->payload( array( 'status' => 'published' ), 'payload-v1-minimal' ) );

		$this->assertStringContainsString( 'Small Meetup', $html );
		$this->assertStringNotContainsString( 'odv-header__dates', $html );
		$this->assertStringNotContainsString( 'odv-header__meta', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'odv-header__description', $html );
	}

	public function test_an_event_with_nothing_to_show_renders_nothing(): void {
		$payload          = $this->payload( array( 'status' => 'published' ), 'payload-v1-minimal' );
		$payload['event'] = array(
			'id'     => 7,
			'status' => 'published',
			'name'   => array( 'not', 'text' ),
			'venue'  => 'nope',
		);

		$this->assertSame( '', $this->render( $payload ) );
	}

	public function test_hostile_payload_is_escaped(): void {
		$html = $this->render( $this->fixture( 'payload-v1-hostile' ) );

		$this->assertSafeHtml( $html );
		$this->assertStringContainsString( 'odv-header__title', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'data:text', $html );
		$this->assertStringNotContainsString( 'onerror=', $html );
		$this->assertStringNotContainsString( 'odv-header__cover', $html, 'data: cover dropped' );
		$this->assertStringNotContainsString( 'odv-header__logo', $html, 'javascript: logo dropped' );
	}
}

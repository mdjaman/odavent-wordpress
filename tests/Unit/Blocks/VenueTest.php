<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Venue;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class VenueTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Venue() )->render( Event_View::from_payload( $payload ), $atts );
	}

	private function with_venue( $venue ): array {
		return $this->payload( array( 'venue' => $venue ) );
	}

	public function test_it_shows_the_venue_as_text_with_a_map_link(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-venue" data-odv-event="42">', trim( $html ) );
		$this->assertStringContainsString( '<h3 class="odv-venue__name">Sofitel Abidjan Hôtel Ivoire</h3>', $html );
		$this->assertStringContainsString( '<p class="odv-venue__address">Boulevard Hassan II, Cocody, Abidjan, Côte d&#039;Ivoire</p>', $html );
	}

	public function test_the_map_link_is_an_encoded_openstreetmap_search(): void {
		$html = $this->render( $this->payload() );

		$this->assertSame( 1, preg_match( '#<a class="odv-venue__map" href="([^"]*)" rel="noopener">#', $html, $m ) );
		$this->assertSame(
			'https://www.openstreetmap.org/search?query=' . rawurlencode( "Boulevard Hassan II, Cocody, Abidjan, Côte d'Ivoire" ),
			html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' )
		);
	}

	public function test_the_map_query_encodes_accents_spaces_ampersands_and_hashes(): void {
		$html = $this->render( $this->with_venue( array( 'name' => 'Salle', 'address' => 'Rue de l\'Élysée & Fils #12', 'city' => 'Saint-Étienne', 'country' => 'France' ) ) );

		$this->assertSame( 1, preg_match( '#<a class="odv-venue__map" href="([^"]*)"#', $html, $m ) );
		$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->assertSame( 'https://www.openstreetmap.org/search?query=' . rawurlencode( 'Rue de l\'Élysée & Fils #12, Saint-Étienne, France' ), $href );
		$this->assertStringContainsString( '%C3%89', $href, 'É encoded as UTF-8 octets' );
		$this->assertStringContainsString( '%26', $href, '& encoded' );
		$this->assertStringContainsString( '%23', $href, '# encoded' );
		$this->assertStringContainsString( '%20', $href, 'spaces encoded' );
		$this->assertSame( 1, substr_count( $href, '?' ) );
		$this->assertSame( 0, substr_count( $href, '#' ) );
		$this->assertSame( 0, preg_match( '/[ &#]/', substr( $href, strpos( $href, 'query=' ) + 6 ) ) );
	}

	public function test_the_map_link_can_be_turned_off(): void {
		$html = $this->render( $this->payload(), array( 'showMapLink' => false ) );

		$this->assertStringContainsString( 'Sofitel Abidjan', $html );
		$this->assertStringNotContainsString( 'odv-venue__map', $html );
		$this->assertStringNotContainsString( 'openstreetmap', $html );
	}

	public function test_a_missing_empty_or_malformed_venue_renders_nothing(): void {
		foreach ( array( null, array(), 'Paris', 12, array( 'name' => '', 'city' => '   ' ), array( 'name' => array( 'x' ), 'address' => false ) ) as $venue ) {
			$this->assertSame( '', $this->render( $this->with_venue( $venue ) ), json_encode( $venue ) );
		}
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-minimal' ) ) );
	}

	public function test_a_venue_with_only_some_parts_shows_those_parts(): void {
		$html = $this->render( $this->with_venue( array( 'name' => 'Hall', 'city' => 'Dakar' ) ) );

		$this->assertStringContainsString( '<h3 class="odv-venue__name">Hall</h3>', $html );
		$this->assertStringContainsString( '<p class="odv-venue__address">Dakar</p>', $html );
		$this->assertStringContainsString( 'query=Dakar"', $html );
	}

	public function test_a_venue_with_only_a_name_uses_it_for_the_map(): void {
		$html = $this->render( $this->with_venue( array( 'name' => 'Palais des Congrès' ) ) );

		$this->assertStringNotContainsString( 'odv-venue__address', $html );
		$this->assertStringContainsString( 'query=' . rawurlencode( 'Palais des Congrès' ), $html );
	}

	public function test_hostile_venue_text_is_escaped(): void {
		$html = $this->render( $this->payload( array(), 'payload-v1-hostile' ) );

		$this->assertNotSame( '', $html );
		$this->assertDomSafe( $html );
		$this->assertStringContainsString( 'DROP TABLE events', $html );
		$this->assertStringContainsString( '{{7*7}}', $html );
		$this->assertStringContainsString( '%7B%7B7%2A7%7D%7D', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_it_renders_in_every_state_with_the_venue_stale_included(): void {
		foreach ( self::state_overrides() as $state => $overrides ) {
			if ( 'draft' === $state ) {
				continue;
			}
			$html = $this->registry( $this->payload( $overrides ) )->render_block( 'odavent-events/venue', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'Sofitel Abidjan', $html, $state );
		}
		$this->assertStringContainsString( 'Sofitel Abidjan', $this->registry( $this->payload(), Event_View::UNAVAILABLE, array(), 0, true )->render_block( 'odavent-events/venue', array( 'eventId' => 42 ) ) );
	}
}

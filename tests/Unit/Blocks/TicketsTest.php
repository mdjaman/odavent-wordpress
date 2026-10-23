<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Tickets;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Tests\Support\BlockTestCase;

class TicketsTest extends BlockTestCase {

	protected function setUp(): void {
		parent::setUp();
		Format::disable_intl( true );
	}

	protected function tearDown(): void {
		Format::disable_intl( false );
		parent::tearDown();
	}

	private function render( array $payload, array $atts = array() ): string {
		return ( new Tickets() )->render( Event_View::from_payload( $payload ), $atts );
	}

	/**
	 * The <li> of a ticket by name.
	 */
	private function item( string $html, string $name ): string {
		$this->assertSame( 1, preg_match( '#<li class="odv-tickets__item[^"]*">(?:(?!</li>).)*' . preg_quote( $name, '#' ) . '.*?</li>#s', $html, $m ), $name );

		return $m[0];
	}

	public function test_it_lists_tickets_with_prices(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-tickets" data-odv-event="42">', trim( $html ) );
		$this->assertStringContainsString( '<ul class="odv-tickets__list">', $html );
		$standard = $this->item( $html, 'Pass Standard' );
		$this->assertStringContainsString( '<h3 class="odv-tickets__name">Pass Standard</h3>', $standard );
		$this->assertStringContainsString( '<p class="odv-tickets__price">25,000 XOF</p>', $standard );
		$this->assertStringContainsString( '<div class="odv-tickets__description">Accès aux deux jours', $standard );
	}

	public function test_free_tickets_say_free(): void {
		$payload                           = $this->payload();
		$payload['tickets'][0]['price']    = 0;
		$payload['tickets'][0]['is_free']  = true;

		$this->assertStringContainsString( '<p class="odv-tickets__price">Free</p>', $this->item( $this->render( $payload ), 'Pass Standard' ) );
	}

	public function test_an_unusable_price_is_hidden(): void {
		$payload                        = $this->payload();
		$payload['tickets'][0]['price'] = 'abc';

		$this->assertStringNotContainsString( 'odv-tickets__price', $this->item( $this->render( $payload ), 'Pass Standard' ) );
	}

	public function test_a_full_ticket_is_sold_out_without_a_link(): void {
		$investor = $this->item( $this->render( $this->payload() ), 'Pass Investisseur' );

		$this->assertStringContainsString( 'odv-tickets__item--sold-out', $investor );
		$this->assertStringContainsString( 'Sold out', $investor );
		$this->assertStringNotContainsString( 'odv-tickets__register', $investor );
		$this->assertStringNotContainsString( 'href=', $investor );
	}

	public function test_an_open_ticket_links_to_registration_with_the_ticket(): void {
		$standard = $this->item( $this->render( $this->payload() ), 'Pass Standard' );

		$this->assertSame( 1, preg_match( '/<a class="odv-tickets__register" href="([^"]*)"/', $standard, $m ) );
		$href = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$this->assertStringContainsString( 'ticket=101', $href );
		$this->assertStringContainsString( 'utm_source=wordpress', $href );
		$this->assertStringContainsString( 'return=' . rawurlencode( self::PERMALINK ), $href );
	}

	public function test_the_secure_notice_follows_the_register_links(): void {
		$this->assertStringContainsString( '<p class="odv-register__notice">Registration and payment are handled securely by Odavent.</p>', $this->render( $this->payload() ) );

		$payload                          = $this->payload();
		$payload['tickets'][0]['on_sale'] = false;
		$this->assertStringNotContainsString( 'odv-register__notice', $this->render( $payload ), 'No link, no notice.' );
	}

	public function test_a_ticket_off_sale_has_no_link(): void {
		$payload                          = $this->payload();
		$payload['tickets'][0]['on_sale'] = false;
		$standard                         = $this->item( $this->render( $payload ), 'Pass Standard' );

		$this->assertStringNotContainsString( 'href=', $standard );
		$this->assertStringContainsString( 'Not on sale', $standard );
	}

	public function test_remaining_spots_only_with_show_availability(): void {
		$this->assertStringNotContainsString( '118', $this->render( $this->payload() ) );
		$this->assertStringContainsString( '118 spots left', $this->item( $this->render( $this->payload(), array( 'showAvailability' => true ) ), 'Pass Standard' ) );

		$payload                                = $this->payload();
		$payload['tickets'][0]['spots_remaining'] = '118';
		$this->assertStringNotContainsString( 'spots left', $this->render( $payload, array( 'showAvailability' => true ) ), 'Only an int is a count.' );

		$payload['tickets'][0]['spots_remaining'] = null;
		$this->assertStringNotContainsString( 'spots left', $this->render( $payload, array( 'showAvailability' => true ) ) );
	}

	public function test_descriptions_can_be_hidden(): void {
		$this->assertStringNotContainsString( 'odv-tickets__description', $this->render( $this->payload(), array( 'showDescription' => false ) ) );
	}

	public function test_the_button_label_can_be_changed(): void {
		$this->assertStringContainsString( '>Buy</a>', $this->render( $this->payload(), array( 'buttonLabel' => 'Buy' ) ) );
	}

	public function test_an_empty_ticket_list_renders_nothing(): void {
		foreach ( array( array(), 'nope', array( 'junk', 3 ) ) as $tickets ) {
			$payload            = $this->payload();
			$payload['tickets'] = $tickets;
			$this->assertSame( '', $this->render( $payload ) );
		}
	}

	public function test_hostile_payload_is_escaped(): void {
		$html = $this->render( $this->fixture( 'payload-v1-hostile' ), array( 'showAvailability' => true ) );

		$this->assertSafeHtml( $html );
		$this->assertStringContainsString( 'odv-tickets__item', $html );
		$this->assertStringNotContainsString( 'odv-tickets__register', $html, 'register_url is javascript:' );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}
}

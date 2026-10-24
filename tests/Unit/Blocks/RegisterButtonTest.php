<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Brain\Monkey\Functions;
use Odavent\Events\Blocks\Renderers\Register_Button;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class RegisterButtonTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Register_Button() )->render( Event_View::from_payload( $payload ), $atts );
	}

	private function href( string $html ): string {
		$this->assertSame( 1, preg_match( '/<a class="odv-register__button" href="([^"]*)"/', $html, $m ), $html );

		return html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	public function test_the_link_is_tagged_and_returns_to_this_page(): void {
		$html = $this->render( $this->payload() );
		$href = $this->href( $html );

		$this->assertStringStartsWith( 'https://odavent.com/events/sommet-tech-abidjan-2026/register?', $href );
		$this->assertStringContainsString( 'utm_source=wordpress', $href );
		$this->assertStringContainsString( 'utm_medium=plugin', $href );
		$this->assertStringContainsString( 'return=' . rawurlencode( self::PERMALINK ), $href );
		$this->assertStringNotContainsString( 'ticket=', $href );
		$this->assertStringContainsString( '>Register</a>', $html );
		$this->assertStringContainsString( '<p class="odv-register__notice">Registration and payment are handled securely by Odavent.</p>', $html );
	}

	public function test_the_home_page_is_the_return_address_without_a_permalink(): void {
		Functions\when( 'get_permalink' )->justReturn( false );
		$href = $this->href( $this->render( $this->payload() ) );

		$this->assertStringContainsString( 'return=' . rawurlencode( 'https://blog.example.com/' ), $href );
	}

	public function test_a_ticket_is_preselected(): void {
		$href = $this->href( $this->render( $this->payload(), array( 'ticketId' => 101 ) ) );

		$this->assertStringContainsString( 'ticket=101', $href );
	}

	public function test_an_unknown_ticket_links_without_preselection(): void {
		$href = $this->href( $this->render( $this->payload(), array( 'ticketId' => 999 ) ) );

		$this->assertStringNotContainsString( 'ticket=', $href );
	}

	public function test_a_sold_out_ticket_gives_no_link(): void {
		$html = $this->render( $this->payload(), array( 'ticketId' => 102 ) );

		$this->assertStringNotContainsString( 'odv-register__button', $html );
		$this->assertStringNotContainsString( 'href=', $html );
		$this->assertStringContainsString( 'Sold out', $html );
		$this->assertStringNotContainsString( 'odv-register__notice', $html );
	}

	public function test_a_ticket_off_sale_gives_no_link(): void {
		$payload                          = $this->payload();
		$payload['tickets'][0]['on_sale'] = false;
		$html                             = $this->render( $payload, array( 'ticketId' => 101 ) );

		$this->assertStringNotContainsString( 'href=', $html );
		$this->assertStringContainsString( 'odv-register__status', $html );
	}

	public function test_no_buyable_ticket_means_sold_out_instead_of_the_button(): void {
		$payload                          = $this->payload();
		$payload['tickets'][0]['is_full'] = true;
		$html                             = $this->render( $payload );

		$this->assertStringNotContainsString( 'odv-register__button', $html );
		$this->assertStringContainsString( '<p class="odv-register__status">Sold out</p>', $html );

		$payload                          = $this->payload();
		$payload['tickets'][0]['on_sale'] = false;
		$this->assertStringContainsString( '<p class="odv-register__status">Sold out</p>', $this->render( $payload ), 'Nothing on sale.' );
	}

	public function test_one_buyable_ticket_keeps_the_button(): void {
		$payload                          = $this->payload();
		$payload['tickets'][1]['is_full'] = false;
		$payload['tickets'][1]['on_sale'] = true;
		$payload['tickets'][0]['is_full'] = true;

		$this->assertStringContainsString( 'odv-register__button', $this->render( $payload ) );
	}

	public function test_an_event_without_tickets_keeps_the_button(): void {
		foreach ( array( array(), null ) as $tickets ) {
			$payload            = $this->payload();
			$payload['tickets'] = $tickets;
			$this->assertStringContainsString( 'odv-register__button', $this->render( $payload ) );
		}
	}

	public function test_the_label_can_be_changed_and_is_escaped(): void {
		$html = $this->render( $this->payload(), array( 'label' => 'Book <b>now</b>' ) );

		$this->assertStringContainsString( '>Book &lt;b&gt;now&lt;/b&gt;</a>', $html );
	}

	public function test_an_empty_label_falls_back_to_register(): void {
		$this->assertStringContainsString( '>Register</a>', $this->render( $this->payload(), array( 'label' => '   ' ) ) );
	}

	public function test_no_usable_register_url_renders_nothing(): void {
		foreach ( array( 'javascript:alert(1)', 'data:text/html,x', '//odavent.com/x', null, 42 ) as $url ) {
			$this->assertSame( '', $this->render( $this->payload( array( 'register_url' => $url ) ) ) );
		}
	}

	public function test_hostile_payload_is_escaped(): void {
		$html = $this->render( $this->fixture( 'payload-v1-hostile' ), array( 'ticketId' => 101 ) );

		$this->assertSame( '', $html, 'The hostile register_url is javascript:, so there is no button.' );
	}
}

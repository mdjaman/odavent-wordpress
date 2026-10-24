<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Find_Registration;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class FindRegistrationTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Find_Registration() )->render( Event_View::from_payload( $payload ), $atts );
	}

	public function test_it_links_to_find_registration(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringContainsString( '<a class="odv-find-registration__link" href="https://odavent.com/events/sommet-tech-abidjan-2026/find-registration">Already registered? Get my ticket</a>', $html );
	}

	public function test_the_label_can_be_changed(): void {
		$this->assertStringContainsString( '>My ticket</a>', $this->render( $this->payload(), array( 'label' => 'My ticket' ) ) );
	}

	public function test_no_usable_url_renders_nothing(): void {
		foreach ( array( 'javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4=', null, array() ) as $url ) {
			$this->assertSame( '', $this->render( $this->payload( array( 'find_registration_url' => $url ) ) ) );
		}
	}

	public function test_hostile_payload_renders_nothing(): void {
		$this->assertSame( '', $this->render( $this->fixture( 'payload-v1-hostile' ) ) );
	}

	public function test_hidden_for_gone_through_the_registry(): void {
		$this->editor = false;
		$this->assertSame( '', $this->registry( null, Event_View::GONE )->render_block( 'odavent-events/find-registration', array( 'eventId' => 42 ) ) );
	}
}

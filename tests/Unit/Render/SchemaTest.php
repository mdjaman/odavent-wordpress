<?php
namespace Odavent\Events\Tests\Unit\Render;

use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Schema;
use Odavent\Events\Tests\Support\RenderTestCase;

class SchemaTest extends RenderTestCase {

	const PERMALINK = 'https://blog.example.com/summit/';

	private function view( array $event_overrides = array(), ?array $payload = null ): Event_View {
		$payload          = $payload ?? $this->fixture( 'payload-v1-full' );
		$payload['event'] = array_merge( $payload['event'], $event_overrides );

		return Event_View::from_payload( $payload );
	}

	/**
	 * @return array The decoded JSON-LD document.
	 */
	private function decode( string $html ): array {
		$this->assertStringStartsWith( '<script type="application/ld+json">', $html );
		$this->assertStringEndsWith( '</script>', $html );

		$json = substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );
		$data = json_decode( $json, true );
		$this->assertIsArray( $data, 'The JSON-LD is not valid JSON: ' . json_last_error_msg() );

		return $data;
	}

	public function test_the_output_is_valid_json_ld_for_an_event(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view(), self::PERMALINK ) );

		$this->assertSame( 'https://schema.org', $data['@context'] );
		$this->assertSame( 'Event', $data['@type'] );
		$this->assertSame( "Sommet Tech d'Abidjan 2026", $data['name'] );
		$this->assertSame( self::PERMALINK, $data['url'] );
		$this->assertSame( 'https://schema.org/EventScheduled', $data['eventStatus'] );
	}

	public function test_dates_carry_the_event_timezone_offset(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'timezone' => 'Europe/Paris' ) ), self::PERMALINK ) );

		$this->assertSame( '2026-11-12T10:00:00+01:00', $data['startDate'] );
		$this->assertSame( '2026-11-13T19:00:00+01:00', $data['endDate'] );
	}

	public function test_a_missing_end_date_is_left_out(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'ends_at' => null ) ), self::PERMALINK ) );

		$this->assertArrayHasKey( 'startDate', $data );
		$this->assertArrayNotHasKey( 'endDate', $data );
	}

	public function test_status_mapping(): void {
		$map = array(
			'published' => 'https://schema.org/EventScheduled',
			'cancelled' => 'https://schema.org/EventCancelled',
			// Registration is paused, not the event: the date stays valid.
			'paused'    => 'https://schema.org/EventScheduled',
			'archived'  => 'https://schema.org/EventScheduled',
		);

		foreach ( $map as $status => $expected ) {
			$data = $this->decode( Schema::event_json_ld( $this->view( array( 'status' => $status ) ), self::PERMALINK ) );

			$this->assertSame( $expected, $data['eventStatus'], $status );
		}

		$ended = $this->decode( Schema::event_json_ld( $this->view( array( 'status' => 'published', 'ended' => true ) ), self::PERMALINK ) );
		$this->assertSame( 'https://schema.org/EventScheduled', $ended['eventStatus'] );
	}

	public function test_no_start_date_gives_an_empty_string(): void {
		$this->assertSame( '', Schema::event_json_ld( $this->view( array( 'starts_at' => null ) ), self::PERMALINK ) );
		$this->assertSame( '', Schema::event_json_ld( $this->view( array( 'starts_at' => 'garbage' ) ), self::PERMALINK ) );
	}

	public function test_views_without_content_give_an_empty_string(): void {
		foreach ( array( Event_View::GONE, Event_View::UNAVAILABLE, Event_View::DRAFT ) as $state ) {
			$this->assertSame( '', Schema::event_json_ld( Event_View::empty( $state ), self::PERMALINK ), $state );
		}
	}

	public function test_a_draft_preview_gets_no_structured_data(): void {
		$payload                   = $this->fixture( 'payload-v1-full' );
		$payload['event']['status'] = 'draft';

		$view = Event_View::from_payload( $payload, true );

		$this->assertTrue( $view->is_preview() );
		$this->assertSame( '', Schema::event_json_ld( $view, self::PERMALINK ) );
	}

	public function test_location_is_a_place_with_a_postal_address(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view(), self::PERMALINK ) );

		$this->assertSame( 'Place', $data['location']['@type'] );
		$this->assertSame( 'Sofitel Abidjan Hôtel Ivoire', $data['location']['name'] );
		$this->assertSame( 'PostalAddress', $data['location']['address']['@type'] );
		$this->assertSame( 'Boulevard Hassan II, Cocody', $data['location']['address']['streetAddress'] );
		$this->assertSame( 'Abidjan', $data['location']['address']['addressLocality'] );
		$this->assertSame( "Côte d'Ivoire", $data['location']['address']['addressCountry'] );
	}

	public function test_no_venue_means_no_location(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'venue' => null ) ), self::PERMALINK ) );

		$this->assertArrayNotHasKey( 'location', $data );
	}

	public function test_image_is_the_cover_then_the_logo_and_only_if_safe(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view(), self::PERMALINK ) );
		$this->assertSame( array( 'https://odavent.com/storage/media/42/cover-large.webp' ), $data['image'] );

		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'cover_url' => null ) ), self::PERMALINK ) );
		$this->assertSame( array( 'https://odavent.com/storage/media/41/logo-medium.webp' ), $data['image'] );

		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'cover_url' => 'javascript:alert(1)', 'logo_url' => null ) ), self::PERMALINK ) );
		$this->assertArrayNotHasKey( 'image', $data );
	}

	public function test_offers_come_from_tickets_on_sale_only(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view(), self::PERMALINK ) );

		$this->assertCount( 1, $data['offers'] );
		$offer = $data['offers'][0];
		$this->assertSame( 'Offer', $offer['@type'] );
		$this->assertSame( 'Pass Standard', $offer['name'] );
		$this->assertSame( 25000, $offer['price'] );
		$this->assertSame( 'XOF', $offer['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $offer['availability'] );
		$this->assertSame( self::PERMALINK, $offer['url'] );
	}

	public function test_a_sold_out_ticket_on_sale_is_marked_sold_out(): void {
		$payload                           = $this->fixture( 'payload-v1-full' );
		$payload['tickets'][0]['is_full']  = true;
		$payload['tickets'][0]['price']    = 12.5;
		$payload['tickets'][0]['is_free']  = false;

		$data = $this->decode( Schema::event_json_ld( $this->view( array(), $payload ), self::PERMALINK ) );

		$this->assertSame( 'https://schema.org/SoldOut', $data['offers'][0]['availability'] );
		$this->assertSame( 12.5, $data['offers'][0]['price'] );
	}

	public function test_no_tickets_on_sale_means_no_offers(): void {
		$payload            = $this->fixture( 'payload-v1-full' );
		$payload['tickets'] = array();

		$data = $this->decode( Schema::event_json_ld( $this->view( array(), $payload ), self::PERMALINK ) );

		$this->assertArrayNotHasKey( 'offers', $data );
	}

	public function test_a_free_ticket_has_price_zero(): void {
		$payload                          = $this->fixture( 'payload-v1-full' );
		$payload['tickets'][0]['price']   = 0;
		$payload['tickets'][0]['is_free'] = true;

		$data = $this->decode( Schema::event_json_ld( $this->view( array(), $payload ), self::PERMALINK ) );

		$this->assertSame( 0, $data['offers'][0]['price'] );
	}

	public function test_a_name_with_a_script_close_tag_cannot_close_the_script_element(): void {
		$name = 'Summit </script><script>alert(1)</script>';
		$html = Schema::event_json_ld( $this->view( array( 'name' => $name ) ), self::PERMALINK );

		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ) );
		$this->assertStringEndsWith( '</script>', $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '<script' ) );
		$this->decode( $html );
	}

	public function test_the_hostile_fixture_cannot_break_out_of_the_script_element(): void {
		$view = Event_View::from_payload( $this->fixture( 'payload-v1-hostile' ) );
		$html = Schema::event_json_ld( $view, 'https://blog.example.com/"></script><script>alert(1)</script>' );

		$this->assertNotSame( '', $html );
		$this->assertSame( 1, substr_count( strtolower( $html ), '</script>' ) );
		$this->assertSame( 1, substr_count( strtolower( $html ), '<script' ) );
		$data = $this->decode( $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'data:text', $html );
		$this->assertArrayNotHasKey( 'image', $data );
	}

	public function test_angle_brackets_and_ampersands_are_hex_escaped_inside_the_json(): void {
		// Text that survives tag stripping but still carries the characters a script element is sensitive to.
		$html = Schema::event_json_ld( $this->view( array( 'name' => '5 < 6 && 7 > 3' ) ), self::PERMALINK );

		$body = substr( $html, strlen( '<script type="application/ld+json">' ), -strlen( '</script>' ) );

		$this->assertStringNotContainsString( '<', $body );
		$this->assertStringNotContainsString( '>', $body );
		$this->assertStringNotContainsString( '&', $body );
		$this->assertStringContainsString( '\\u003C', $body );
		$this->assertStringContainsString( '\\u003E', $body );
		$this->assertStringContainsString( '\\u0026', $body );

		$data = json_decode( $body, true );
		$this->assertSame( '5 < 6 && 7 > 3', $data['name'] );
	}

	public function test_slashes_in_urls_are_not_escaped(): void {
		$html = Schema::event_json_ld( $this->view(), self::PERMALINK );

		$this->assertStringContainsString( '"url":"https://blog.example.com/summit/"', $html );
	}

	public function test_a_hostile_permalink_is_left_out(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view(), 'javascript:alert(1)' ) );

		$this->assertArrayNotHasKey( 'url', $data );
		$this->assertArrayNotHasKey( 'url', $data['offers'][0] );
	}

	public function bad_currencies(): array {
		return array(
			'markup'   => array( '<b>XOF</b>' ),
			'null'     => array( null ),
			'int'      => array( 123 ),
			'array'    => array( array( 'x' ) ),
			'empty'    => array( '' ),
			'too long' => array( 'EURO' ),
		);
	}

	/**
	 * @dataProvider bad_currencies
	 */
	public function test_a_bad_currency_does_not_throw_and_leaves_priceCurrency_out( $currency ): void {
		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'currency' => $currency ) ), self::PERMALINK ) );

		$this->assertCount( 1, $data['offers'] );
		$this->assertSame( 'Pass Standard', $data['offers'][0]['name'] );
		$this->assertArrayNotHasKey( 'priceCurrency', $data['offers'][0] );
	}

	public function test_a_missing_currency_does_not_throw(): void {
		$payload = $this->fixture( 'payload-v1-full' );
		unset( $payload['event']['currency'] );

		$data = $this->decode( Schema::event_json_ld( Event_View::from_payload( $payload ), self::PERMALINK ) );

		$this->assertArrayNotHasKey( 'priceCurrency', $data['offers'][0] );
	}

	public function test_a_lowercase_currency_is_normalised(): void {
		$data = $this->decode( Schema::event_json_ld( $this->view( array( 'currency' => ' eur ' ) ), self::PERMALINK ) );

		$this->assertSame( 'EUR', $data['offers'][0]['priceCurrency'] );
	}

	public function test_a_permalink_that_is_not_a_string_does_not_throw(): void {
		// get_permalink() returns false for a missing post.
		$data = $this->decode( Schema::event_json_ld( $this->view(), false ) );

		$this->assertArrayNotHasKey( 'url', $data );
	}
}

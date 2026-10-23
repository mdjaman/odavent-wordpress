<?php
namespace Odavent\Events\Tests\Unit\Render;

use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Tests\Support\RenderTestCase;
use Odavent\Events\Tests\Support\WpStubs;

class RegisterUrlTest extends RenderTestCase {

	private function view( ?string $register_url = 'https://odavent.com/events/summit/register' ): Event_View {
		$payload                          = $this->fixture( 'payload-v1-full' );
		$payload['event']['register_url'] = $register_url;

		return Event_View::from_payload( $payload );
	}

	public function test_all_tracking_parameters_are_added_and_encoded(): void {
		$url = Register_Url::build( $this->view(), null, 'https://blog.example.com/summit/?a=1&b=2' );

		$this->assertSame(
			'https://odavent.com/events/summit/register'
			. '?utm_source=wordpress&utm_medium=plugin&utm_campaign=blog.example.com'
			. '&return=https%3A%2F%2Fblog.example.com%2Fsummit%2F%3Fa%3D1%26b%3D2',
			$url
		);
	}

	public function test_the_return_url_defaults_to_the_home_page(): void {
		$url = Register_Url::build( $this->view() );

		$this->assertStringContainsString( '&return=https%3A%2F%2Fblog.example.com%2F', $url );
	}

	public function test_the_campaign_is_the_host_of_home_url(): void {
		WpStubs::$home = 'https://events.acme.test:8443/blog';

		$url = Register_Url::build( $this->view() );

		$this->assertStringContainsString( 'utm_campaign=events.acme.test', $url );
		$this->assertStringNotContainsString( '8443', substr( $url, 0, strpos( $url, 'return=' ) ) );
	}

	public function test_an_existing_query_string_is_preserved(): void {
		$url = Register_Url::build( $this->view( 'https://odavent.com/events/summit/register?ref=abc%20def&lang=fr#form' ), 7 );

		$this->assertMatchesRegularExpression( '/[?&]ref=abc(\+|%20)def&/', $url );
		$this->assertStringContainsString( 'lang=fr', $url );
		$this->assertStringContainsString( 'utm_source=wordpress', $url );
		$this->assertSame( 1, substr_count( $url, '?' ) );
		$this->assertStringEndsWith( '#form', $url );
	}

	public function test_the_ticket_parameter_is_present_only_when_given(): void {
		$this->assertStringNotContainsString( 'ticket=', Register_Url::build( $this->view() ) );
		$this->assertStringContainsString( '&ticket=101', Register_Url::build( $this->view(), 101 ) );
	}

	public function test_a_ticket_id_from_the_payload_is_used_only_when_it_is_a_positive_integer(): void {
		$this->assertStringContainsString( '&ticket=12', Register_Url::build( $this->view(), '12' ) );
		$this->assertStringContainsString( '&ticket=12', Register_Url::build( $this->view(), 12.0 ) );

		foreach ( array( 'abc', '1; DROP', '', 0, -3, 1.5, array( 1 ), true, new \stdClass() ) as $bad ) {
			$this->assertStringNotContainsString( 'ticket=', Register_Url::build( $this->view(), $bad ), var_export( $bad, true ) );
		}
	}

	public function test_a_return_url_that_is_not_a_string_falls_back_to_home(): void {
		$url = Register_Url::build( $this->view(), null, array( 'x' ) );

		$this->assertStringContainsString( 'return=https%3A%2F%2Fblog.example.com%2F', $url );
	}

	public function test_a_hostile_return_url_falls_back_to_the_home_page(): void {
		$url = Register_Url::build( $this->view(), null, 'javascript:alert(1)' );

		$this->assertStringNotContainsString( 'javascript', $url );
		$this->assertStringContainsString( 'return=https%3A%2F%2Fblog.example.com%2F', $url );
	}

	public function test_a_non_http_register_url_gives_an_empty_string(): void {
		$this->assertSame( '', Register_Url::build( $this->view( 'javascript:alert(document.cookie)' ) ) );
		$this->assertSame( '', Register_Url::build( $this->view( 'data:text/html;base64,PHNjcmlwdD4=' ) ) );
		$this->assertSame( '', Register_Url::build( $this->view( null ) ) );
		$this->assertSame( '', Register_Url::build( $this->view( '' ) ) );
		$this->assertSame( '', Register_Url::build( Event_View::empty( Event_View::GONE ) ) );
	}

	public function test_hostile_fixture_register_url_is_dropped(): void {
		$view = Event_View::from_payload( $this->fixture( 'payload-v1-hostile' ) );

		$this->assertSame( '', Register_Url::build( $view ) );
	}

	public function test_safe_accepts_http_and_https_only(): void {
		$this->assertSame( 'https://example.com/x', Register_Url::safe( 'https://example.com/x' ) );
		$this->assertSame( 'http://example.com/x', Register_Url::safe( 'http://example.com/x' ) );
		$this->assertSame( '', Register_Url::safe( 'javascript:alert(1)' ) );
		$this->assertSame( '', Register_Url::safe( 'ftp://example.com/x' ) );
		$this->assertSame( '', Register_Url::safe( null ) );
	}
}

<?php
namespace Odavent\Events\Tests\Unit\Api;

use Brain\Monkey\Functions;
use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\TestCase;

class ClientTest extends TestCase {

	private const KEY = 'odv_wp_abcdef1234567890WXYZ';

	/** @var array|null Captured [url, args] of the last wp_remote_get call. */
	private $captured = null;

	/** @var int Number of wp_remote_get calls. */
	private $calls = 0;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
			}
		);
	}

	private function options( string $key = self::KEY, string $base_url = 'https://odavent.com' ): Options {
		Functions\when( 'get_option' )->justReturn(
			array(
				'base_url' => $base_url,
				'site_key' => $key,
			)
		);

		return new Options();
	}

	private function client( string $key = self::KEY, string $base_url = 'https://odavent.com' ): Client {
		return new Client( $this->options( $key, $base_url ) );
	}

	/**
	 * Makes wp_remote_get return a canned response and records its arguments.
	 *
	 * @param mixed $response What wp_remote_get returns.
	 */
	private function respond( $response ): void {
		$this->captured = null;
		$this->calls    = 0;
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) use ( $response ) {
				++$this->calls;
				$this->captured = array( $url, $args );
				return $response;
			}
		);
	}

	private function http( int $status, $body ): array {
		return array(
			'response' => array( 'code' => $status ),
			'body'     => is_string( $body ) ? $body : json_encode( $body ),
		);
	}

	private function envelope( $data ): array {
		return array(
			'success' => true,
			'message' => 'OK',
			'data'    => $data,
		);
	}

	private function error_body( string $code, string $message = 'Nope.' ): array {
		return array(
			'success' => false,
			'message' => $message,
			'errors'  => array( 'code' => $code ),
		);
	}

	private function url(): string {
		return $this->captured[0];
	}

	private function args(): array {
		return $this->captured[1];
	}

	// ---- success ---------------------------------------------------------------

	public function test_success_envelope_returns_ok_with_the_data(): void {
		$payload = $this->fixture( 'payload-v1-full' );
		$this->respond( $this->http( 200, $this->envelope( $payload ) ) );

		$result = $this->client()->site_event( 42 );

		$this->assertInstanceOf( Result::class, $result );
		$this->assertTrue( $result->ok );
		$this->assertSame( $payload, $result->data );
		$this->assertSame( '', $result->code );
		$this->assertSame( 200, $result->status );
	}

	public function test_minimal_fixture_parses(): void {
		$payload = $this->fixture( 'payload-v1-minimal' );
		$this->respond( $this->http( 200, $this->envelope( $payload ) ) );

		$result = $this->client()->public_event( 'small-meetup' );

		$this->assertTrue( $result->ok );
		$this->assertSame( $payload, $result->data );
		$this->assertNull( $result->data['event']['venue'] );
	}

	public function test_hostile_fixture_passes_through_untouched(): void {
		$payload = $this->fixture( 'payload-v1-hostile' );
		$this->respond( $this->http( 200, $this->envelope( $payload ) ) );

		$result = $this->client()->public_event( 'x' );

		$this->assertTrue( $result->ok );
		$this->assertSame( $payload, $result->data );
		$this->assertSame( 51200, strlen( $result->data['event']['description'] ) );
	}

	public function test_event_list_is_returned_as_a_list(): void {
		$list = $this->fixture( 'events-list' );
		$this->respond( $this->http( 200, $this->envelope( $list ) ) );

		$result = $this->client()->list_events();

		$this->assertTrue( $result->ok );
		$this->assertSame( $list, $result->data );
	}

	public function test_empty_event_list_is_ok(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$result = $this->client()->list_events();

		$this->assertTrue( $result->ok );
		$this->assertSame( array(), $result->data );
	}

	public function test_newer_contract_is_still_ok_with_contract_intact(): void {
		$payload             = $this->fixture( 'payload-v1-full' );
		$payload['contract'] = 2;
		$this->respond( $this->http( 200, $this->envelope( $payload ) ) );

		$result = $this->client()->public_event( 'x' );

		$this->assertTrue( $result->ok );
		$this->assertSame( 2, $result->data['contract'] );
		$this->assertGreaterThan( Client::SUPPORTED_CONTRACT, $result->data['contract'] );
	}

	public function test_plugin_update_returns_the_release_data(): void {
		$release = array(
			'version'      => '0.2.0',
			'download_url' => 'https://github.com/mdjaman/odavent-wordpress/releases/download/v0.2.0/odavent-events.zip',
			'requires'     => '6.1',
			'requires_php' => '7.4',
			'tested'       => '6.8',
			'changelog_url' => 'https://github.com/mdjaman/odavent-wordpress/releases/tag/v0.2.0',
		);
		$this->respond( $this->http( 200, $this->envelope( $release ) ) );

		$result = $this->client()->plugin_update();

		$this->assertTrue( $result->ok );
		$this->assertSame( $release, $result->data );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/plugin-update', $this->url() );
		$this->assertArrayNotHasKey( 'Authorization', $this->args()['headers'] );
	}

	// ---- error mapping ---------------------------------------------------------

	public function test_401_with_invalid_key_code_maps_to_invalid_key(): void {
		$this->respond( $this->http( 401, $this->error_body( 'invalid_key', 'Invalid or missing site key.' ) ) );

		$result = $this->client()->list_events();

		$this->assertFalse( $result->ok );
		$this->assertSame( 'invalid_key', $result->code );
		$this->assertSame( 401, $result->status );
		$this->assertSame( array(), $result->data );
	}

	public function test_403_with_plan_required_code_maps_to_plan_required(): void {
		$this->respond( $this->http( 403, $this->error_body( 'plan_required' ) ) );

		$result = $this->client()->list_events();

		$this->assertFalse( $result->ok );
		$this->assertSame( 'plan_required', $result->code );
		$this->assertSame( 403, $result->status );
	}

	public function test_404_with_not_found_code_maps_to_not_found_on_both_routes(): void {
		$this->respond( $this->http( 404, $this->error_body( 'not_found', 'Event not found.' ) ) );

		$site   = $this->client()->site_event( 999 );
		$public = $this->client()->public_event( 'nope' );

		$this->assertSame( 'not_found', $site->code );
		$this->assertSame( 'not_found', $public->code );
		$this->assertSame( 404, $public->status );
	}

	public function test_403_without_the_plan_required_code_is_unavailable(): void {
		// A WAF / Cloudflare JSON block must not look like "your plan does not include this".
		$this->respond( $this->http( 403, array( 'message' => 'Forbidden by firewall' ) ) );
		$this->assertSame( 'unavailable', $this->client()->list_events()->code );

		$this->respond( $this->http( 403, $this->error_body( 'something_else' ) ) );
		$this->assertSame( 'unavailable', $this->client()->list_events()->code );

		$this->respond( $this->http( 403, '<html><body>Attention Required! | Cloudflare</body></html>' ) );
		$result = $this->client()->list_events();
		$this->assertSame( 'unavailable', $result->code );
		$this->assertSame( 403, $result->status );
	}

	public function test_401_without_the_invalid_key_code_is_unavailable(): void {
		$this->respond( $this->http( 401, array( 'message' => 'Unauthenticated.' ) ) );
		$this->assertSame( 'unavailable', $this->client()->list_events()->code );

		$this->respond( $this->http( 401, $this->error_body( 'plan_required' ) ) );
		$this->assertSame( 'unavailable', $this->client()->list_events()->code );
	}

	public function test_404_without_the_not_found_code_is_unavailable(): void {
		// A wrong base URL (some other app's 404) must not read as "event not found".
		$this->respond( $this->http( 404, array( 'message' => 'Not found.' ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );

		$this->respond( $this->http( 404, '<html>404</html>' ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_a_mismatched_code_never_maps_across_statuses(): void {
		$this->respond( $this->http( 404, $this->error_body( 'invalid_key' ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_500_is_unavailable(): void {
		$this->respond( $this->http( 500, array( 'message' => 'Server Error' ) ) );

		$result = $this->client()->list_events();

		$this->assertFalse( $result->ok );
		$this->assertSame( 'unavailable', $result->code );
		$this->assertSame( 500, $result->status );
	}

	public function test_503_with_unavailable_code_is_unavailable(): void {
		$this->respond( $this->http( 503, $this->error_body( 'unavailable' ) ) );

		$this->assertSame( 'unavailable', $this->client()->plugin_update()->code );
	}

	public function test_wp_error_is_unavailable_with_status_zero(): void {
		$this->respond( new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$result = $this->client()->public_event( 'x' );

		$this->assertFalse( $result->ok );
		$this->assertSame( 'unavailable', $result->code );
		$this->assertSame( 0, $result->status );
	}

	public function test_html_body_on_200_is_unavailable(): void {
		$this->respond( $this->http( 200, '<html><body>Maintenance</body></html>' ) );

		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_success_false_on_200_is_unavailable(): void {
		$this->respond( $this->http( 200, array( 'success' => false ) ) );

		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_success_must_be_boolean_true(): void {
		$this->respond( $this->http( 200, array( 'success' => 'true', 'data' => array( 'a' => 1 ) ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );

		$this->respond( $this->http( 200, array( 'success' => 1, 'data' => array( 'a' => 1 ) ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_missing_or_non_array_data_is_unavailable(): void {
		$this->respond( $this->http( 200, array( 'success' => true, 'message' => 'OK' ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );

		$this->respond( $this->http( 200, array( 'success' => true, 'data' => 'oops' ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );

		$this->respond( $this->http( 200, array( 'success' => true, 'data' => null ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_json_scalar_or_empty_body_is_unavailable(): void {
		foreach ( array( '', 'null', '42', '"str"', 'true', '{' ) as $body ) {
			$this->respond( $this->http( 200, $body ) );
			$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code, "body: {$body}" );
		}
	}

	public function test_redirect_status_is_unavailable(): void {
		$this->respond( $this->http( 301, '' ) );

		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_a_3xx_with_a_valid_success_body_is_never_ok(): void {
		$body = $this->envelope( array( 'event' => array( 'id' => 1 ) ) );

		foreach ( array( 301, 302, 303, 307, 308 ) as $status ) {
			$this->respond( $this->http( $status, $body ) );

			$result = $this->client()->public_event( 'x' );
			$this->assertFalse( $result->ok, "status {$status}" );
			$this->assertSame( 'unavailable', $result->code, "status {$status}" );
			$this->assertSame( $status, $result->status );

			$this->assertFalse( $this->client()->list_events()->ok, "status {$status}" );
		}
	}

	public function test_errors_that_are_a_string_or_a_list_never_map_to_an_error_code(): void {
		foreach ( array(
			401 => 'invalid_key',
			403 => 'plan_required',
			404 => 'not_found',
		) as $status => $code ) {
			foreach ( array(
				'string' => array(
					'success' => false,
					'errors'  => $code,
				),
				'list'   => array(
					'success' => false,
					'errors'  => array( $code ),
				),
				'nested list' => array(
					'success' => false,
					'errors'  => array( array( 'code' => $code ) ),
				),
				'non-string code' => array(
					'success' => false,
					'errors'  => array( 'code' => array( $code ) ),
				),
			) as $label => $body ) {
				$this->respond( $this->http( $status, $body ) );

				$result = $this->client()->list_events();
				$this->assertSame( 'unavailable', $result->code, "{$status} / {$label}" );
				$this->assertSame( $status, $result->status );
			}
		}
	}

	public function test_a_body_with_a_byte_order_mark_is_unavailable(): void {
		$this->respond( $this->http( 200, "\xEF\xBB\xBF" . json_encode( $this->envelope( array( 'event' => array( 'id' => 1 ) ) ) ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );

		$this->respond( $this->http( 404, "\xEF\xBB\xBF" . json_encode( $this->error_body( 'not_found' ) ) ) );
		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_body_over_two_megabytes_is_unavailable(): void {
		$huge = $this->envelope( array( 'blob' => str_repeat( 'a', 2 * 1024 * 1024 ) ) );
		$this->respond( $this->http( 200, $huge ) );

		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_body_just_under_two_megabytes_is_accepted(): void {
		$small = $this->envelope( array( 'blob' => str_repeat( 'a', 1024 * 1024 ) ) );
		$this->respond( $this->http( 200, $small ) );

		$this->assertTrue( $this->client()->public_event( 'x' )->ok );
	}

	public function test_deeply_nested_json_is_unavailable(): void {
		$nested = str_repeat( '[', 40 ) . str_repeat( ']', 40 );
		$this->respond( $this->http( 200, '{"success":true,"data":' . $nested . '}' ) );

		$this->assertSame( 'unavailable', $this->client()->public_event( 'x' )->code );
	}

	public function test_result_message_is_generic_and_never_the_raw_body_or_key(): void {
		$this->respond( $this->http( 500, 'SECRET-BODY ' . self::KEY ) );

		$result = $this->client()->list_events();

		$this->assertNotSame( '', $result->message );
		$this->assertStringNotContainsString( 'SECRET-BODY', $result->message );
		$this->assertStringNotContainsString( self::KEY, $result->message );
	}

	// ---- request shape ---------------------------------------------------------

	public function test_key_request_carries_bearer_accept_user_agent_and_safe_args(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$this->client()->list_events();

		$args = $this->args();
		$this->assertSame( 'Bearer ' . self::KEY, $args['headers']['Authorization'] );
		$this->assertSame( 'application/json', $args['headers']['Accept'] );
		$this->assertSame( 'Odavent-WP/' . ODAVENT_EVENTS_VERSION, $args['headers']['User-Agent'] );
		$this->assertSame( 8, $args['timeout'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( Client::MAX_BODY_BYTES + 1, $args['limit_response_size'] );
	}

	public function test_list_events_takes_a_shorter_timeout_but_never_a_longer_one(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		$client->list_events();
		$this->assertSame( 8, $this->args()['timeout'] );

		$client->list_events( 3 );
		$this->assertSame( 3, $this->args()['timeout'] );

		$client->list_events( 60 );
		$this->assertSame( 8, $this->args()['timeout'], 'capped at the default' );

		$client->list_events( 0 );
		$this->assertSame( 1, $this->args()['timeout'], 'at least one second' );

		$client->site_event( 1 );
		$this->assertSame( 8, $this->args()['timeout'], 'other calls keep the default' );
	}

	public function test_every_method_uses_the_safe_args(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		foreach ( array(
			static function ( Client $c ) {
				return $c->list_events();
			},
			static function ( Client $c ) {
				return $c->site_event( 1 );
			},
			static function ( Client $c ) {
				return $c->public_event( 'x' );
			},
			static function ( Client $c ) {
				return $c->plugin_update();
			},
		) as $call ) {
			$call( $client );
			$this->assertSame( 8, $this->args()['timeout'] );
			$this->assertSame( 0, $this->args()['redirection'] );
			$this->assertTrue( $this->args()['sslverify'] );
			$this->assertSame( Client::MAX_BODY_BYTES + 1, $this->args()['limit_response_size'] );
			$this->assertSame( 'application/json', $this->args()['headers']['Accept'] );
		}
	}

	public function test_the_key_never_appears_in_a_url(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		$client->list_events();
		$this->assertStringNotContainsString( self::KEY, $this->url() );
		$this->assertStringNotContainsString( 'odv_wp_', $this->url() );

		$client->site_event( 5, 'fr' );
		$this->assertStringNotContainsString( 'odv_wp_', $this->url() );

		$client->public_event( 'x', 'fr' );
		$this->assertStringNotContainsString( 'odv_wp_', $this->url() );
	}

	public function test_public_event_sends_no_authorization_header(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$this->client()->public_event( 'summit' );

		$this->assertArrayNotHasKey( 'Authorization', $this->args()['headers'] );
		$this->assertSame( 'application/json', $this->args()['headers']['Accept'] );
		$this->assertStringNotContainsString( self::KEY, json_encode( $this->captured ) );
	}

	public function test_public_event_works_without_a_key(): void {
		$this->respond( $this->http( 200, $this->envelope( array( 'contract' => 1 ) ) ) );

		$result = $this->client( '' )->public_event( 'summit' );

		$this->assertTrue( $result->ok );
		$this->assertSame( 1, $this->calls );
	}

	public function test_urls_are_built_from_the_base_url_without_a_trailing_slash(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$client = $this->client( self::KEY, 'https://events.example.org/odavent/' );
		$client->list_events();
		$this->assertSame( 'https://events.example.org/odavent/api/v1/wordpress/site/events', $this->url() );

		$client->site_event( 42 );
		$this->assertSame( 'https://events.example.org/odavent/api/v1/wordpress/site/events/42', $this->url() );

		$client->public_event( 'summit' );
		$this->assertSame( 'https://events.example.org/odavent/api/v1/wordpress/events/summit', $this->url() );

		$client->plugin_update();
		$this->assertSame( 'https://events.example.org/odavent/api/v1/wordpress/plugin-update', $this->url() );
	}

	public function test_locale_is_added_only_when_given(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		$client->site_event( 42, 'fr' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/site/events/42?locale=fr', $this->url() );

		$client->public_event( 'summit', 'fr' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit?locale=fr', $this->url() );

		$client->public_event( 'summit' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit', $this->url() );

		$client->public_event( 'summit', '' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit', $this->url() );

		$client->public_event( 'summit', null );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit', $this->url() );
	}

	public function test_locale_accepts_wordpress_style_codes(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		$client->public_event( 'summit', 'fr_FR' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit?locale=fr_FR', $this->url() );

		$client->public_event( 'summit', 'en' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit?locale=en', $this->url() );
	}

	public function test_invalid_locale_is_dropped(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client();

		foreach ( array( 'fr&x=1', 'FR', 'french', 'fr-FR', 'fr_fr', 'f', 'fr_FRA', "fr\n", '../', 'fr#x', ' fr' ) as $bad ) {
			$client->public_event( 'summit', $bad );
			$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/summit', $this->url(), 'locale: ' . json_encode( $bad ) );
		}
	}

	public function test_slug_is_url_encoded(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$this->client()->public_event( 'a/b?c=d#e f' );

		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/a%2Fb%3Fc%3Dd%23e%20f', $this->url() );

		$this->client()->public_event( '../site/events' );
		$this->assertSame( 'https://odavent.com/api/v1/wordpress/events/..%2Fsite%2Fevents', $this->url() );
	}

	public function test_a_slug_that_is_empty_or_only_dots_is_not_found_without_any_request(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		foreach ( array( '', '.', '..', '...', ' ', "\t", ' . ', ' .. ', "\n" ) as $slug ) {
			$result = $this->client()->public_event( $slug, 'fr' );

			$this->assertFalse( $result->ok, 'slug: ' . json_encode( $slug ) );
			$this->assertSame( 'not_found', $result->code, 'slug: ' . json_encode( $slug ) );
		}
		$this->assertSame( 0, $this->calls );

		// A real slug that merely contains a dot still goes out.
		$this->client()->public_event( 'v1.2-summit' );
		$this->assertSame( 1, $this->calls );
	}

	public function test_site_event_id_is_cast_to_int(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$this->client()->site_event( 7 );

		$this->assertSame( 'https://odavent.com/api/v1/wordpress/site/events/7', $this->url() );
	}

	// ---- key gating ------------------------------------------------------------

	public function test_key_routes_fail_without_calling_http_when_there_is_no_key(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );
		$client = $this->client( '' );

		$list = $client->list_events();
		$one  = $client->site_event( 1 );

		$this->assertSame( 'invalid_key', $list->code );
		$this->assertSame( 'invalid_key', $one->code );
		$this->assertFalse( $list->ok );
		$this->assertSame( 0, $list->status );
		$this->assertSame( 0, $this->calls );
	}

	public function test_a_malformed_key_counts_as_no_key(): void {
		$this->respond( $this->http( 200, $this->envelope( array() ) ) );

		$result = $this->client( "bad key\r\nX-Evil: 1" )->list_events();

		$this->assertSame( 'invalid_key', $result->code );
		$this->assertSame( 0, $this->calls );
	}

	public function test_result_named_constructors(): void {
		$ok = Result::ok( array( 'a' => 1 ), 201 );
		$this->assertTrue( $ok->ok );
		$this->assertSame( array( 'a' => 1 ), $ok->data );
		$this->assertSame( '', $ok->code );
		$this->assertSame( 201, $ok->status );
		$this->assertSame( '', $ok->message );

		$fail = Result::fail( 'not_found', 404, 'Gone' );
		$this->assertFalse( $fail->ok );
		$this->assertSame( array(), $fail->data );
		$this->assertSame( 'not_found', $fail->code );
		$this->assertSame( 404, $fail->status );
		$this->assertSame( 'Gone', $fail->message );

		$default = Result::fail( 'unavailable' );
		$this->assertSame( 0, $default->status );
		$this->assertSame( '', $default->message );

		$this->assertSame( 200, Result::ok( array() )->status );
	}
}

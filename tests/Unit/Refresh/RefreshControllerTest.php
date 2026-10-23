<?php
namespace Odavent\Events\Tests\Unit\Refresh;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Refresh\Page_Cache_Purger;
use Odavent\Events\Refresh\Refresh_Controller;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The controller runs against in-memory transients that record every write, a frozen clock
 * (odavent_events_now filter) and Mockery doubles for Options, the repository and the purger.
 */
class RefreshControllerTest extends TestCase {

	private const KEY = 'odv_wp_testkey12345';
	private const NOW = 1700000000;

	/** @var array name => value */
	private $transients = array();

	/** @var array Every transient write: [name, value, ttl]. */
	private $transient_writes = array();

	/** @var Options|Mockery\MockInterface */
	private $options;

	/** @var Event_Repository|Mockery\MockInterface */
	private $repository;

	/** @var Page_Cache_Purger|Mockery\MockInterface */
	private $purger;

	/** @var string The site key Options returns. */
	private $key = self::KEY;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return array_key_exists( $name, $this->transients ) ? $this->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl = 0 ) {
				$this->transient_writes[] = array( $name, $value, $ttl );
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			function ( $title ) {
				return strtolower( preg_replace( '/[^A-Za-z0-9-]+/', '-', trim( (string) $title ) ) );
			}
		);
		Filters\expectApplied( 'odavent_events_now' )->zeroOrMoreTimes()->andReturnUsing(
			function () {
				return self::NOW;
			}
		);

		$this->options = Mockery::mock( Options::class );
		$this->options->shouldReceive( 'site_key' )->andReturnUsing(
			function () {
				return $this->key;
			}
		)->byDefault();
		$this->repository = Mockery::mock( Event_Repository::class );
		$this->purger     = Mockery::mock( Page_Cache_Purger::class );
	}

	private function controller(): Refresh_Controller {
		return new Refresh_Controller( $this->options, $this->repository, $this->purger );
	}

	private function body( array $data ): string {
		return (string) json_encode( $data, JSON_UNESCAPED_SLASHES );
	}

	private function sign( string $body, int $t = self::NOW, string $key = self::KEY ): string {
		return 't=' . $t . ',v1=' . hash_hmac( 'sha256', $t . '.' . $body, $key );
	}

	private function request( string $body, ?string $signature, string $header_name = 'X-Odavent-Signature' ): WP_REST_Request {
		$request = new WP_REST_Request( 'POST', '/odavent/v1/refresh' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( $body );
		if ( null !== $signature ) {
			$request->set_header( $header_name, $signature );
		}

		return $request;
	}

	private function valid_body( int $id = 7 ): string {
		return $this->body(
			array(
				'event_id'   => $id,
				'event_slug' => 'annual-tech-summit',
				'sent_at'    => '2023-11-14T22:13:20+00:00',
			)
		);
	}

	private function expect_no_purge(): void {
		$this->repository->shouldNotReceive( 'purge' );
		$this->purger->shouldNotReceive( 'purge_event' );
		Actions\expectDone( 'odavent_events_refreshed' )->never();
	}

	private function assert_unauthorized( WP_REST_Response $response ): void {
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'odavent_invalid_signature', $response->get_data()['code'] );
	}

	// ---- routes --------------------------------------------------------------------

	public function test_route_is_post_only_with_signature_as_authentication(): void {
		$captured = array();
		Functions\expect( 'register_rest_route' )->once()->andReturnUsing(
			function ( $namespace, $route, $args ) use ( &$captured ) {
				$captured = array( $namespace, $route, $args );
				return true;
			}
		);

		$controller = $this->controller();
		$controller->register_routes();

		$this->assertSame( 'odavent/v1', $captured[0] );
		$this->assertSame( '/refresh', $captured[1] );
		$this->assertSame( 'POST', $captured[2]['methods'] );
		$this->assertSame( '__return_true', $captured[2]['permission_callback'] );
		$this->assertSame( array( $controller, 'handle' ), $captured[2]['callback'] );
	}

	// ---- success -------------------------------------------------------------------

	public function test_valid_ping_purges_the_event_and_fires_the_action(): void {
		$body = $this->valid_body( 7 );
		$this->repository->shouldReceive( 'purge' )->once()->with( 7 );
		$this->purger->shouldReceive( 'purge_event' )->once()->with( 7 )->andReturn( 2 );
		Actions\expectDone( 'odavent_events_refreshed' )->once()->with( 7, 'annual-tech-summit' );

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 'ok' => true ), $response->get_data() );
	}

	public function test_lowercase_header_name_is_accepted(): void {
		$body = $this->valid_body( 7 );
		$this->repository->shouldReceive( 'purge' )->once()->with( 7 );
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ), 'x-odavent-signature' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_replay_marker_is_written_after_a_valid_ping(): void {
		$body = $this->valid_body( 7 );
		$sig  = $this->sign( $body );
		$this->repository->shouldReceive( 'purge' )->once();
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );

		$this->controller()->handle( $this->request( $body, $sig ) );

		$v1 = substr( $sig, strpos( $sig, 'v1=' ) + 3 );
		$this->assertSame(
			array( array( 'odavent_events_seen_' . substr( sha1( $v1 ), 0, 32 ), 1, 600 ) ),
			$this->transient_writes
		);
	}

	public function test_slug_is_optional_and_sanitised(): void {
		$body = $this->body( array( 'event_id' => 9 ) );
		$this->repository->shouldReceive( 'purge' )->once()->with( 9 );
		$this->purger->shouldReceive( 'purge_event' )->once()->with( 9 )->andReturn( 0 );
		Actions\expectDone( 'odavent_events_refreshed' )->once()->with( 9, '' );

		$this->assertSame( 200, $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) )->get_status() );
	}

	public function test_hostile_slug_is_sanitised_before_the_action(): void {
		$body = $this->body( array( 'event_id' => 9, 'event_slug' => '<script>x</script>' ) );
		$this->repository->shouldReceive( 'purge' )->once();
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );
		Actions\expectDone( 'odavent_events_refreshed' )->once()->with( 9, '-script-x-script-' );

		$this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_unknown_event_id_still_returns_200(): void {
		$body = $this->valid_body( 424242 );
		$this->repository->shouldReceive( 'purge' )->once()->with( 424242 );
		$this->purger->shouldReceive( 'purge_event' )->once()->with( 424242 )->andReturn( 0 );

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_failure_while_purging_is_a_generic_500_not_a_fatal(): void {
		$body = $this->valid_body( 7 );
		$this->repository->shouldReceive( 'purge' )->andThrow( new \RuntimeException( 'db down ' . self::KEY ) );
		$this->purger->shouldNotReceive( 'purge_event' );

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertStringNotContainsString( self::KEY, (string) json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( 'db down', (string) json_encode( $response->get_data() ) );
	}

	// ---- authentication failures ----------------------------------------------------

	public function test_replay_of_the_same_signature_is_rejected(): void {
		$body = $this->valid_body( 7 );
		$sig  = $this->sign( $body );
		$this->repository->shouldReceive( 'purge' )->once();
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );

		$first  = $this->controller()->handle( $this->request( $body, $sig ) );
		$second = $this->controller()->handle( $this->request( $body, $sig ) );

		$this->assertSame( 200, $first->get_status() );
		$this->assert_unauthorized( $second );
	}

	public function test_body_tampered_after_signing_is_rejected(): void {
		$sig = $this->sign( $this->valid_body( 7 ) );
		$this->expect_no_purge();

		$this->assert_unauthorized( $this->controller()->handle( $this->request( $this->valid_body( 8 ), $sig ) ) );
	}

	public function test_stale_signature_is_rejected(): void {
		$body = $this->valid_body( 7 );
		$this->expect_no_purge();

		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW - 301 ) ) ) );
		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW + 301 ) ) ) );
	}

	public function test_no_key_configured_is_rejected(): void {
		$this->key = '';
		$body      = $this->valid_body( 7 );
		$this->expect_no_purge();

		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) ) );
	}

	public function test_missing_and_malformed_headers_are_rejected(): void {
		$body = $this->valid_body( 7 );
		$this->expect_no_purge();

		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, null ) ) );
		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, '' ) ) );
		$this->assert_unauthorized( $this->controller()->handle( $this->request( $body, 't=abc,v1=x' ) ) );
	}

	public function test_every_authentication_failure_has_the_identical_response(): void {
		$body = $this->valid_body( 7 );
		$this->repository->shouldReceive( 'purge' )->once();
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );

		$responses = array();

		// Replay: a first valid ping, then the same one again.
		$replayed = $this->sign( $body );
		$this->controller()->handle( $this->request( $body, $replayed ) );
		$responses['replay'] = $this->controller()->handle( $this->request( $body, $replayed ) );

		$responses['wrong signature'] = $this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW, 'odv_wp_otherkey12345' ) ) );
		$responses['tampered']        = $this->controller()->handle( $this->request( $this->valid_body( 8 ), $this->sign( $body, self::NOW - 1 ) ) );
		$responses['stale']           = $this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW - 400 ) ) );
		$responses['missing header']  = $this->controller()->handle( $this->request( $body, null ) );
		$responses['malformed']       = $this->controller()->handle( $this->request( $body, 't=abc,v1=x' ) );

		$this->key            = '';
		$responses['no key'] = $this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW - 2 ) ) );

		$reference = null;
		foreach ( $responses as $cause => $response ) {
			$this->assertInstanceOf( WP_REST_Response::class, $response, $cause );
			$shape = array( $response->get_status(), json_encode( $response->get_data() ) );
			if ( null === $reference ) {
				$reference = $shape;
			}
			$this->assertSame( $reference, $shape, "Response for '{$cause}' differs" );
		}
		$this->assertSame( 401, $reference[0] );
		$this->assertStringNotContainsString( 'odv_wp_', $reference[1] );
	}

	public function test_invalid_signature_writes_nothing(): void {
		$body = $this->valid_body( 7 );
		$this->expect_no_purge();

		$this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW, 'odv_wp_otherkey12345' ) ) );
		$this->controller()->handle( $this->request( $body, $this->sign( $body, self::NOW - 301 ) ) );
		$this->controller()->handle( $this->request( $body, 't=' . self::NOW . ',v1=' . str_repeat( 'a', 64 ) ) );

		$this->assertSame( array(), $this->transient_writes );
	}

	// ---- size ---------------------------------------------------------------------------

	public function test_oversized_body_is_rejected_before_any_hashing(): void {
		$body = $this->body( array( 'event_id' => 7, 'pad' => str_repeat( 'x', 9 * 1024 ) ) );
		// The key is only ever read to compute the HMAC: not reading it proves no HMAC was computed.
		$this->options->shouldNotReceive( 'site_key' );
		$this->expect_no_purge();

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 413, $response->get_status() );
		$this->assertSame( 'odavent_payload_too_large', $response->get_data()['code'] );
		$this->assertSame( array(), $this->transient_writes );
	}

	public function test_body_of_exactly_8192_bytes_is_accepted(): void {
		$prefix = '{"event_id":7,"pad":"';
		$body   = $prefix . str_repeat( 'x', 8192 - strlen( $prefix ) - 2 ) . '"}';
		$this->assertSame( 8192, strlen( $body ) );
		$this->repository->shouldReceive( 'purge' )->once()->with( 7 );
		$this->purger->shouldReceive( 'purge_event' )->once()->andReturn( 0 );

		$this->assertSame( 200, $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) )->get_status() );
	}

	public function test_oversized_content_length_header_is_rejected_before_reading_the_key(): void {
		$body    = $this->valid_body( 7 );
		$request = $this->request( $body, $this->sign( $body ) );
		$request->set_header( 'Content-Length', '100000' );
		$this->options->shouldNotReceive( 'site_key' );
		$this->expect_no_purge();

		$this->assertSame( 413, $this->controller()->handle( $request )->get_status() );
	}

	// ---- payload ----------------------------------------------------------------------

	public function test_valid_signature_on_non_json_is_a_bad_request(): void {
		$body = 'not json';
		$this->expect_no_purge();

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'odavent_bad_request', $response->get_data()['code'] );
	}

	/**
	 * @dataProvider bad_event_ids
	 *
	 * @param string $body Raw JSON body.
	 */
	public function test_event_id_must_be_a_positive_int( string $body ): void {
		$this->expect_no_purge();

		$response = $this->controller()->handle( $this->request( $body, $this->sign( $body ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'odavent_bad_request', $response->get_data()['code'] );
	}

	public function bad_event_ids(): array {
		return array(
			'zero'       => array( '{"event_id":0}' ),
			'negative'   => array( '{"event_id":-1}' ),
			'string'     => array( '{"event_id":"7"}' ),
			'float'      => array( '{"event_id":7.5}' ),
			'bool'       => array( '{"event_id":true}' ),
			'null'       => array( '{"event_id":null}' ),
			'missing'    => array( '{"event_slug":"x"}' ),
			'list'       => array( '[7]' ),
			'scalar'     => array( '7' ),
			'overflow'   => array( '{"event_id":99999999999999999999999}' ),
		);
	}
}

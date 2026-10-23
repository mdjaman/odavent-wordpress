<?php
namespace Odavent\Events\Tests\Unit\Data;

use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\TestCase;

/**
 * The repository runs against an in-memory WordPress (transients with TTLs on a fake clock, options)
 * that records every write, and a Mockery Client whose calls are logged and answered from a queue.
 */
class EventRepositoryTest extends TestCase {

	private const KEY = 'odv_wp_abcdef1234567890WXYZ';

	/** @var array name => [value, expires_at (0 = never)] */
	private $transients = array();

	/** @var array name => value */
	private $options = array();

	/** @var array Every write: [function, name, value, ttl-or-autoload]. */
	private $writes = array();

	/** @var int Fake clock for transient expiry. */
	private $now = 1000000;

	/** @var int nocache_headers() calls. */
	private $nocache = 0;

	/** @var string What determine_locale() returns. */
	private $wp_locale = 'en_US';

	/** @var array Client calls: [method, ...args]. */
	private $calls = array();

	/** @var array method => queue of Result (or Throwable to throw). */
	private $queue = array(
		'site_event'  => array(),
		'public_event' => array(),
		'list_events' => array(),
	);

	/** @var string[] Client methods called with nothing queued. */
	private $unexpected = array();

	/** @var Client|Mockery\MockInterface */
	private $client;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		$this->settings( array( 'site_key' => self::KEY ) );

		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				if ( ! isset( $this->transients[ $name ] ) ) {
					return false;
				}
				list( $value, $expires ) = $this->transients[ $name ];
				if ( 0 !== $expires && $expires <= $this->now ) {
					unset( $this->transients[ $name ] );
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value, $ttl = 0 ) {
				$this->writes[]             = array( 'set_transient', $name, $value, $ttl );
				$this->transients[ $name ] = array( $value, $ttl > 0 ? $this->now + $ttl : 0 );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				$this->writes[] = array( 'delete_transient', $name, null, null );
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->writes[]         = array( 'update_option', $name, $value, $autoload );
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				$this->writes[] = array( 'add_option', $name, $value, $autoload );
				if ( ! array_key_exists( $name, $this->options ) ) {
					$this->options[ $name ] = $value;
				}
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				$this->writes[] = array( 'delete_option', $name, null, null );
				unset( $this->options[ $name ] );
				return true;
			}
		);
		Functions\when( 'nocache_headers' )->alias(
			function () {
				++$this->nocache;
			}
		);
		Functions\when( 'determine_locale' )->alias(
			function () {
				return $this->wp_locale;
			}
		);

		$this->client = Mockery::mock( Client::class );
		foreach ( array_keys( $this->queue ) as $method ) {
			$this->client->shouldReceive( $method )->andReturnUsing(
				function ( ...$args ) use ( $method ) {
					$this->calls[] = array_merge( array( $method ), $args );
					if ( array() === $this->queue[ $method ] ) {
						// Not $this->fail(): get() catches every Throwable, so record and assert later.
						$this->unexpected[] = $method;
						return Result::fail( Result::UNAVAILABLE );
					}
					$next = array_shift( $this->queue[ $method ] );
					if ( $next instanceof \Throwable ) {
						throw $next;
					}
					return $next;
				}
			);
		}
	}

	protected function assertPostConditions(): void {
		$this->assertSame( array(), $this->unexpected, 'unexpected Client calls' );
		parent::assertPostConditions();
	}

	// ---- helpers ---------------------------------------------------------------

	private function settings( array $settings ): void {
		$this->options[ Options::OPTION ] = $settings;
	}

	private function repo(): Event_Repository {
		return new Event_Repository( $this->client, new Options() );
	}

	private function answer( string $method, Result ...$results ): void {
		foreach ( $results as $result ) {
			$this->queue[ $method ][] = $result;
		}
	}

	private function payload( string $status = 'published', string $fixture = 'payload-v1-full' ): array {
		$payload                    = $this->fixture( $fixture );
		$payload['event']['status'] = $status;

		return $payload;
	}

	private function fail_result( string $code, int $status = 0 ): Result {
		return Result::fail( $code, $status, 'msg' );
	}

	private function pub( int $id, string $loc = 'en' ): string {
		return "odavent_events_ev_{$id}_{$loc}";
	}

	private function pvw( int $id, string $loc = 'en' ): string {
		return "odavent_events_ev_{$id}_{$loc}_preview";
	}

	private function lastgood( int $id, string $loc = 'en' ): string {
		return "odavent_events_lastgood_{$id}_{$loc}";
	}

	private function transient( string $name ) {
		return isset( $this->transients[ $name ] ) ? $this->transients[ $name ][0] : null;
	}

	private function ttl_of( string $name ): ?int {
		foreach ( array_reverse( $this->writes ) as $write ) {
			if ( 'set_transient' === $write[0] && $name === $write[1] ) {
				return $write[3];
			}
		}
		return null;
	}

	private function writes_to( string $name ): array {
		return array_values(
			array_filter(
				$this->writes,
				static function ( $write ) use ( $name ) {
					return $write[1] === $name;
				}
			)
		);
	}

	private function http_calls( string $method = '' ): array {
		return array_values(
			array_filter(
				$this->calls,
				static function ( $call ) use ( $method ) {
					return '' === $method || $call[0] === $method;
				}
			)
		);
	}

	private function seed_last_good( int $id, array $payload, string $loc = 'en' ): void {
		$this->options[ $this->lastgood( $id, $loc ) ] = $payload;
	}

	// ---- memo, success, TTL ----------------------------------------------------

	public function test_three_gets_make_one_client_call(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$repo = $this->repo();

		$a = $repo->get( 42 );
		$b = $repo->get( 42 );
		$c = $repo->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertSame( array( array( 'site_event', 42, 'en' ) ), $this->calls );
		$this->assertSame( Event_View::LIVE, $a->state() );
		$this->assertSame( $a, $b );
		$this->assertSame( $a, $c );
	}

	public function test_success_caches_transient_and_non_autoloaded_last_good(): void {
		$payload = $this->payload();
		$this->answer( 'site_event', Result::ok( $payload ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertFalse( $view->is_stale() );
		$this->assertFalse( $view->is_preview() );
		$this->assertSame( $payload['event'], $view->event() );
		$this->assertSame( $payload, $this->transient( $this->pub( 42 ) ) );
		$this->assertSame( 900, $this->ttl_of( $this->pub( 42 ) ) );
		$this->assertSame( array( array( 'update_option', $this->lastgood( 42 ), $payload, false ) ), $this->writes_to( $this->lastgood( 42 ) ) );
		$this->assertSame( 0, $this->nocache );
	}

	public function test_ttl_follows_the_settings_and_is_clamped(): void {
		$this->settings( array( 'site_key' => self::KEY, 'cache_ttl' => 120 ) );
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42 );
		$this->assertSame( 120, $this->ttl_of( $this->pub( 42 ) ) );

		// A corrupted 0 would mean "never expires" for set_transient: clamp to the minimum.
		$this->settings( array( 'site_key' => self::KEY, 'cache_ttl' => 0 ) );
		$this->transients = array();
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42 );
		$this->assertSame( Options::MIN_CACHE_TTL, $this->ttl_of( $this->pub( 42 ) ) );
	}

	public function test_cached_payload_is_served_without_http_on_the_next_request(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42 );

		$view = $this->repo()->get( 42 );

		$this->assertCount( 1, $this->calls );
		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertTrue( $view->has_content() );
	}

	public function test_paused_cancelled_archived_and_ended_are_cached_with_content(): void {
		foreach ( array( 'paused' => Event_View::PAUSED, 'cancelled' => Event_View::CANCELLED, 'archived' => Event_View::ARCHIVED ) as $status => $state ) {
			$this->transients = array();
			$this->answer( 'site_event', Result::ok( $this->payload( $status ) ) );
			$view = $this->repo()->get( 42 );
			$this->assertSame( $state, $view->state() );
			$this->assertTrue( $view->has_content() );
			$this->assertIsArray( $this->transient( $this->pub( 42 ) ) );
		}
	}

	public function test_integer_and_float_prices_both_survive(): void {
		$payload                       = $this->payload();
		$payload['tickets'][0]['price'] = 25000;
		$payload['tickets'][1]['price'] = 12.5;
		$this->answer( 'site_event', Result::ok( $payload ) );

		$tickets = $this->repo()->get( 42 )->tickets();

		$this->assertSame( 25000, $tickets[0]['price'] );
		$this->assertSame( 12.5, $tickets[1]['price'] );
	}

	// ---- malformed payloads ----------------------------------------------------

	public function malformed_payloads(): array {
		return array(
			'data []'           => array( array() ),
			'no event key'      => array( array( 'contract' => 1, 'tickets' => array() ) ),
			'event empty'       => array( array( 'event' => array() ) ),
			'event not array'   => array( array( 'event' => 'x' ) ),
			'event.id string'   => array( array( 'event' => array( 'id' => '42', 'status' => 'published' ) ) ),
			'event.id float'    => array( array( 'event' => array( 'id' => 42.0, 'status' => 'published' ) ) ),
			'event.id missing'  => array( array( 'event' => array( 'status' => 'published' ) ) ),
			'other event'       => array( array( 'event' => array( 'id' => 43, 'status' => 'published' ) ) ),
		);
	}

	/**
	 * @dataProvider malformed_payloads
	 */
	public function test_malformed_ok_payload_is_unavailable_and_never_cached( array $data ): void {
		$this->answer( 'site_event', Result::ok( $data ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::UNAVAILABLE, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertNull( $this->transient( $this->pub( 42 ) ) );
		$this->assertArrayNotHasKey( $this->lastgood( 42 ), $this->options );
		$this->assertSame( 1, $this->transient( Event_Repository::BACKOFF ) );
	}

	public function test_malformed_payload_falls_back_to_last_good(): void {
		$good = $this->payload();
		$this->seed_last_good( 42, $good );
		$this->answer( 'site_event', Result::ok( array() ) );

		$view = $this->repo()->get( 42 );

		$this->assertTrue( $view->is_stale() );
		$this->assertSame( $good['event'], $view->event() );
		$this->assertSame( $good, $this->options[ $this->lastgood( 42 ) ] );
	}

	public function test_unknown_status_is_unavailable_and_not_cached_nor_breaker(): void {
		$this->seed_last_good( 42, $this->payload() );
		$this->answer( 'site_event', Result::ok( $this->payload( 'scheduled' ) ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::UNAVAILABLE, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertNull( $this->transient( $this->pub( 42 ) ) );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ) );
	}

	// ---- failures ----------------------------------------------------------------

	public function test_network_failure_with_last_good_serves_it_as_stale(): void {
		$good = $this->payload( 'paused' );
		$this->seed_last_good( 42, $good );
		$this->answer( 'site_event', $this->fail_result( Result::UNAVAILABLE ) );

		$view = $this->repo()->get( 42 );

		$this->assertTrue( $view->is_stale() );
		$this->assertSame( Event_View::PAUSED, $view->state() );
		$this->assertSame( $good['speakers'], $view->speakers() );
		$this->assertNull( $this->transient( $this->pub( 42 ) ) );
	}

	public function test_network_failure_without_last_good_is_unavailable(): void {
		$this->answer( 'site_event', $this->fail_result( Result::UNAVAILABLE, 503 ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::UNAVAILABLE, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertFalse( $view->is_stale() );
	}

	public function test_last_good_of_another_event_is_ignored(): void {
		$this->seed_last_good( 42, $this->payload( 'published', 'payload-v1-minimal' ) );
		$this->answer( 'site_event', $this->fail_result( Result::UNAVAILABLE ) );

		$this->assertSame( Event_View::UNAVAILABLE, $this->repo()->get( 42 )->state() );
	}

	public function test_get_never_throws(): void {
		$this->queue['site_event'] = array( new \RuntimeException( 'boom' ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::UNAVAILABLE, $view->state() );
	}

	public function test_not_found_purges_every_cache_and_returns_gone(): void {
		foreach ( array( 'en', 'fr', 'default' ) as $loc ) {
			$this->transients[ $this->pvw( 42, $loc ) ] = array( $this->payload( 'draft' ), 0 );
			$this->seed_last_good( 42, $this->payload(), $loc );
		}
		$this->transients[ $this->pub( 42, 'fr' ) ] = array( $this->payload(), 0 );
		$this->answer( 'site_event', $this->fail_result( Result::NOT_FOUND, 404 ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::GONE, $view->state() );
		$this->assertFalse( $view->has_content() );
		foreach ( array( 'en', 'fr', 'default' ) as $loc ) {
			$this->assertNull( $this->transient( $this->pvw( 42, $loc ) ), $loc );
			$this->assertArrayNotHasKey( $this->lastgood( 42, $loc ), $this->options, $loc );
		}
		$this->assertNull( $this->transient( $this->pub( 42, 'fr' ) ) );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ), 'not_found is not a breaker failure' );
	}

	public function test_gone_marker_is_content_free_short_lived_and_saves_the_next_call(): void {
		$this->answer( 'site_event', $this->fail_result( Result::NOT_FOUND, 404 ) );
		$this->repo()->get( 42 );

		$this->assertSame( array( '__state' => 'gone' ), $this->transient( $this->pub( 42 ) ) );
		$this->assertSame( 300, $this->ttl_of( $this->pub( 42 ) ) );

		$view = $this->repo()->get( 42 );
		$this->assertSame( Event_View::GONE, $view->state() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_gone_marker_ttl_is_the_cache_ttl_when_shorter(): void {
		$this->settings( array( 'site_key' => self::KEY, 'cache_ttl' => 120 ) );
		$this->answer( 'site_event', $this->fail_result( Result::NOT_FOUND, 404 ) );

		$this->repo()->get( 42 );

		$this->assertSame( 120, $this->ttl_of( $this->pub( 42 ) ) );
	}

	public function test_plan_required_disables_the_connection_and_falls_back_to_the_public_read(): void {
		$payload = $this->payload();
		$this->answer( 'site_event', $this->fail_result( Result::PLAN_REQUIRED, 403 ) );
		$this->answer( 'public_event', Result::ok( $payload ) );

		$view = $this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertSame( $payload['event'], $view->event() );
		$this->assertSame(
			array(
				array( 'site_event', 42, 'en' ),
				array( 'public_event', 'sommet-tech-abidjan-2026', 'en' ),
			),
			$this->calls
		);
		$this->assertTrue( ( new Options() )->is_connection_disabled() );
		$this->assertSame( $payload, $this->transient( $this->pub( 42 ) ) );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ), 'plan_required is not a breaker failure' );
	}

	public function test_while_disabled_the_key_route_is_not_called(): void {
		$this->answer( 'site_event', $this->fail_result( Result::PLAN_REQUIRED, 403 ) );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->transients = array_intersect_key( $this->transients, array( Options::CONN_DISABLED_FLAG => 1 ) );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertCount( 1, $this->http_calls( 'site_event' ) );
		$this->assertCount( 2, $this->http_calls( 'public_event' ) );
	}

	public function test_plan_required_without_slug_uses_last_good_or_unavailable(): void {
		$this->answer( 'site_event', $this->fail_result( Result::PLAN_REQUIRED, 403 ) );
		$this->assertSame( Event_View::UNAVAILABLE, $this->repo()->get( 42 )->state() );
		$this->assertCount( 0, $this->http_calls( 'public_event' ) );

		$this->seed_last_good( 42, $this->payload() );
		$view = $this->repo()->get( 42 );
		$this->assertTrue( $view->is_stale() );
		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertCount( 1, $this->http_calls( 'site_event' ) );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ), 'plan_required is not a breaker failure' );
	}

	public function test_invalid_key_is_unavailable_and_raises_the_notice_flag(): void {
		$this->answer( 'site_event', $this->fail_result( Result::INVALID_KEY, 401 ) );

		$view = $this->repo()->get( 42 );

		$this->assertSame( Event_View::UNAVAILABLE, $view->state() );
		$this->assertSame( 1, $this->transient( Event_Repository::INVALID_KEY_NOTICE ) );
		$this->assertSame( 12 * 3600, $this->ttl_of( Event_Repository::INVALID_KEY_NOTICE ) );
		$this->assertSame( 1, $this->transient( Event_Repository::BACKOFF ) );
	}

	public function test_invalid_key_with_last_good_serves_stale(): void {
		$this->seed_last_good( 42, $this->payload() );
		$this->answer( 'site_event', $this->fail_result( Result::INVALID_KEY, 401 ) );

		$this->assertTrue( $this->repo()->get( 42 )->is_stale() );
	}

	// ---- circuit breaker -------------------------------------------------------

	public function test_breaker_after_unavailable_skips_http_and_serves_last_good_until_it_expires(): void {
		$good = $this->payload();
		$this->seed_last_good( 42, $good );
		$this->answer( 'site_event', $this->fail_result( Result::UNAVAILABLE ) );
		$this->repo()->get( 42 );

		$this->assertSame( 60, $this->ttl_of( Event_Repository::BACKOFF ) );

		// Next request: no HTTP at all, last-good as stale.
		$view = $this->repo()->get( 42 );
		$this->assertCount( 1, $this->calls );
		$this->assertTrue( $view->is_stale() );
		$this->assertSame( $good['event'], $view->event() );

		// Other events are protected too, and without last-good they are UNAVAILABLE.
		$this->assertSame( Event_View::UNAVAILABLE, $this->repo()->get( 99 )->state() );
		$this->assertCount( 1, $this->calls );

		// The breaker expires after 60 s.
		$this->now += 61;
		$this->answer( 'site_event', Result::ok( $good ) );
		$view = $this->repo()->get( 42 );
		$this->assertCount( 2, $this->calls );
		$this->assertFalse( $view->is_stale() );
	}

	public function test_breaker_also_covers_the_public_path(): void {
		$this->settings( array( 'site_key' => '' ) );
		$this->answer( 'public_event', $this->fail_result( Result::UNAVAILABLE ) );
		$this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertSame( Event_View::UNAVAILABLE, $this->repo()->get( 42, 'sommet-tech-abidjan-2026' )->state() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_successful_fetch_clears_the_breaker(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ) );

		$this->repo()->get( 42 );

		$this->assertNotEmpty(
			array_filter(
				$this->writes,
				static function ( $w ) {
					return 'delete_transient' === $w[0] && Event_Repository::BACKOFF === $w[1];
				}
			)
		);
	}

	public function test_cached_reads_do_not_clear_the_breaker(): void {
		$this->transients[ $this->pub( 42 ) ]        = array( $this->payload(), 0 );
		$this->transients[ Event_Repository::BACKOFF ] = array( 1, $this->now + 60 );

		$this->repo()->get( 42 );

		$this->assertSame( 1, $this->transient( Event_Repository::BACKOFF ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_list_for_editors_bypasses_the_breaker_and_does_not_clear_it(): void {
		$this->transients[ Event_Repository::BACKOFF ] = array( 1, $this->now + 60 );
		$list = $this->fixture( 'events-list' );
		$this->answer( 'list_events', Result::ok( $list ) );

		$result = $this->repo()->list_for_editors();

		$this->assertTrue( $result->ok );
		$this->assertSame( $list, $result->data );
		$this->assertSame( 1, $this->transient( Event_Repository::BACKOFF ) );
	}

	// ---- draft privacy ---------------------------------------------------------

	/** JSON of every value ever written, to search for leaked content. */
	private function everything_written(): string {
		return (string) json_encode(
			array_map(
				static function ( $w ) {
					return $w[2];
				},
				$this->writes
			)
		);
	}

	public function test_draft_for_a_visitor_is_empty_and_only_a_content_free_marker_is_written(): void {
		$this->seed_last_good( 7, $this->payload( 'published', 'payload-v1-minimal' ) );
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );

		$view = $this->repo()->get( 7 );

		$this->assertSame( Event_View::DRAFT, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertFalse( $view->is_preview() );
		$this->assertSame( array(), $view->event() );
		$this->assertNull( $view->notice_key() );
		$this->assertSame( 0, $this->nocache );

		$this->assertSame( array( '__state' => 'draft' ), $this->transient( $this->pub( 7 ) ) );
		$this->assertSame( 900, $this->ttl_of( $this->pub( 7 ) ) );
		$this->assertSame( array(), $this->writes_to( $this->pvw( 7 ) ) );
		$this->assertArrayNotHasKey( $this->lastgood( 7 ), $this->options, 'a now-draft event must lose its last-good copy' );
		$written = $this->everything_written();
		$this->assertStringNotContainsString( 'Small Meetup', $written );
		$this->assertStringNotContainsString( 'small-meetup', $written );
	}

	public function test_draft_marker_saves_the_next_visitor_call(): void {
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->repo()->get( 7 );

		$view = $this->repo()->get( 7 );

		$this->assertSame( Event_View::DRAFT, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_draft_for_an_editor_is_previewed_and_written_only_to_the_preview_key(): void {
		$payload = $this->fixture( 'payload-v1-minimal' );
		$this->answer( 'site_event', Result::ok( $payload ) );

		$view = $this->repo()->get( 7, '', true );

		$this->assertSame( Event_View::DRAFT, $view->state() );
		$this->assertTrue( $view->is_preview() );
		$this->assertTrue( $view->has_content() );
		$this->assertSame( 'Small Meetup', $view->event()['name'] );
		$this->assertSame( 'draft_preview', $view->notice_key() );
		$this->assertSame( 1, $this->nocache );

		$this->assertSame( $payload, $this->transient( $this->pvw( 7 ) ) );
		$this->assertSame( 60, $this->ttl_of( $this->pvw( 7 ) ) );
		$this->assertArrayNotHasKey( $this->lastgood( 7 ), $this->options );
		foreach ( $this->writes as $write ) {
			if ( $this->pvw( 7 ) === $write[1] ) {
				continue;
			}
			$this->assertStringNotContainsString( 'Small Meetup', (string) json_encode( $write[2] ), 'content written to ' . $write[1] );
		}
		$this->assertSame( array( '__state' => 'draft' ), $this->transient( $this->pub( 7 ) ) );
	}

	public function test_editor_bypasses_the_draft_marker(): void {
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ), Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->repo()->get( 7 ); // a visitor leaves the draft marker
		$this->assertCount( 0, $this->writes_to( $this->pvw( 7 ) ) );

		$view = $this->repo()->get( 7, '', true );

		$this->assertTrue( $view->is_preview() );
		$this->assertSame( 'Small Meetup', $view->event()['name'] );
		$this->assertCount( 2, $this->calls, 'the marker is content-free, so the editor must fetch' );
	}

	public function test_editor_second_request_uses_the_preview_transient(): void {
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->repo()->get( 7, '', true );

		$view = $this->repo()->get( 7, '', true );

		$this->assertCount( 1, $this->calls );
		$this->assertTrue( $view->is_preview() );
		$this->assertSame( 2, $this->nocache );
	}

	public function test_visitor_never_reads_the_preview_transient(): void {
		$this->transients[ $this->pvw( 7 ) ] = array( $this->fixture( 'payload-v1-minimal' ), $this->now + 60 );
		$this->transients[ $this->pub( 7 ) ] = array( array( '__state' => 'draft' ), $this->now + 900 );

		$view = $this->repo()->get( 7 );

		$this->assertFalse( $view->has_content() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_memoised_preview_still_sends_no_cache_headers(): void {
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$repo = $this->repo();

		$repo->get( 7, '', true );
		$repo->get( 7, '', true );

		$this->assertSame( 2, $this->nocache );
	}

	public function test_published_views_do_not_send_no_cache_headers(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ) );

		$this->repo()->get( 42, '', true );

		$this->assertSame( 0, $this->nocache );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preview_defines_donotcachepage(): void {
		$this->answer( 'site_event', Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->repo()->get( 7, '', true );

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
		$this->assertTrue( DONOTCACHEPAGE );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_non_preview_views_leave_donotcachepage_alone(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ), Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );

		$this->repo()->get( 42, '', true );
		$this->repo()->get( 7 );

		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );
	}

	// ---- locale ------------------------------------------------------------------

	public function test_locale_is_sent_and_is_part_of_every_cache_key(): void {
		$fr          = $this->payload();
		$fr['locale'] = 'fr';
		$en          = $this->payload();
		$en['locale'] = 'en';

		$this->wp_locale = 'fr_FR';
		$this->answer( 'site_event', Result::ok( $fr ) );
		$this->repo()->get( 42 );

		$this->wp_locale = 'en_GB';
		$this->answer( 'site_event', Result::ok( $en ) );
		$this->repo()->get( 42 );

		$this->wp_locale = 'de_DE';
		$this->answer( 'site_event', Result::ok( $en ) );
		$this->repo()->get( 42 );

		$this->assertSame(
			array(
				array( 'site_event', 42, 'fr' ),
				array( 'site_event', 42, 'en' ),
				array( 'site_event', 42, null ),
			),
			$this->calls
		);
		$this->assertSame( 'fr', $this->transient( $this->pub( 42, 'fr' ) )['locale'] );
		$this->assertSame( 'en', $this->transient( $this->pub( 42, 'en' ) )['locale'] );
		$this->assertIsArray( $this->transient( $this->pub( 42, 'default' ) ) );
		$this->assertSame( 'fr', $this->options[ $this->lastgood( 42, 'fr' ) ]['locale'] );
		$this->assertSame( 'en', $this->options[ $this->lastgood( 42, 'en' ) ]['locale'] );

		// Back to French: served from the fr cache, no call.
		$this->wp_locale = 'fr_CA';
		$this->assertSame( 'fr', $this->transient( $this->pub( 42, 'fr' ) )['locale'] );
		$this->repo()->get( 42 );
		$this->assertCount( 3, $this->calls );
	}

	public function test_memo_is_per_locale(): void {
		$repo = $this->repo();
		$this->answer( 'site_event', Result::ok( $this->payload() ), Result::ok( $this->payload() ) );

		$this->wp_locale = 'fr_FR';
		$repo->get( 42 );
		$this->wp_locale = 'en_US';
		$repo->get( 42 );

		$this->assertCount( 2, $this->calls );
	}

	// ---- purge -------------------------------------------------------------------

	public function test_purge_removes_every_locale_marker_last_good_and_memo(): void {
		$repo = $this->repo();
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$repo->get( 42 );

		foreach ( array( 'en', 'fr', 'default' ) as $loc ) {
			$this->transients[ $this->pub( 42, $loc ) ] = array( array( '__state' => 'draft' ), 0 );
			$this->transients[ $this->pvw( 42, $loc ) ] = array( $this->payload( 'draft' ), 0 );
			$this->seed_last_good( 42, $this->payload(), $loc );
		}
		$this->transients[ $this->pub( 43, 'en' ) ] = array( $this->payload(), 0 );

		$repo->purge( 42 );

		foreach ( array( 'en', 'fr', 'default' ) as $loc ) {
			$this->assertNull( $this->transient( $this->pub( 42, $loc ) ), "public {$loc}" );
			$this->assertNull( $this->transient( $this->pvw( 42, $loc ) ), "preview {$loc}" );
			$this->assertArrayNotHasKey( $this->lastgood( 42, $loc ), $this->options, "last-good {$loc}" );
		}
		$this->assertNotNull( $this->transient( $this->pub( 43, 'en' ) ), 'other events are untouched' );

		// Memo cleared: the same repository fetches again.
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$repo->get( 42 );
		$this->assertCount( 2, $this->calls );
	}

	public function test_purge_clears_the_gone_marker(): void {
		$this->answer( 'site_event', $this->fail_result( Result::NOT_FOUND, 404 ), Result::ok( $this->payload() ) );
		$this->repo()->get( 42 );

		$repo = $this->repo();
		$repo->purge( 42 );

		$this->assertSame( Event_View::LIVE, $repo->get( 42 )->state() );
	}

	public function test_purge_all_removes_everything_the_plugin_cached(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ), Result::ok( $this->fixture( 'payload-v1-minimal' ) ) );
		$this->answer( 'list_events', Result::ok( $this->fixture( 'events-list' ) ) );
		$repo = $this->repo();
		$repo->get( 42 );
		$this->wp_locale = 'fr_FR';
		$repo->get( 7, '', true );
		$repo->list_for_editors();
		$this->transients[ Event_Repository::BACKOFF ]            = array( 1, $this->now + 60 );
		$this->transients[ Event_Repository::INVALID_KEY_NOTICE ] = array( 1, $this->now + 60 );

		$repo->purge_all();

		$left_transients = array_diff( array_keys( $this->transients ), array( Options::CONN_DISABLED_FLAG ) );
		$this->assertSame( array(), array_values( $left_transients ) );
		$this->assertSame( array( Options::OPTION ), array_keys( $this->options ) );

		// Memo cleared too.
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$this->wp_locale = 'en_US';
		$repo->get( 42 );
		$this->assertCount( 3, $this->http_calls( 'site_event' ) );
	}

	public function test_tracking_option_is_not_autoloaded(): void {
		$this->answer( 'site_event', Result::ok( $this->payload() ) );
		$this->repo()->get( 42 );

		foreach ( $this->writes as $write ) {
			if ( 'update_option' === $write[0] ) {
				$this->assertFalse( $write[3], $write[1] . ' must not autoload' );
				$this->assertStringStartsWith( 'odavent_events_lastgood_', $write[1], 'uninstall removes odavent_events_lastgood_*' );
			}
		}
	}

	// ---- resolve_id / list -------------------------------------------------------

	public function test_resolve_id_uses_the_cached_events_list(): void {
		$this->answer( 'list_events', Result::ok( $this->fixture( 'events-list' ) ) );
		$repo = $this->repo();

		$this->assertSame( 42, $repo->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertSame( 12, $repo->resolve_id( 'meetup-fintech-2025' ) );
		$this->assertNull( $repo->resolve_id( 'not-in-the-list' ) );
		$this->assertSame( 43, $this->repo()->resolve_id( 'atelier-ia-2026' ) );

		$this->assertSame( array( array( 'list_events' ) ), $this->calls );
		$this->assertSame( 300, $this->ttl_of( Event_Repository::LIST_TRANSIENT ) );
	}

	public function test_resolve_id_of_an_empty_slug_is_null_without_http(): void {
		$this->assertNull( $this->repo()->resolve_id( '' ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_list_for_editors_is_cached_for_five_minutes(): void {
		$list = $this->fixture( 'events-list' );
		$this->answer( 'list_events', Result::ok( $list ), Result::ok( $list ) );

		$this->repo()->list_for_editors();
		$second = $this->repo()->list_for_editors();
		$this->assertTrue( $second->ok );
		$this->assertSame( $list, $second->data );
		$this->assertCount( 1, $this->calls );

		$this->now += 301;
		$this->repo()->list_for_editors();
		$this->assertCount( 2, $this->calls );
	}

	public function test_list_for_editors_returns_the_real_error_and_does_not_cache_it(): void {
		$this->answer( 'list_events', $this->fail_result( Result::UNAVAILABLE, 502 ), $this->fail_result( Result::UNAVAILABLE, 502 ) );

		$result = $this->repo()->list_for_editors();

		$this->assertFalse( $result->ok );
		$this->assertSame( Result::UNAVAILABLE, $result->code );
		$this->assertNull( $this->transient( Event_Repository::LIST_TRANSIENT ) );
		$this->repo()->list_for_editors();
		$this->assertCount( 2, $this->calls );
	}

	public function test_resolve_id_respects_the_breaker(): void {
		$this->transients[ Event_Repository::BACKOFF ] = array( 1, $this->now + 60 );

		$this->assertNull( $this->repo()->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertSame( array(), $this->calls );
	}

	public function test_resolve_id_falls_back_to_the_last_known_slug_map_when_the_list_fails(): void {
		$this->answer( 'list_events', Result::ok( $this->fixture( 'events-list' ) ), $this->fail_result( Result::UNAVAILABLE ) );
		$this->repo()->resolve_id( 'sommet-tech-abidjan-2026' );
		$this->now += 301;

		$this->assertSame( 42, $this->repo()->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertNull( $this->repo()->resolve_id( 'atelier-ia-2026' ), 'draft slugs are not kept' );
		$this->assertSame( 1, $this->transient( Event_Repository::BACKOFF ) );
	}

	public function test_resolve_id_public_path_seeds_the_cache_so_get_makes_no_second_call(): void {
		$this->settings( array( 'site_key' => '' ) );
		$payload = $this->payload();
		$this->answer( 'public_event', Result::ok( $payload ) );
		$repo = $this->repo();

		$id   = $repo->resolve_id( 'sommet-tech-abidjan-2026' );
		$view = $repo->get( (int) $id, 'sommet-tech-abidjan-2026' );

		$this->assertSame( 42, $id );
		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertSame( array( array( 'public_event', 'sommet-tech-abidjan-2026', 'en' ) ), $this->calls );
		$this->assertSame( $payload, $this->transient( $this->pub( 42 ) ) );
		$this->assertSame( $payload, $this->options[ $this->lastgood( 42 ) ] );

		// Next request: slug and event both come from the cache.
		$repo = $this->repo();
		$this->assertSame( 42, $repo->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertSame( Event_View::LIVE, $repo->get( 42, 'sommet-tech-abidjan-2026' )->state() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_resolve_id_public_path_seeds_the_memo_even_when_transients_do_not_persist(): void {
		// Transient writes can fail silently (object cache down, DB write refused).
		Functions\when( 'set_transient' )->justReturn( false );
		$this->settings( array( 'site_key' => '' ) );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );
		$repo = $this->repo();

		$id = $repo->resolve_id( 'sommet-tech-abidjan-2026' );

		$this->assertSame( Event_View::LIVE, $repo->get( (int) $id, 'sommet-tech-abidjan-2026' )->state() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_resolve_id_while_disabled_uses_the_public_path(): void {
		$this->transients[ Options::CONN_DISABLED_FLAG ] = array( 1, $this->now + 3600 );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );

		$this->assertSame( 42, $this->repo()->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertSame( array(), $this->http_calls( 'list_events' ) );
	}

	public function test_resolve_id_public_not_found_is_null(): void {
		$this->settings( array( 'site_key' => '' ) );
		$this->answer( 'public_event', $this->fail_result( Result::NOT_FOUND, 404 ) );

		$this->assertNull( $this->repo()->resolve_id( 'nope' ) );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ) );
	}

	public function test_resolve_id_list_plan_required_disables_and_uses_public_read(): void {
		$this->answer( 'list_events', $this->fail_result( Result::PLAN_REQUIRED, 403 ) );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );

		$this->assertSame( 42, $this->repo()->resolve_id( 'sommet-tech-abidjan-2026' ) );
		$this->assertTrue( ( new Options() )->is_connection_disabled() );
	}

	// ---- no key --------------------------------------------------------------------

	public function test_without_key_and_slug_no_http_is_made(): void {
		$this->settings( array( 'site_key' => '' ) );

		$this->assertSame( Event_View::UNAVAILABLE, $this->repo()->get( 42 )->state() );

		$this->seed_last_good( 42, $this->payload() );
		$this->assertTrue( $this->repo()->get( 42 )->is_stale() );
		$this->assertSame( array(), $this->calls );
	}

	public function test_without_key_public_read_is_cached(): void {
		$this->settings( array( 'site_key' => '' ) );
		$this->answer( 'public_event', Result::ok( $this->payload() ) );

		$view = $this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertSame( array( array( 'public_event', 'sommet-tech-abidjan-2026', 'en' ) ), $this->calls );
		$this->assertIsArray( $this->transient( $this->pub( 42 ) ) );
	}

	public function test_without_key_public_404_serves_last_good_as_stale(): void {
		$this->settings( array( 'site_key' => '' ) );
		$this->seed_last_good( 42, $this->payload() );
		$this->answer( 'public_event', $this->fail_result( Result::NOT_FOUND, 404 ) );

		$view = $this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertTrue( $view->is_stale() );
		$this->assertSame( Event_View::LIVE, $view->state() );
		$this->assertArrayHasKey( $this->lastgood( 42 ), $this->options, 'a public 404 is ambiguous: keep last-good' );

		// And the next request does not ask again for a while.
		$this->assertTrue( $this->repo()->get( 42, 'sommet-tech-abidjan-2026' )->is_stale() );
		$this->assertCount( 1, $this->calls );
	}

	public function test_without_key_public_404_without_last_good_is_gone(): void {
		$this->settings( array( 'site_key' => '' ) );
		$this->answer( 'public_event', $this->fail_result( Result::NOT_FOUND, 404 ) );

		$this->assertSame( Event_View::GONE, $this->repo()->get( 42, 'sommet-tech-abidjan-2026' )->state() );
		$this->assertNull( $this->transient( Event_Repository::BACKOFF ) );
	}

	public function test_public_read_of_a_slug_now_used_by_another_event_is_not_cached_under_this_id(): void {
		$this->settings( array( 'site_key' => '' ) );
		$other                = $this->payload();
		$other['event']['id'] = 99;
		$this->answer( 'public_event', Result::ok( $other ) );

		$view = $this->repo()->get( 42, 'sommet-tech-abidjan-2026' );

		$this->assertSame( Event_View::GONE, $view->state() );
		$this->assertFalse( $view->has_content() );
		$this->assertStringNotContainsString( 'Sommet', (string) json_encode( $this->transient( $this->pub( 42 ) ) ) );
		$this->assertArrayNotHasKey( $this->lastgood( 42 ), $this->options );
		$this->assertArrayNotHasKey( $this->lastgood( 99 ), $this->options );
	}

	public function test_invalid_event_id_makes_no_http(): void {
		$this->assertSame( Event_View::GONE, $this->repo()->get( 0 )->state() );
		$this->assertSame( Event_View::GONE, $this->repo()->get( -5 )->state() );
		$this->assertSame( array(), $this->calls );
	}
}

<?php
namespace Odavent\Events\Tests\Unit\Rest;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Api\Result;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Rest\Editor_Controller;
use Odavent\Events\Tests\Support\RenderTestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class EditorControllerTest extends RenderTestCase {

	const KEY = 'odv_wp_supersecretkey1234';

	/** @var bool */
	private $can_edit = true;

	/** @var bool */
	private $logged_in = true;

	/** @var array[] */
	private $routes = array();

	protected function setUp(): void {
		parent::setUp();
		$this->can_edit  = true;
		$this->logged_in = true;
		$this->routes    = array();

		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'edit_posts' === $capability && $this->can_edit;
			}
		);
		Functions\when( 'rest_authorization_required_code' )->alias(
			function () {
				return $this->logged_in ? 403 : 401;
			}
		);
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) {
				$this->routes[] = array( $namespace, $route, $args );
				return true;
			}
		);
	}

	private function controller( Result $result ): Editor_Controller {
		$repo = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'list_for_editors' )->andReturn( $result );

		return new Editor_Controller( $repo );
	}

	private function route( Result $result ): array {
		$this->controller( $result )->register_routes();
		$this->assertCount( 1, $this->routes );

		return $this->routes[0];
	}

	public function test_the_route_is_a_get_on_odavent_v1_events(): void {
		list( $namespace, $route, $args ) = $this->route( Result::ok( array() ) );

		$this->assertSame( 'odavent/v1', $namespace );
		$this->assertSame( '/events', $route );
		$this->assertSame( 'GET', $args['methods'] );
		$this->assertIsCallable( $args['callback'] );
		$this->assertIsCallable( $args['permission_callback'] );
		$this->assertNotSame( '__return_true', $args['permission_callback'] );
	}

	public function test_the_permission_callback_requires_edit_posts(): void {
		$capabilities = array();
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) use ( &$capabilities ) {
				$capabilities[] = $capability;
				return false;
			}
		);
		list( , , $args ) = $this->route( Result::ok( array() ) );

		$this->assertNotTrue( call_user_func( $args['permission_callback'], new WP_REST_Request( 'GET', '/odavent/v1/events' ) ) );
		$this->assertSame( array( 'edit_posts' ), $capabilities );
	}

	public function test_the_permission_callback_follows_the_preview_capability_filter(): void {
		Filters\expectApplied( 'odavent_events_preview_capability' )->andReturn( 'edit_others_posts' );
		$asked = array();
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) use ( &$asked ) {
				$asked[] = $capability;
				return 'edit_posts' === $capability;
			}
		);
		list( , , $args ) = $this->route( Result::ok( array() ) );

		$this->assertInstanceOf( WP_Error::class, call_user_func( $args['permission_callback'], new WP_REST_Request( 'GET' ) ) );
		$this->assertSame( array( 'edit_others_posts' ), $asked );
	}

	public function test_permission_matrix(): void {
		list( , , $args ) = $this->route( Result::ok( array() ) );
		$check            = $args['permission_callback'];

		$this->can_edit = true;
		$this->assertTrue( call_user_func( $check, new WP_REST_Request( 'GET' ) ) );

		$this->can_edit  = false;
		$this->logged_in = true;
		$denied          = call_user_func( $check, new WP_REST_Request( 'GET' ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 403, $denied->get_error_data()['status'] );

		$this->logged_in = false;
		$denied          = call_user_func( $check, new WP_REST_Request( 'GET' ) );
		$this->assertInstanceOf( WP_Error::class, $denied );
		$this->assertSame( 401, $denied->get_error_data()['status'] );
	}

	public function test_it_lists_events_with_clean_strings(): void {
		$rows   = $this->fixture( 'events-list' );
		$rows[] = array( 'id' => 5, 'slug' => 'x<script>alert(1)</script>', 'name' => '<b>Bold</b> <script>alert(1)</script>', 'status' => 'published', 'secret' => self::KEY );
		$rows[] = array( 'id' => '6', 'slug' => 'bad-id', 'name' => 'Bad id', 'status' => 'published' );
		$rows[] = 'junk';

		list( , , $args ) = $this->route( Result::ok( $rows ) );
		$response         = call_user_func( $args['callback'], new WP_REST_Request( 'GET' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame(
			array( 'id' => 42, 'slug' => 'sommet-tech-abidjan-2026', 'name' => "Sommet Tech d'Abidjan 2026", 'status' => 'published' ),
			$data[0]
		);
		$this->assertSame( array( 42, 43, 12, 5 ), array_column( $data, 'id' ) );
		$this->assertSame( 'Bold', $data[3]['name'] );
		$this->assertSame( 'x', $data[3]['slug'] );
		$this->assertArrayNotHasKey( 'secret', $data[3] );
	}

	public function errors(): array {
		return array(
			// Literal codes: data providers run before setUp() loads the plugin's autoloader.
			'invalid key'   => array( 'invalid_key', 'odavent_invalid_key', 401 ),
			'plan required' => array( 'plan_required', 'odavent_plan_required', 403 ),
			'unavailable'   => array( 'unavailable', 'odavent_unavailable', 503 ),
			'not found'     => array( 'not_found', 'odavent_unavailable', 503 ),
			'unknown'       => array( 'something_else', 'odavent_unavailable', 503 ),
		);
	}

	/**
	 * @dataProvider errors
	 */
	public function test_failures_become_generic_errors( string $code, string $error_code, int $status ): void {
		$result           = Result::fail( $code, 500, 'Upstream said: ' . self::KEY );
		list( , , $args ) = $this->route( $result );
		$response         = call_user_func( $args['callback'], new WP_REST_Request( 'GET' ) );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( $error_code, $response->code );
		$this->assertSame( $status, $response->get_error_data()['status'] );
		$this->assertStringNotContainsString( self::KEY, serialize( $response ) );
		$this->assertStringNotContainsString( 'Upstream', $response->get_error_message() );
	}

	public function test_a_throwing_repository_is_a_generic_error(): void {
		$repo = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'list_for_editors' )->andThrow( new \RuntimeException( self::KEY ) );
		( new Editor_Controller( $repo ) )->register_routes();

		$response = call_user_func( $this->routes[0][2]['callback'], new WP_REST_Request( 'GET' ) );
		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'odavent_unavailable', $response->code );
		$this->assertStringNotContainsString( self::KEY, serialize( $response ) );
	}
}

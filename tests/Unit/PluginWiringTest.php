<?php
namespace Odavent\Events\Tests\Unit;

use Brain\Monkey\Functions;
use Odavent\Events\Api\Client;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Plugin;
use Odavent\Events\Refresh\Page_Cache_Purger;
use Odavent\Events\Tests\TestCase;

class PluginWiringTest extends TestCase {

	/** @var string[] Hooks registered through add_action. */
	private $hooks = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 2 ) . '/odavent-events.php';

		Functions\when( 'add_action' )->alias(
			function ( $hook ) {
				$this->hooks[] = $hook;
				return true;
			}
		);
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'plugin_basename' )->justReturn( 'odavent-events/odavent-events.php' );
	}

	/**
	 * A fresh Plugin (the real one is a process-wide singleton).
	 */
	private function fresh_plugin(): Plugin {
		$class = new \ReflectionClass( Plugin::class );

		return $class->newInstanceWithoutConstructor();
	}

	public function test_client_and_repository_are_singletons_inside_the_plugin(): void {
		$plugin = $this->fresh_plugin();

		$this->assertInstanceOf( Client::class, $plugin->client() );
		$this->assertSame( $plugin->client(), $plugin->client() );
		$this->assertInstanceOf( Event_Repository::class, $plugin->repository() );
		$this->assertSame( $plugin->repository(), $plugin->repository() );
	}

	public function test_boot_registers_the_settings_page_only_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );
		$this->fresh_plugin()->boot();
		$this->assertContains( 'admin_menu', $this->hooks );
		$this->assertContains( 'admin_post_odavent_events_test_connection', $this->hooks );
	}

	public function test_boot_registers_nothing_on_the_front_end(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$this->fresh_plugin()->boot();
		$this->assertNotContains( 'admin_menu', $this->hooks );
	}

	public function test_boot_registers_the_refresh_route_on_rest_api_init(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$callbacks = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$callbacks ) {
				$callbacks[ $hook ] = $callback;
				return true;
			}
		);
		$plugin = $this->fresh_plugin();
		$plugin->boot();
		$this->assertArrayHasKey( 'rest_api_init', $callbacks );

		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = $namespace . $route . ' ' . $args['methods'];
				return true;
			}
		);
		call_user_func( $callbacks['rest_api_init'] );

		$this->assertSame( array( 'odavent/v1/refresh POST' ), $routes );
		$this->assertInstanceOf( Page_Cache_Purger::class, $plugin->purger() );
		$this->assertSame( $plugin->purger(), $plugin->purger() );
	}
}

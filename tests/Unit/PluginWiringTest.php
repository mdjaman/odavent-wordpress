<?php
namespace Odavent\Events\Tests\Unit;

use Brain\Monkey\Functions;
use Odavent\Events\Api\Client;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Render\Assets;
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

		$this->assertSame( array( 'odavent/v1/refresh POST', 'odavent/v1/events GET' ), $routes );
		$this->assertInstanceOf( Page_Cache_Purger::class, $plugin->purger() );
		$this->assertSame( $plugin->purger(), $plugin->purger() );
	}

	public function test_boot_wires_blocks_assets_preview_and_category(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		$actions = array();
		$filters = array();
		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback ) use ( &$actions ) {
				$actions[ $hook ] = $callback;
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback ) use ( &$filters ) {
				$filters[ $hook ] = $callback;
				return true;
			}
		);

		$plugin = $this->fresh_plugin();
		$plugin->boot();

		foreach ( array( 'init', 'rest_api_init', 'template_redirect', 'wp_enqueue_scripts', 'enqueue_block_editor_assets' ) as $hook ) {
			$this->assertArrayHasKey( $hook, $actions, $hook );
		}
		$this->assertArrayHasKey( 'block_categories_all', $filters );
		$this->assertInstanceOf( Block_Registry::class, $plugin->blocks() );
		$this->assertSame( $plugin->blocks(), $plugin->blocks() );
		$this->assertInstanceOf( Assets::class, $plugin->assets() );
		$this->assertSame( $plugin->assets(), $plugin->assets() );

		// init: the stylesheet handle exists BEFORE the block types that name it as editorStyle.
		$order = array();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.com/x' );
		Functions\when( 'wp_style_is' )->justReturn( false );
		Functions\when( 'wp_register_style' )->alias(
			function ( $handle ) use ( &$order ) {
				$order[] = 'style:' . $handle;
				return true;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle ) use ( &$order ) {
				$order[] = 'script:' . $handle;
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->justReturn( true );
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'register_block_type' )->alias(
			function ( $dir ) use ( &$order ) {
				$order[] = 'block:' . basename( $dir );
				return true;
			}
		);
		call_user_func( $actions['init'] );

		$this->assertSame( 'style:odavent-events', $order[0] );
		$this->assertSame( 'script:odavent-events-editor', $order[1] );
		$this->assertContains( 'block:event-header', $order );

		$inline = array();
		Functions\when( 'wp_add_inline_script' )->alias(
			function ( $handle, $code, $position ) use ( &$inline ) {
				$inline[] = array( $handle, $position );
				return true;
			}
		);
		call_user_func( $actions['enqueue_block_editor_assets'] );
		$this->assertSame( array( array( 'odavent-events-editor', 'before' ) ), $inline );

		$categories = call_user_func( $filters['block_categories_all'], array() );
		$this->assertSame( 'odavent-events', $categories[0]['slug'] );
	}
}

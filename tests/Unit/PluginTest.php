<?php
namespace Odavent\Events\Tests\Unit;

use Brain\Monkey\Functions;
use Odavent\Events\Plugin;
use Odavent\Events\Tests\TestCase;

class PluginTest extends TestCase {

	private function main_file(): string {
		return dirname( __DIR__, 2 ) . '/odavent-events.php';
	}

	/**
	 * Requires the main plugin file with WordPress stubbed (once per process).
	 */
	private function load_main_file(): void {
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once $this->main_file();
	}

	public function test_autoloader_loads_plugin_class(): void {
		// Run in a clean PHP process so Composer's autoloader cannot satisfy the lookup:
		// only the plugin's own hand-written autoloader is in play.
		$script = sys_get_temp_dir() . '/odavent-events-autoload-check-' . getmypid() . '.php';
		file_put_contents(
			$script,
			'<?php
define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["wp_version"] = "6.5";
$hooks = array();
function add_action( $hook ) { global $hooks; $hooks[] = $hook; return true; }
require ' . var_export( $this->main_file(), true ) . ';
echo ( class_exists( "Odavent\\\\Events\\\\Plugin" ) ? "class-ok" : "class-missing" ) . "|" . implode( ",", $hooks );'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		$this->assertSame( 'class-ok|plugins_loaded', trim( (string) $output ) );
	}

	public function test_instance_is_a_singleton(): void {
		$this->load_main_file();

		$this->assertInstanceOf( Plugin::class, Plugin::instance() );
		$this->assertSame( Plugin::instance(), Plugin::instance() );
	}

	public function test_version_matches_header(): void {
		$this->load_main_file();

		$header = (string) file_get_contents( $this->main_file() );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $header, $m ) );

		$this->assertSame( $m[1], ODAVENT_EVENTS_VERSION );
		$this->assertSame( $m[1], Plugin::instance()->version() );
	}
}

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

	/**
	 * Runs PHP code in a clean process (no Composer autoloader, no Brain Monkey) and returns its output.
	 *
	 * @param string $code Code after the opening tag.
	 */
	private function run_in_clean_process( string $code ): string {
		$script = sys_get_temp_dir() . '/odavent-events-clean-' . getmypid() . '-' . uniqid() . '.php';
		file_put_contents( $script, "<?php\n" . $code );
		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d error_reporting=-1 ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		return trim( (string) $output );
	}

	public function test_second_copy_of_the_plugin_in_the_same_request_does_nothing(): void {
		$copy_dir = sys_get_temp_dir() . '/odavent-events-copy-' . getmypid() . '-' . uniqid();
		mkdir( $copy_dir );
		copy( $this->main_file(), $copy_dir . '/odavent-events.php' );

		$output = $this->run_in_clean_process(
			'define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["wp_version"] = "6.5";
$hooks = array();
function add_action( $hook ) { global $hooks; $hooks[] = $hook; return true; }
require ' . var_export( $this->main_file(), true ) . ';
require ' . var_export( $copy_dir . '/odavent-events.php', true ) . ';
echo "dir-first=" . ( ODAVENT_EVENTS_DIR === ' . var_export( dirname( $this->main_file() ) . '/', true ) . ' ? "yes" : "no" ) . "|hooks=" . implode( ",", $hooks );'
		);
		unlink( $copy_dir . '/odavent-events.php' );
		rmdir( $copy_dir );

		// No fatal "Cannot redeclare", no warning, the first copy keeps the constants and hooks once.
		$this->assertSame( 'dir-first=yes|hooks=plugins_loaded', $output );
	}

	public function test_including_the_main_file_twice_neither_warns_nor_fatals(): void {
		$output = $this->run_in_clean_process(
			'define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["wp_version"] = "6.5";
$hooks = array();
function add_action( $hook ) { global $hooks; $hooks[] = $hook; return true; }
require ' . var_export( $this->main_file(), true ) . ';
require ' . var_export( $this->main_file(), true ) . ';
echo "hooks=" . implode( ",", $hooks ) . "|autoloaders=" . count( spl_autoload_functions() );'
		);

		$this->assertSame( 'hooks=plugins_loaded|autoloaders=1', $output );
	}

	public function test_autoloader_refuses_names_that_could_leave_src_and_loads_nested_classes(): void {
		$plugin_dir = sys_get_temp_dir() . '/odavent-events-autoload-' . getmypid() . '-' . uniqid();
		mkdir( $plugin_dir . '/src/Sub', 0777, true );
		copy( $this->main_file(), $plugin_dir . '/odavent-events.php' );
		file_put_contents( $plugin_dir . '/src/Sub/Nested.php', '<?php namespace Odavent\\Events\\Sub; class Nested {}' );
		file_put_contents( $plugin_dir . '/evil.php', '<?php echo "ESCAPED"; ' );
		file_put_contents( $plugin_dir . '/src/Plain.php', '<?php namespace Odavent\\Events; class Plain {}' );

		$output = $this->run_in_clean_process(
			'define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["wp_version"] = "6.5";
function add_action() { return true; }
require ' . var_export( $plugin_dir . '/odavent-events.php', true ) . ';
$load = spl_autoload_functions()[0];
foreach ( array( "Odavent\\Events\\..\\evil", "Odavent\\Events\\Sub\\..\\..\\evil", "Odavent\\Events\\.\\Plain", "Odavent\\Events\\Plain\0", "Odavent\\Events\\Pla in" ) as $name ) {
	$load( $name );
}
echo "|nested=";
$load( "Odavent\\Events\\Sub\\Nested" );
echo class_exists( "Odavent\\Events\\Sub\\Nested", false ) ? "loaded" : "missing";
$load( "Odavent\\Events\\Plain" );
echo "|plain=" . ( class_exists( "Odavent\\Events\\Plain", false ) ? "loaded" : "missing" );'
		);

		unlink( $plugin_dir . '/odavent-events.php' );
		unlink( $plugin_dir . '/evil.php' );
		unlink( $plugin_dir . '/src/Plain.php' );
		unlink( $plugin_dir . '/src/Sub/Nested.php' );
		rmdir( $plugin_dir . '/src/Sub' );
		rmdir( $plugin_dir . '/src' );
		rmdir( $plugin_dir );

		$this->assertSame( '|nested=loaded|plain=loaded', $output );
	}
}

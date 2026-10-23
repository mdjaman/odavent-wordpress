<?php
namespace Odavent\Events\Tests\Unit;

use Brain\Monkey\Functions;
use Odavent\Events\Tests\TestCase;

class UninstallerTest extends TestCase {

	/** @var mixed */
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 2 ) . '/odavent-events.php';
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	/**
	 * A $wpdb double that records every prepared query.
	 *
	 * @param array $queries Collected by reference: list of [sql, arg].
	 */
	private function fake_wpdb( array &$queries ): \Mockery\MockInterface {
		$wpdb          = \Mockery::mock( 'wpdb' );
		$wpdb->options  = 'wp_options';
		$wpdb->sitemeta = 'wp_sitemeta';

		$wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $text ) {
				return addcslashes( $text, '_%\\' );
			}
		);
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			static function ( $sql, $arg ) {
				return $sql . ' [' . $arg . ']';
			}
		);
		$wpdb->shouldReceive( 'query' )->andReturnUsing(
			static function ( $sql ) use ( &$queries ) {
				$queries[] = $sql;
				return 1;
			}
		);

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}

	public function test_single_site_deletes_settings_lastgood_options_and_transients(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\expect( 'delete_option' )->once()->with( 'odavent_events_settings' );

		$queries = array();
		$this->fake_wpdb( $queries );

		\Odavent\Events\Uninstaller::run();

		$this->assertCount( 5, $queries );
		$this->assertStringContainsString( 'DELETE FROM wp_options WHERE option_name LIKE %s [odavent\_events\_lastgood\_%]', $queries[0] );
		$this->assertStringContainsString( '[\_transient\_odavent\_events\_%]', $queries[1] );
		$this->assertStringContainsString( '[\_transient\_timeout\_odavent\_events\_%]', $queries[2] );
		$this->assertStringContainsString( '[\_site\_transient\_odavent\_events\_%]', $queries[3] );
		$this->assertStringContainsString( '[\_site\_transient\_timeout\_odavent\_events\_%]', $queries[4] );
	}

	public function test_multisite_cleans_every_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->andReturn( array( 1, 4 ) );
		Functions\expect( 'switch_to_blog' )->once()->with( 1 )->ordered();
		Functions\expect( 'delete_option' )->twice()->with( 'odavent_events_settings' );
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'switch_to_blog' )->once()->with( 4 );

		$queries = array();
		$this->fake_wpdb( $queries );

		\Odavent\Events\Uninstaller::run();

		// Five per site, plus the two network-wide site-transient rows in sitemeta.
		$this->assertCount( 12, $queries );

		$network = array_slice( $queries, 10 );
		$this->assertStringContainsString( 'DELETE FROM wp_sitemeta WHERE meta_key LIKE %s [\_site\_transient\_odavent\_events\_%]', $network[0] );
		$this->assertStringContainsString( 'DELETE FROM wp_sitemeta WHERE meta_key LIKE %s [\_site\_transient\_timeout\_odavent\_events\_%]', $network[1] );
	}

	public function test_uninstall_file_is_guarded_by_the_wordpress_constant(): void {
		$script = sys_get_temp_dir() . '/odavent-events-uninstall-guard-' . getmypid() . '.php';
		file_put_contents(
			$script,
			'<?php require ' . var_export( dirname( __DIR__, 2 ) . '/uninstall.php', true ) . '; echo "reached";'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		$this->assertSame( '', trim( (string) $output ) );
	}

	public function test_uninstall_file_runs_the_cleanup_without_the_plugin_loaded(): void {
		$script = sys_get_temp_dir() . '/odavent-events-uninstall-run-' . getmypid() . '.php';
		file_put_contents(
			$script,
			'<?php
define( "WP_UNINSTALL_PLUGIN", "odavent-events/odavent-events.php" );
$log = array();
function is_multisite() { return false; }
function delete_option( $name ) { global $log; $log[] = "delete_option:" . $name; }
class FakeDb {
	public $options = "wp_options";
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, $arg ) { return $arg; }
	public function query( $q ) { global $log; $log[] = "query:" . $q; return 1; }
}
$wpdb = new FakeDb();
require ' . var_export( dirname( __DIR__, 2 ) . '/uninstall.php', true ) . ';
echo implode( "|", $log );'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		$this->assertSame(
			'delete_option:odavent_events_settings|query:odavent_events_lastgood_%|query:_transient_odavent_events_%|query:_transient_timeout_odavent_events_%|query:_site_transient_odavent_events_%|query:_site_transient_timeout_odavent_events_%',
			trim( (string) $output )
		);
	}
}

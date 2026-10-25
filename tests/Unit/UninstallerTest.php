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

	/** @var array Every options-API call: "function:name". */
	private $calls = array();

	/**
	 * A $wpdb double: get_col() answers each prepared LIKE from $rows (table => names) and records the query.
	 * It has no query() method, so a raw DELETE fails the test.
	 *
	 * @param array $queries Collected by reference: list of prepared SQL.
	 * @param array $rows    table => option or meta names in that table.
	 */
	private function fake_wpdb( array &$queries, array $rows ): \Mockery\MockInterface {
		$wpdb           = \Mockery::mock( 'wpdb' );
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
		$wpdb->shouldReceive( 'get_col' )->andReturnUsing(
			static function ( $sql ) use ( &$queries, $rows ) {
				$queries[] = $sql;
				preg_match( '/FROM (\S+) .*\[(.*)%\]$/', $sql, $m );
				$prefix = stripcslashes( str_replace( array( '\\_', '\\%' ), array( '_', '%' ), $m[2] ) );
				return array_values(
					array_filter(
						$rows[ $m[1] ] ?? array(),
						static function ( $name ) use ( $prefix ) {
							return 0 === strpos( $name, $prefix );
						}
					)
				);
			}
		);

		$GLOBALS['wpdb'] = $wpdb;

		return $wpdb;
	}

	private function record_api(): void {
		foreach ( array( 'delete_option', 'delete_transient', 'delete_site_transient', 'delete_site_option' ) as $fn ) {
			Functions\when( $fn )->alias(
				function ( $name ) use ( $fn ) {
					$this->calls[] = $fn . ':' . $name;
					return true;
				}
			);
		}
	}

	public function test_single_site_deletes_settings_lastgood_options_and_transients_through_the_options_api(): void {
		Functions\when( 'is_multisite' )->justReturn( false );
		$this->record_api();

		$queries = array();
		$this->fake_wpdb(
			$queries,
			array(
				'wp_options' => array(
					'odavent_events_lastgood_42_en',
					'_transient_odavent_events_ev_42_en',
					'_transient_timeout_odavent_events_ev_42_en',
					'_site_transient_odavent_events_update',
					'_site_transient_timeout_odavent_events_update',
					// WordPress itself saves widget_<id_base> when a widget registers (WP_Widget::get_settings()).
					'widget_odavent_events_speakers',
					'widget_odavent_events_add_to_calendar',
					'widget_text',
					'other_plugin_option',
				),
			)
		);

		\Odavent\Events\Uninstaller::run();

		$this->assertCount( 6, $queries );
		$this->assertStringContainsString( 'SELECT option_name FROM wp_options WHERE option_name LIKE %s [odavent\_events\_lastgood\_%]', $queries[0] );
		$this->assertStringContainsString( '[\_transient\_odavent\_events\_%]', $queries[1] );
		$this->assertStringContainsString( '[\_transient\_timeout\_odavent\_events\_%]', $queries[2] );
		$this->assertStringContainsString( '[\_site\_transient\_odavent\_events\_%]', $queries[3] );
		$this->assertStringContainsString( '[\_site\_transient\_timeout\_odavent\_events\_%]', $queries[4] );
		$this->assertStringContainsString( '[widget\_odavent\_events\_%]', $queries[5] );

		foreach ( array(
			'delete_option:odavent_events_settings',
			'delete_transient:odavent_events_list',
			'delete_transient:odavent_events_backoff',
			'delete_transient:odavent_events_invalid_key_notice',
			'delete_transient:odavent_events_contract_notice',
			'delete_transient:odavent_events_conn_disabled',
			'delete_site_transient:odavent_events_update',
			'delete_option:odavent_events_lastgood_42_en',
			'delete_option:_transient_odavent_events_ev_42_en',
			'delete_transient:odavent_events_ev_42_en',
			'delete_option:_transient_timeout_odavent_events_ev_42_en',
			'delete_option:_site_transient_odavent_events_update',
			'delete_option:_site_transient_timeout_odavent_events_update',
			'delete_option:widget_odavent_events_speakers',
			'delete_option:widget_odavent_events_add_to_calendar',
		) as $call ) {
			$this->assertContains( $call, $this->calls );
		}
		$this->assertNotContains( 'delete_option:other_plugin_option', $this->calls );
		$this->assertNotContains( 'delete_option:widget_text', $this->calls, 'another widget\'s settings are not ours' );
		$this->assertNotContains( 'delete_transient:timeout_odavent_events_ev_42_en', $this->calls, 'a timeout row is not a transient' );
	}

	public function test_multisite_cleans_every_site(): void {
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\expect( 'get_sites' )->once()->andReturn( array( 1, 4 ) );
		Functions\expect( 'switch_to_blog' )->once()->with( 1 )->ordered();
		Functions\expect( 'restore_current_blog' )->twice();
		Functions\expect( 'switch_to_blog' )->once()->with( 4 );
		$this->record_api();

		$queries = array();
		$this->fake_wpdb(
			$queries,
			array(
				'wp_options'  => array( 'odavent_events_lastgood_7_fr', 'widget_odavent_events_venue' ),
				'wp_sitemeta' => array( '_site_transient_odavent_events_update', '_site_transient_timeout_odavent_events_update', 'site_admins' ),
			)
		);

		\Odavent\Events\Uninstaller::run();

		// Six per site, plus the two network-wide site-transient lookups in sitemeta.
		$this->assertCount( 14, $queries );

		$network = array_slice( $queries, 12 );
		$this->assertStringContainsString( 'SELECT meta_key FROM wp_sitemeta WHERE meta_key LIKE %s [\_site\_transient\_odavent\_events\_%]', $network[0] );
		$this->assertStringContainsString( 'SELECT meta_key FROM wp_sitemeta WHERE meta_key LIKE %s [\_site\_transient\_timeout\_odavent\_events\_%]', $network[1] );

		$counts = array_count_values( $this->calls );
		$this->assertSame( 2, $counts['delete_option:odavent_events_settings'] );
		$this->assertSame( 2, $counts['delete_option:odavent_events_lastgood_7_fr'] );
		$this->assertSame( 2, $counts['delete_option:widget_odavent_events_venue'] );
		$this->assertSame( 1, $counts['delete_site_option:_site_transient_odavent_events_update'] );
		$this->assertSame( 1, $counts['delete_site_option:_site_transient_timeout_odavent_events_update'] );
		$this->assertArrayNotHasKey( 'delete_site_option:site_admins', $counts );
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
function delete_transient( $name ) { global $log; $log[] = "delete_transient:" . $name; }
function delete_site_transient( $name ) { global $log; $log[] = "delete_site_transient:" . $name; }
class FakeDb {
	public $options = "wp_options";
	public function esc_like( $t ) { return $t; }
	public function prepare( $sql, $arg ) { return $arg; }
	public function get_col( $q ) { global $log; $log[] = "select:" . $q; return "odavent_events_lastgood_%" === $q ? array( "odavent_events_lastgood_1_en" ) : array(); }
}
$wpdb = new FakeDb();
require ' . var_export( dirname( __DIR__, 2 ) . '/uninstall.php', true ) . ';
echo implode( "|", $log );'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		$this->assertSame(
			'delete_option:odavent_events_settings|delete_transient:odavent_events_list|delete_transient:odavent_events_backoff|delete_transient:odavent_events_invalid_key_notice|delete_transient:odavent_events_contract_notice|delete_transient:odavent_events_conn_disabled|delete_site_transient:odavent_events_update|select:odavent_events_lastgood_%|delete_option:odavent_events_lastgood_1_en|select:_transient_odavent_events_%|select:_transient_timeout_odavent_events_%|select:_site_transient_odavent_events_%|select:_site_transient_timeout_odavent_events_%|select:widget_odavent_events_%',
			trim( (string) $output )
		);
	}
}

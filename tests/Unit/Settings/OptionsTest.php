<?php
namespace Odavent\Events\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Odavent\Events\Plugin;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\Support\OptionsWithConstant;
use Odavent\Events\Tests\TestCase;

class OptionsTest extends TestCase {

	private const KEY = 'odv_wp_abcdef1234567890WXYZ';

	protected function setUp(): void {
		parent::setUp();
		// Load the plugin's own autoloader (once per process) so src/ classes resolve.
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	private function stored( array $settings ): void {
		Functions\when( 'get_option' )->justReturn( $settings );
	}

	private function defaults(): array {
		return array(
			'base_url'         => 'https://odavent.com',
			'site_key'         => '',
			'cache_ttl'        => 900,
			'default_event_id' => 0,
			'load_css'         => true,
		);
	}

	public function test_nested_namespace_class_autoloads(): void {
		// Fresh PHP process so only the plugin's hand-written autoloader can find a nested-namespace class.
		$script = sys_get_temp_dir() . '/odavent-events-nested-autoload-' . getmypid() . '.php';
		file_put_contents(
			$script,
			'<?php
define( "ABSPATH", __DIR__ . "/" );
$GLOBALS["wp_version"] = "6.5";
function add_action() { return true; }
require ' . var_export( dirname( __DIR__, 3 ) . '/odavent-events.php', true ) . ';
echo class_exists( "Odavent\\\\Events\\\\Settings\\\\Options" ) ? "nested-ok" : "nested-missing";'
		);

		$output = shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1' );
		unlink( $script );

		$this->assertSame( 'nested-ok', trim( (string) $output ) );
	}

	public function test_defaults_when_nothing_is_stored(): void {
		Functions\expect( 'get_option' )->with( 'odavent_events_settings', array() )->andReturn( array() );

		$options = new Options();

		$this->assertSame( $this->defaults(), $options->get() );
		$this->assertSame( 'https://odavent.com', $options->base_url() );
		$this->assertSame( 900, $options->cache_ttl() );
		$this->assertSame( 0, $options->default_event_id() );
		$this->assertTrue( $options->load_css() );
		$this->assertFalse( $options->has_key() );
		$this->assertSame( '', $options->site_key() );
		$this->assertSame( '', $options->masked_key() );
	}

	public function test_non_array_stored_value_falls_back_to_defaults(): void {
		$this->stored( array() );
		Functions\when( 'get_option' )->justReturn( 'garbage' );

		$this->assertSame( $this->defaults(), ( new Options() )->get() );
	}

	public function test_stored_values_merge_over_defaults_and_unknown_keys_are_dropped(): void {
		$this->stored(
			array(
				'base_url'  => 'https://events.example.org/',
				'cache_ttl' => 120,
				'load_css'  => false,
				'surprise'  => 'x',
			)
		);

		$options = new Options();
		$all     = $options->get();

		$this->assertArrayNotHasKey( 'surprise', $all );
		$this->assertSame( 'https://events.example.org', $options->base_url() );
		$this->assertSame( 120, $options->cache_ttl() );
		$this->assertFalse( $options->load_css() );
		$this->assertSame( 0, $options->default_event_id() );
	}

	public function test_stored_key_is_used_when_no_constant(): void {
		$this->stored( array( 'site_key' => self::KEY ) );

		$options = new OptionsWithConstant();

		$this->assertTrue( $options->has_key() );
		$this->assertSame( self::KEY, $options->site_key() );
	}

	public function test_constant_override_wins_over_stored_key(): void {
		$this->stored( array( 'site_key' => self::KEY ) );

		$options           = new OptionsWithConstant();
		$options->constant = 'odv_wp_fromconstant9999';

		$this->assertTrue( $options->has_key() );
		$this->assertSame( 'odv_wp_fromconstant9999', $options->site_key() );
		$this->assertSame( 'odv_wp_••••9999', $options->masked_key() );
	}

	public function test_constant_alone_makes_has_key_true(): void {
		$this->stored( array() );

		$options           = new OptionsWithConstant();
		$options->constant = 'odv_wp_onlyconstant0001';

		$this->assertTrue( $options->has_key() );
	}

	public function test_masked_key_shows_prefix_and_last_four_only(): void {
		$this->stored( array( 'site_key' => self::KEY ) );

		$masked = ( new Options() )->masked_key();

		$this->assertSame( 'odv_wp_••••WXYZ', $masked );
		$this->assertStringNotContainsString( 'abcdef1234567890', $masked );
		$this->assertStringNotContainsString( self::KEY, $masked );
	}

	public function test_save_stores_with_autoload_disabled(): void {
		Functions\expect( 'update_option' )
			->once()
			->with( 'odavent_events_settings', array( 'cache_ttl' => 300 ), false );

		( new Options() )->save( array( 'cache_ttl' => 300 ) );
		$this->addToAssertionCount( 1 );
	}

	/* ---- sanitize ---- */

	private function sanitize( array $input, array $current = array() ): array {
		return ( new Options() )->sanitize( $input, array_merge( $this->defaults(), $current ) );
	}

	public function test_sanitize_accepts_https_base_url_and_strips_trailing_slash(): void {
		$out = $this->sanitize( array( 'base_url' => ' https://Events.Example.org/ ' ) );

		$this->assertSame( 'https://events.example.org', $out['base_url'] );
	}

	public function test_sanitize_rejects_http_base_url_and_keeps_current(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\expect( 'add_settings_error' )
			->once()
			->with( 'odavent_events_settings', 'odavent_events_base_url', \Mockery::type( 'string' ), 'error' );

		$out = $this->sanitize( array( 'base_url' => 'http://example.com' ), array( 'base_url' => 'https://keep.example.org' ) );

		$this->assertSame( 'https://keep.example.org', $out['base_url'] );
	}

	public function test_sanitize_accepts_http_localhost_only_in_local_environment(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		$out = $this->sanitize( array( 'base_url' => 'http://localhost:8000' ) );
		$this->assertSame( 'http://localhost:8000', $out['base_url'] );

		$out = $this->sanitize( array( 'base_url' => 'http://127.0.0.1' ) );
		$this->assertSame( 'http://127.0.0.1', $out['base_url'] );
	}

	public function test_sanitize_rejects_http_localhost_outside_local_environment(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'add_settings_error' )->justReturn( null );

		$out = $this->sanitize( array( 'base_url' => 'http://localhost' ) );

		$this->assertSame( 'https://odavent.com', $out['base_url'] );
	}

	public function test_sanitize_rejects_http_for_other_hosts_even_in_local_environment(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		Functions\when( 'add_settings_error' )->justReturn( null );

		$out = $this->sanitize( array( 'base_url' => 'http://example.com' ) );

		$this->assertSame( 'https://odavent.com', $out['base_url'] );
	}

	public function test_sanitize_rejects_urls_with_credentials_or_junk(): void {
		Functions\when( 'add_settings_error' )->justReturn( null );

		foreach ( array( 'https://user:pw@example.com', 'ftp://example.com', 'example.com', 'javascript:alert(1)', '', 'https://exa mple.com' ) as $bad ) {
			$out = $this->sanitize( array( 'base_url' => $bad ) );
			$this->assertSame( 'https://odavent.com', $out['base_url'], "Should reject {$bad}" );
		}
	}

	public function test_sanitize_clamps_ttl(): void {
		$this->assertSame( 60, $this->sanitize( array( 'cache_ttl' => 5 ) )['cache_ttl'] );
		$this->assertSame( 86400, $this->sanitize( array( 'cache_ttl' => 999999 ) )['cache_ttl'] );
		$this->assertSame( 300, $this->sanitize( array( 'cache_ttl' => '300' ) )['cache_ttl'] );
	}

	public function test_sanitize_keeps_current_ttl_when_absent_or_not_numeric(): void {
		$this->assertSame( 450, $this->sanitize( array(), array( 'cache_ttl' => 450 ) )['cache_ttl'] );
		$this->assertSame( 450, $this->sanitize( array( 'cache_ttl' => 'abc' ), array( 'cache_ttl' => 450 ) )['cache_ttl'] );
	}

	public function test_sanitize_accepts_and_trims_a_valid_key(): void {
		$out = $this->sanitize( array( 'site_key' => '  ' . self::KEY . "\n" ) );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	public function test_sanitize_rejects_key_without_prefix_and_keeps_stored(): void {
		Functions\expect( 'add_settings_error' )
			->once()
			->with( 'odavent_events_settings', 'odavent_events_site_key', \Mockery::type( 'string' ), 'error' );

		$out = $this->sanitize( array( 'site_key' => 'sk_live_nope' ), array( 'site_key' => self::KEY ) );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	public function test_sanitize_rejects_key_with_inner_whitespace(): void {
		Functions\when( 'add_settings_error' )->justReturn( null );

		$out = $this->sanitize( array( 'site_key' => 'odv_wp_abc def' ), array( 'site_key' => self::KEY ) );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	public function test_settings_error_never_contains_the_submitted_key(): void {
		$messages = array();
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message ) use ( &$messages ) {
				$messages[] = $message;
			}
		);

		$this->sanitize( array( 'site_key' => 'secret_not_prefixed_123' ) );

		$this->assertNotEmpty( $messages );
		foreach ( $messages as $message ) {
			$this->assertStringNotContainsString( 'secret_not_prefixed_123', $message );
		}
	}

	public function test_sanitize_keeps_stored_key_when_submitted_key_is_empty(): void {
		$out = $this->sanitize( array( 'site_key' => '   ' ), array( 'site_key' => self::KEY ) );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	/**
	 * Separate process: once any test mocks add_settings_error the function stays defined for the process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_sanitize_rejection_is_safe_without_add_settings_error(): void {
		// add_settings_error is not defined in this test: the guard must prevent a fatal.
		$out = $this->sanitize( array( 'site_key' => 'nope' ), array( 'site_key' => self::KEY ) );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	public function test_sanitize_load_css_checkbox_semantics(): void {
		$this->assertTrue( $this->sanitize( array( 'load_css' => '1' ) )['load_css'] );
		$this->assertTrue( $this->sanitize( array( 'load_css' => 'on' ) )['load_css'] );
		$this->assertFalse( $this->sanitize( array() )['load_css'] );
		$this->assertFalse( $this->sanitize( array( 'load_css' => '0' ) )['load_css'] );
	}

	public function test_sanitize_default_event_id_is_a_non_negative_int(): void {
		$this->assertSame( 12, $this->sanitize( array( 'default_event_id' => '12' ) )['default_event_id'] );
		$this->assertSame( 0, $this->sanitize( array( 'default_event_id' => '-7' ) )['default_event_id'] );
		$this->assertSame( 3, $this->sanitize( array( 'default_event_id' => 'abc' ), array( 'default_event_id' => 3 ) )['default_event_id'] );
		$this->assertSame( 3, $this->sanitize( array(), array( 'default_event_id' => 3 ) )['default_event_id'] );
	}

	public function test_sanitize_returns_only_known_keys(): void {
		$out = $this->sanitize( array( 'evil' => 'x' ) );

		$this->assertSame( array_keys( $this->defaults() ), array_keys( $out ) );
	}

	/* ---- ensure_exists ---- */

	public function test_ensure_exists_creates_the_option_without_autoload(): void {
		Functions\expect( 'add_option' )
			->once()
			->with( 'odavent_events_settings', array(), '', false );
		Functions\expect( 'update_option' )->never();

		( new Options() )->ensure_exists();
		$this->addToAssertionCount( 1 );
	}

	public function test_ensure_exists_never_overwrites_an_existing_option(): void {
		// add_option() is WordPress's create-if-missing; the only write ensure_exists() may use.
		$this->stored( array( 'site_key' => self::KEY ) );
		Functions\expect( 'add_option' )->once();
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'delete_option' )->never();

		( new Options() )->ensure_exists();
		$this->addToAssertionCount( 1 );
	}

	/* ---- key validation ---- */

	public function test_sanitize_rejects_probe_keys_and_keeps_current(): void {
		$errors = 0;
		Functions\when( 'add_settings_error' )->alias(
			function () use ( &$errors ) {
				++$errors;
			}
		);

		$probes = array(
			'markup'    => 'odv_wp_"><svg>',
			'nul'       => "odv_wp_abcdefgh\0ijklmnop",
			'too long'  => 'odv_wp_' . str_repeat( 'a', 4993 ),
			'quote'     => "odv_wp_abcdefgh'ijkl",
			'unicode'   => 'odv_wp_abcdefgh••••',
			'too short' => 'odv_wp_abcdefg',
			'too long2' => 'odv_wp_' . str_repeat( 'a', 121 ),
		);

		foreach ( $probes as $label => $probe ) {
			$out = $this->sanitize( array( 'site_key' => $probe ), array( 'site_key' => self::KEY ) );
			$this->assertSame( self::KEY, $out['site_key'], "Should reject {$label}" );
		}

		$this->assertSame( count( $probes ), $errors );
	}

	public function test_sanitize_accepts_a_48_char_key_and_the_length_boundaries(): void {
		$long = 'odv_wp_' . str_repeat( 'aB3-_', 8 ) . 'abcdefgh'; // 7 + 48 = 55 characters.
		$this->assertSame( $long, $this->sanitize( array( 'site_key' => $long ) )['site_key'] );

		$min = 'odv_wp_abcdefgh';
		$this->assertSame( $min, $this->sanitize( array( 'site_key' => $min ) )['site_key'] );

		$max = 'odv_wp_' . str_repeat( 'z', 120 );
		$this->assertSame( $max, $this->sanitize( array( 'site_key' => $max ) )['site_key'] );
	}

	public function test_is_valid_key_rejects_trailing_newline(): void {
		$this->assertTrue( Options::is_valid_key( 'odv_wp_abcdefgh1234' ) );
		$this->assertFalse( Options::is_valid_key( "odv_wp_abcdefgh1234\n" ) );
	}

	public function test_malformed_constant_counts_as_no_key(): void {
		$this->stored( array( 'site_key' => self::KEY ) );

		foreach ( array( 'odv_wp_"><svg>', "odv_wp_abcdefgh1234\n", 'nope' ) as $bad ) {
			$options           = new OptionsWithConstant();
			$options->constant = $bad;

			$this->assertFalse( $options->has_key(), 'Constant: ' . $bad );
			$this->assertSame( '', $options->site_key() );
			$this->assertSame( '', $options->masked_key() );
		}
	}

	public function test_malformed_stored_key_counts_as_no_key(): void {
		foreach ( array( 'odv_wp_"><svg>', 'garbage', array( 'x' ), 123 ) as $bad ) {
			$this->stored( array( 'site_key' => $bad ) );
			$options = new OptionsWithConstant();

			$this->assertFalse( $options->has_key() );
			$this->assertSame( '', $options->site_key() );
		}
	}

	/* ---- masking ---- */

	public function test_mask_shows_the_tail_only_for_keys_of_at_least_12_characters(): void {
		$this->assertSame( '', Options::mask( '' ) );
		$this->assertSame( 'odv_wp_••••', Options::mask( 'odv_wp_ab' ) );
		$this->assertSame( 'odv_wp_••••', Options::mask( 'odv_wp_abcd' ) ); // 11 characters.
	}

	public function test_mask_boundary_at_exactly_12_characters(): void {
		$this->assertSame( 12, strlen( 'odv_wp_abcde' ) );
		$this->assertSame( 'odv_wp_••••bcde', Options::mask( 'odv_wp_abcde' ) );
	}

	/* ---- base_url read-time validation ---- */

	public function test_base_url_falls_back_to_default_for_unusable_stored_values(): void {
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );

		foreach ( array( array( 'x' ), '', 'Array', 'http://example.com', 'not a url', 123 ) as $bad ) {
			$this->stored( array( 'base_url' => $bad ) );
			$this->assertSame( 'https://odavent.com', ( new Options() )->base_url() );
		}
	}

	public function test_stale_http_localhost_becomes_invalid_outside_local_environment(): void {
		$this->stored( array( 'base_url' => 'http://localhost:8000' ) );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'local' );
		$this->assertSame( 'http://localhost:8000', ( new Options() )->base_url() );

		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$this->assertSame( 'https://odavent.com', ( new Options() )->base_url() );
	}

	/* ---- base URL normalisation ---- */

	public function test_normalization_cases(): void {
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		$default = 'https://odavent.com';

		$accepted = array(
			'HTTPS://Odavent.com/'     => 'https://odavent.com',
			'https://odavent.com/app/' => 'https://odavent.com/app',
			'https://odavent.com:8443' => 'https://odavent.com:8443',
		);
		foreach ( $accepted as $in => $expected ) {
			$this->assertSame( $expected, $this->sanitize( array( 'base_url' => $in ), array( 'base_url' => $default ) )['base_url'], $in );
		}

		$rejected = array(
			'https://odavent.com/path?x',
			'https://x#',
			'https://x?',
			'https://a@b',
			'https://odavent..com',
			'https://odavent.com/a/../b',
			'https://odavent.com/..',
			'https://odavent.com/a%00b',
			'https://odavent.com:0',
		);
		foreach ( $rejected as $in ) {
			$this->assertSame( 'https://keep.example.org', $this->sanitize( array( 'base_url' => $in ), array( 'base_url' => 'https://keep.example.org' ) )['base_url'], $in );
		}
	}

	/* ---- connection disabled flag ---- */

	public function test_connection_disabled_reads_the_transient(): void {
		$value = '1';
		Functions\expect( 'get_transient' )
			->with( 'odavent_events_conn_disabled' )
			->andReturnUsing(
				static function () use ( &$value ) {
					return $value;
				}
			);

		$this->assertTrue( ( new Options() )->is_connection_disabled() );

		$value = false;
		$this->assertFalse( ( new Options() )->is_connection_disabled() );
	}

	public function test_set_connection_disabled_stores_for_one_hour(): void {
		Functions\expect( 'set_transient' )->once()->with( 'odavent_events_conn_disabled', 1, 3600 );

		( new Options() )->set_connection_disabled( true );
		$this->addToAssertionCount( 1 );
	}

	public function test_clearing_connection_disabled_deletes_the_transient(): void {
		Functions\expect( 'delete_transient' )->once()->with( 'odavent_events_conn_disabled' );

		( new Options() )->set_connection_disabled( false );
		$this->addToAssertionCount( 1 );
	}

	public function test_plugin_exposes_a_lazy_options_singleton(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( Options::class, $plugin->options() );
		$this->assertSame( $plugin->options(), $plugin->options() );
	}

	public function test_is_key_from_constant_reflects_the_constant_only(): void {
		$this->stored( array( 'site_key' => self::KEY ) );

		$this->assertFalse( ( new OptionsWithConstant() )->is_key_from_constant() );

		$options           = new OptionsWithConstant();
		$options->constant = 'odv_wp_CONSTANTKEY0987654321';
		$this->assertTrue( $options->is_key_from_constant() );
	}

	public function test_sanitize_can_run_silently_and_reports_again_afterwards(): void {
		$this->stored( array() );
		$calls = 0;
		Functions\when( 'add_settings_error' )->alias(
			function () use ( &$calls ) {
				++$calls;
			}
		);
		$options = new Options();

		$options->sanitize( array( 'base_url' => 'ftp://nope' ), array(), false );
		$this->assertSame( 0, $calls );

		$options->sanitize( array( 'base_url' => 'ftp://nope' ), array() );
		$this->assertSame( 1, $calls, 'silence applies to one pass only' );
	}
}

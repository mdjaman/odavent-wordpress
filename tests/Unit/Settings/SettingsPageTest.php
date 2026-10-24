<?php
namespace Odavent\Events\Tests\Unit\Settings;

use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Settings\Options;
use Odavent\Events\Settings\Settings_Page;
use Odavent\Events\Tests\Support\OptionsWithConstant;
use Odavent\Events\Tests\TestCase;

class SettingsPageTest extends TestCase {

	private const KEY      = 'odv_wp_abcdef1234567890WXYZ';
	private const CONSTANT = 'odv_wp_CONSTANTKEY0987654321';

	/** @var array name => value */
	private $options = array();

	/** @var array name => value */
	private $transients = array();

	/** @var array Every add_option call: [name, value, autoload]. */
	private $added = array();

	/** @var array add_action / add_filter calls: [kind, hook, callback, priority]. */
	private $hooks = array();

	/** @var array add_settings_error calls: [setting, code, message, type]. */
	private $errors = array();

	/** @var array add_settings_field calls: [id, title, callback, page, section]. */
	private $fields = array();

	/** @var array register_setting calls: [group, name, args]. */
	private $registered = array();

	/** @var array add_settings_section calls: [id, title, callback]. */
	private $sections = array();

	/** @var array wp_enqueue_style / wp_enqueue_script calls: [kind, handle, src, deps]. */
	private $enqueued = array();

	/** @var bool */
	private $can_manage = true;

	/** @var Client|Mockery\MockInterface */
	private $client;

	/** @var Event_Repository|Mockery\MockInterface */
	private $repo;

	/** @var string */
	private $permalinks = '/%postname%/';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		$this->options = array(
			Options::OPTION => array(
				'site_key'  => self::KEY,
				'cache_ttl' => 900,
			),
		);

		Functions\when( 'add_action' )->alias(
			function ( $hook, $callback, $priority = 10 ) {
				$this->hooks[] = array( 'action', $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'add_filter' )->alias(
			function ( $hook, $callback, $priority = 10 ) {
				$this->hooks[] = array( 'filter', $hook, $callback, $priority );
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				if ( 'permalink_structure' === $name ) {
					return $this->permalinks;
				}
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
				$this->added[] = array( $name, $value, $autoload );
				return true;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return array_key_exists( $name, $this->transients ) ? $this->transients[ $name ] : false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can_manage;
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 7 );
		Functions\when( 'admin_url' )->alias(
			function ( $path = '' ) {
				return 'https://wp.test/wp-admin/' . $path;
			}
		);
		Functions\when( 'plugin_basename' )->justReturn( 'odavent-events/odavent-events.php' );
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_odavent-events' );
		Functions\when( 'add_settings_section' )->alias(
			function ( $id, $title, $callback ) {
				$this->sections[] = array( $id, $title, $callback );
			}
		);
		Functions\when( 'add_settings_field' )->alias(
			function ( $id, $title, $callback, $page, $section = 'default', $args = array() ) {
				$this->fields[] = array( $id, $title, $callback, $page, $section );
			}
		);
		Functions\when( 'register_setting' )->alias(
			function ( $group, $name, $args = array() ) {
				$this->registered[] = array( $group, $name, $args );
			}
		);
		Functions\when( 'add_settings_error' )->alias(
			function ( $setting, $code, $message, $type = 'error' ) {
				$this->errors[] = array( $setting, $code, $message, $type );
			}
		);
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'settings_errors' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->alias(
			function ( $action ) {
				echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '">';
			}
		);
		// Like core: each section's title and callback, then its fields.
		Functions\when( 'do_settings_sections' )->alias(
			function () {
				foreach ( $this->sections as $section ) {
					echo '<h2>' . $section[1] . '</h2>';
					if ( is_callable( $section[2] ) ) {
						call_user_func( $section[2], array( 'id' => $section[0] ) );
					}
					foreach ( $this->fields as $field ) {
						if ( $field[4] === $section[0] ) {
							call_user_func( $field[2] );
						}
					}
				}
			}
		);
		Functions\when( 'number_format_i18n' )->alias(
			function ( $number, $decimals = 0 ) {
				return number_format( (float) $number, (int) $decimals );
			}
		);
		Functions\when( 'plugins_url' )->alias(
			function ( $path = '' ) {
				return 'https://wp.test/wp-content/plugins/odavent-events/' . $path;
			}
		);
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle, $src = '', $deps = array() ) {
				$this->enqueued[] = array( 'style', $handle, $src, $deps );
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array() ) {
				$this->enqueued[] = array( 'script', $handle, $src, $deps );
			}
		);

		$this->client = Mockery::mock( Client::class );
		$this->repo   = Mockery::mock( Event_Repository::class );
		$this->repo->shouldReceive( 'is_backing_off' )->andReturn( false )->byDefault();
	}

	// ---- helpers -----------------------------------------------------------------

	/**
	 * A page whose redirect records instead of exiting. Built at runtime: the plugin autoloader
	 * only exists once setUp() has required the main file.
	 */
	private function page( ?Options $options = null ): Settings_Page {
		return new class( $options ?? new Options(), $this->client, $this->repo ) extends Settings_Page {

			/** @var int */
			public $redirects = 0;

			protected function redirect_back(): void {
				++$this->redirects;
			}
		};
	}

	private function events_result(): Result {
		return Result::ok( $this->fixture_list() );
	}

	private function fixture_list(): array {
		$data = json_decode( (string) file_get_contents( __DIR__ . '/../../fixtures/events-list.json' ), true );
		$this->assertIsArray( $data );

		return $data;
	}

	private function render( Settings_Page $page ): string {
		$page->register();
		$this->run_hook( 'admin_init' );

		ob_start();
		$page->render_page();

		return (string) ob_get_clean();
	}

	private function run_hook( string $hook ): void {
		foreach ( $this->hooks as $h ) {
			if ( $h[1] === $hook ) {
				call_user_func( $h[2] );
			}
		}
	}

	private function hook( string $hook ): ?array {
		foreach ( $this->hooks as $h ) {
			if ( $h[1] === $hook ) {
				return $h;
			}
		}

		return null;
	}

	private function flash(): ?array {
		$flash = $this->transients['odavent_events_notice_7'] ?? null;

		return is_array( $flash ) ? $flash : null;
	}

	private function die_throws(): void {
		Functions\when( 'wp_die' )->alias(
			function ( $message = '', $title = '', $args = array() ) {
				throw new \RuntimeException( 'wp_die:' . ( is_array( $args ) ? ( $args['response'] ?? '' ) : $args ) );
			}
		);
	}

	// ---- wiring --------------------------------------------------------------------

	public function test_register_hooks_the_menu_handlers_and_action_link(): void {
		$this->page()->register();

		$this->assertNotNull( $this->hook( 'admin_menu' ) );
		$this->assertNotNull( $this->hook( 'admin_init' ) );
		$this->assertNotNull( $this->hook( 'admin_post_odavent_events_test_connection' ) );
		$this->assertNotNull( $this->hook( 'admin_post_odavent_events_clear_cache' ) );
		$this->assertNotNull( $this->hook( 'plugin_action_links_odavent-events/odavent-events.php' ) );
	}

	public function test_menu_is_under_settings_for_manage_options(): void {
		$captured = array();
		Functions\when( 'add_options_page' )->alias(
			function () use ( &$captured ) {
				$captured = func_get_args();
				return 'x';
			}
		);

		$this->page()->add_menu();

		// "Settings > Odavent Events", as the docs say; the slug stays odavent-events.
		$this->assertSame( 'Odavent Events', $captured[0] );
		$this->assertSame( 'Odavent Events', $captured[1] );
		$this->assertSame( 'manage_options', $captured[2] );
		$this->assertSame( 'odavent-events', $captured[3] );
	}

	public function test_ensure_exists_is_hooked_on_admin_init_before_the_settings_api(): void {
		$this->options = array();
		$this->page()->register();

		$ensure = null;
		foreach ( $this->hooks as $h ) {
			if ( 'admin_init' === $h[1] && 5 === $h[3] ) {
				$ensure = $h[2];
			}
		}
		$this->assertNotNull( $ensure, 'a priority 5 admin_init callback exists' );

		call_user_func( $ensure );

		$this->assertSame( array( array( Options::OPTION, array(), false ) ), $this->added );

		// And it runs before register_setting's priority.
		foreach ( $this->hooks as $h ) {
			if ( 'admin_init' === $h[1] && $h[2] !== $ensure ) {
				$this->assertGreaterThan( 5, $h[3] );
			}
		}
	}

	public function test_register_setting_uses_the_option_and_the_sanitize_wrapper(): void {
		$page = $this->page();
		$page->register();
		$this->run_hook( 'admin_init' );

		$this->assertCount( 1, $this->registered );
		list( $group, $name, $args ) = $this->registered[0];
		$this->assertSame( 'odavent_events', $group );
		$this->assertSame( Options::OPTION, $name );
		$this->assertSame( 'array', $args['type'] );
		$this->assertSame( array( $page, 'sanitize' ), $args['sanitize_callback'] );
	}

	public function test_register_setting_declares_no_default_that_would_autoload_the_first_save(): void {
		// Core's update_option(): when the stored value === apply_filters( "default_option_{$option}" ), the
		// option counts as missing and is re-added by add_option() with NO autoload argument (autoload `yes`,
		// or `auto` on 6.6+). register_setting() 'default' feeds that filter, and ensure_exists() stores
		// array(), so a default equal to it put the site key back into autoload on the first save (found on
		// a real WordPress 7.1 by bin/smoke.sh check 9).
		$page = $this->page();
		$page->register();
		$this->run_hook( 'admin_init' );

		$args = $this->registered[0][2];
		$this->assertFalse(
			array_key_exists( 'default', $args ) && array() === $args['default'],
			'The registered default must not equal the placeholder ensure_exists() stores.'
		);
	}

	public function test_render_page_does_not_print_settings_errors_itself(): void {
		// Settings > Odavent is under options-general.php, where wp-admin/options-head.php already calls
		// settings_errors(); a second call printed every error notice twice (bin/smoke.sh check 9).
		$calls = 0;
		Functions\when( 'settings_errors' )->alias(
			function () use ( &$calls ) {
				++$calls;
			}
		);
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$this->render( $this->page() );
		$this->assertSame( 0, $calls );
	}

	public function test_plugin_action_links_adds_one_settings_link(): void {
		$links = $this->page()->action_links( array( '<a href="x">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'options-general.php?page=odavent-events', $links[0] );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertSame( '<a href="x">Deactivate</a>', $links[1] );
	}

	// ---- sanitize wrapper --------------------------------------------------------------

	public function test_sanitize_wraps_options_sanitize_with_the_stored_settings(): void {
		$out = $this->page()->sanitize(
			array(
				'base_url'  => 'https://events.example.com/',
				'cache_ttl' => '1200',
				'load_css'  => '1',
			)
		);

		$this->assertSame( 'https://events.example.com', $out['base_url'] );
		$this->assertSame( 1200, $out['cache_ttl'] );
		$this->assertSame( self::KEY, $out['site_key'], 'an omitted key keeps the stored one' );
		$this->assertSame( array(), $this->errors );
	}

	public function test_sanitize_called_twice_adds_each_error_once(): void {
		$page  = $this->page();
		$input = array(
			'base_url' => 'ftp://nope',
			'site_key' => 'not a key',
		);

		$page->sanitize( $input );
		$page->sanitize( $input );

		$codes = array_column( $this->errors, 1 );
		sort( $codes );
		$this->assertSame( array( 'odavent_events_base_url', 'odavent_events_site_key' ), $codes );
	}

	public function test_a_hand_made_post_cannot_store_a_key_while_the_constant_owns_it(): void {
		$options           = new OptionsWithConstant();
		$options->constant = self::CONSTANT;
		$other             = 'odv_wp_HANDCRAFTED123456789';

		$out = $this->page( $options )->sanitize( array( 'site_key' => $other, 'cache_ttl' => '1200' ) );

		$this->assertSame( self::KEY, $out['site_key'], 'the stored key is untouched' );
		$this->assertSame( 1200, $out['cache_ttl'], 'the other fields still save' );
		$this->assertStringNotContainsString( $other, json_encode( $out ) );
	}

	public function test_the_key_is_still_saved_when_no_constant_is_defined(): void {
		$other = 'odv_wp_NEWKEY1234567890';

		$out = $this->page()->sanitize( array( 'site_key' => $other ) );

		$this->assertSame( $other, $out['site_key'] );
	}

	/**
	 * Everything a connection leaves behind that must not outlive it. A method, not a constant: the plugin's
	 * classes are only autoloadable once setUp() has loaded the plugin.
	 *
	 * @return string[]
	 */
	private function connection_state(): array {
		return array(
			Event_Repository::LIST_TRANSIENT,
			Event_Repository::INVALID_KEY_NOTICE,
			Event_Repository::CONTRACT_NOTICE,
			Event_Repository::BACKOFF,
			Options::CONN_DISABLED_FLAG,
		);
	}

	private function seed_connection_state(): void {
		foreach ( $this->connection_state() as $name ) {
			$this->transients[ $name ] = array( 'old connection' );
		}
	}

	public function test_changing_the_key_or_the_address_drops_the_old_connection_state(): void {
		foreach ( array(
			array( 'site_key' => 'odv_wp_NEWKEY1234567890' ),
			array( 'base_url' => 'https://events.example.com' ),
		) as $input ) {
			$this->seed_connection_state();

			$this->page()->sanitize( $input );

			foreach ( $this->connection_state() as $name ) {
				$this->assertArrayNotHasKey( $name, $this->transients, $name . ' after ' . json_encode( $input ) );
			}
		}
	}

	public function test_saving_unchanged_connection_settings_keeps_the_connection_state(): void {
		$this->seed_connection_state();

		$this->page()->sanitize( array( 'site_key' => self::KEY, 'base_url' => 'https://odavent.com', 'cache_ttl' => '1200' ) );
		$this->page()->sanitize( array( 'site_key' => '', 'base_url' => 'ftp://rejected' ) );

		foreach ( $this->connection_state() as $name ) {
			$this->assertSame( array( 'old connection' ), $this->transients[ $name ], $name );
		}
	}

	public function test_sanitize_tolerates_a_non_array_input(): void {
		$out = $this->page()->sanitize( 'garbage' );

		$this->assertSame( self::KEY, $out['site_key'] );
	}

	// ---- handlers: capability and nonce ----------------------------------------------------

	public function test_test_connection_refuses_without_manage_options(): void {
		$this->die_throws();
		$this->can_manage = false;
		Functions\expect( 'check_admin_referer' )->never();
		$this->client->shouldReceive( 'list_events' )->never();

		$this->expectExceptionMessage( 'wp_die:403' );
		$this->page()->handle_test_connection();
	}

	public function test_clear_cache_refuses_without_manage_options(): void {
		$this->die_throws();
		$this->can_manage = false;
		Functions\expect( 'check_admin_referer' )->never();
		$this->repo->shouldReceive( 'purge_all' )->never();

		$this->expectExceptionMessage( 'wp_die:403' );
		$this->page()->handle_clear_cache();
	}

	public function test_test_connection_checks_its_nonce_before_calling_odavent(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( 'odavent_events_test_connection' )->andReturnUsing(
			function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);
		$this->client->shouldReceive( 'list_events' )->never();

		$this->expectExceptionMessage( 'bad nonce' );
		$this->page()->handle_test_connection();
	}

	public function test_clear_cache_checks_its_nonce_before_purging(): void {
		Functions\expect( 'check_admin_referer' )->once()->with( 'odavent_events_clear_cache' )->andReturnUsing(
			function () {
				throw new \RuntimeException( 'bad nonce' );
			}
		);
		$this->repo->shouldReceive( 'purge_all' )->never();

		$this->expectExceptionMessage( 'bad nonce' );
		$this->page()->handle_clear_cache();
	}

	// ---- handlers: results ----------------------------------------------------------------------

	public function test_successful_test_connection_reports_the_count_and_clears_the_flags(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->client->shouldReceive( 'list_events' )->once()->andReturn( $this->events_result() );
		$this->transients[ Event_Repository::INVALID_KEY_NOTICE ] = 1;
		$this->transients[ Event_Repository::CONTRACT_NOTICE ]    = 1;
		$this->transients[ Options::CONN_DISABLED_FLAG ]          = 1;

		$page = $this->page();
		$page->handle_test_connection();

		$this->assertSame( 'success', $this->flash()['type'] );
		$this->assertSame( 'Connected: 3 events found.', $this->flash()['message'] );
		$this->assertArrayNotHasKey( Event_Repository::INVALID_KEY_NOTICE, $this->transients );
		$this->assertArrayNotHasKey( Event_Repository::CONTRACT_NOTICE, $this->transients );
		$this->assertArrayNotHasKey( Options::CONN_DISABLED_FLAG, $this->transients, 'a working key lifts the disabled flag' );
		$this->assertSame( 1, $page->redirects );
	}

	public function test_test_connection_without_any_key_asks_for_one_and_makes_no_call(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->options[ Options::OPTION ] = array();
		$this->client->shouldReceive( 'list_events' )->never();

		$page = $this->page();
		$page->handle_test_connection();

		$this->assertSame( 'error', $this->flash()['type'] );
		$this->assertSame( 'Enter a site key first.', $this->flash()['message'] );
		$this->assertNotSame( 'The site key was rejected.', $this->flash()['message'] );
		$this->assertSame( 1, $page->redirects );
	}

	public function test_test_connection_with_a_malformed_constant_says_so(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$options           = new OptionsWithConstant();
		$options->constant = 'not-a-key';
		$this->client->shouldReceive( 'list_events' )->never();

		$this->page( $options )->handle_test_connection();

		$this->assertStringContainsString( 'ODAVENT_SITE_KEY', $this->flash()['message'] );
		$this->assertStringContainsString( 'not a valid site key', $this->flash()['message'] );
	}

	public function test_a_malformed_constant_gets_a_hint_on_the_page(): void {
		$options           = new OptionsWithConstant();
		$options->constant = 'not-a-key';
		$this->client->shouldReceive( 'list_events' )->never();

		$html = $this->render( $this->page( $options ) );

		$this->assertStringContainsString( 'ODAVENT_SITE_KEY in wp-config.php is not a valid site key', $html );
		$this->assertStringNotContainsString( 'not-a-key', $html );
		$this->assertStringNotContainsString( 'A key is stored', $html );
	}

	public function test_invalid_key_message(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->client->shouldReceive( 'list_events' )->andReturn( Result::fail( Result::INVALID_KEY, 401, 'x' ) );

		$this->page()->handle_test_connection();

		$this->assertSame( 'error', $this->flash()['type'] );
		$this->assertSame( 'The site key was rejected.', $this->flash()['message'] );
		$this->assertArrayNotHasKey( Options::CONN_DISABLED_FLAG, $this->transients );
	}

	public function test_plan_required_message_sets_the_disabled_flag(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->client->shouldReceive( 'list_events' )->andReturn( Result::fail( Result::PLAN_REQUIRED, 403, 'x' ) );

		$this->page()->handle_test_connection();

		$this->assertSame( 'Your Odavent plan no longer includes the WordPress plugin; published events keep showing.', $this->flash()['message'] );
		$this->assertArrayHasKey( Options::CONN_DISABLED_FLAG, $this->transients );
	}

	public function test_unavailable_message(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->client->shouldReceive( 'list_events' )->andReturn( Result::fail( Result::UNAVAILABLE, 0, 'x' ) );
		$this->transients[ Options::CONN_DISABLED_FLAG ] = 1;

		$this->page()->handle_test_connection();

		$this->assertSame( 'Could not reach Odavent. Check the URL and try again.', $this->flash()['message'] );
		$this->assertArrayHasKey( Options::CONN_DISABLED_FLAG, $this->transients, 'an outage says nothing about the plan' );
	}

	public function test_clear_cache_purges_everything_and_reports_it(): void {
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		$this->repo->shouldReceive( 'purge_all' )->once();

		$page = $this->page();
		$page->handle_clear_cache();

		$this->assertSame( 'Cache cleared.', $this->flash()['message'] );
		$this->assertSame( 'success', $this->flash()['type'] );
		$this->assertSame( 1, $page->redirects );
	}

	// ---- rendering -----------------------------------------------------------------------------------

	public function test_flash_notice_is_shown_once_then_consumed(): void {
		$this->transients['odavent_events_notice_7'] = array(
			'type'    => 'success',
			'message' => 'Cache cleared.',
		);
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$first = $this->render( $this->page() );
		$this->assertStringContainsString( 'Cache cleared.', $first );
		$this->assertStringContainsString( 'notice-success', $first );
		$this->assertArrayNotHasKey( 'odavent_events_notice_7', $this->transients );

		$second = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'Cache cleared.', $second );
	}

	public function test_form_never_contains_the_stored_key(): void {
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringNotContainsString( substr( self::KEY, 7 ), $html );
		$this->assertStringContainsString( 'odv_wp_••••WXYZ', $html, 'the masked key is shown' );
		$this->assertStringContainsString( 'A key is stored', $html );
		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'autocomplete="off"', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="odavent_events_settings\[site_key\]"[^>]*value=/', $html );
	}

	public function test_form_never_contains_the_constant_key_and_the_input_is_disabled(): void {
		$options           = new OptionsWithConstant();
		$options->constant = self::CONSTANT;
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page( $options ) );

		$this->assertStringNotContainsString( self::CONSTANT, $html );
		$this->assertStringNotContainsString( self::KEY, $html );
		$this->assertStringContainsString( 'Defined in wp-config.php', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*name="odavent_events_settings\[site_key\]"[^>]*disabled/', $html );
	}

	public function test_default_event_is_a_select_when_the_list_loads(): void {
		$this->options[ Options::OPTION ]['default_event_id'] = 42;
		$this->client->shouldReceive( 'list_events' )->once()->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '<select id="odavent_events_default_event"', $html );
		$this->assertStringContainsString( 'name="odavent_events_settings[default_event_id]"', $html );
		$this->assertStringContainsString( '<option value="0">', $html );
		$this->assertMatchesRegularExpression( '/<option value="42" selected[^>]*>/', $html );
		$this->assertStringContainsString( 'Meetup Fintech 2025', $html );
	}

	public function test_default_event_falls_back_to_a_number_input_when_the_list_fails(): void {
		$this->options[ Options::OPTION ]['default_event_id'] = 42;
		$this->client->shouldReceive( 'list_events' )->andReturn( Result::fail( Result::UNAVAILABLE, 0, 'x' ) );

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<select id="odavent_events_default_event"', $html, 'the default event is not a select' );
		$this->assertMatchesRegularExpression( '/<input[^>]*type="number"[^>]*name="odavent_events_settings\[default_event_id\]"[^>]*value="42"/', $html );
		$this->assertStringContainsString( 'Could not load your events', $html );
		$this->assertStringContainsString( 'name="odavent_events_settings[cache_ttl]"', $html, 'the page still renders the other fields' );
	}

	public function test_default_event_makes_no_call_without_a_usable_key(): void {
		$this->options[ Options::OPTION ] = array();
		$this->client->shouldReceive( 'list_events' )->never();

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<select id="odavent_events_default_event"', $html, 'the default event is not a select' );
		$this->assertStringNotContainsString( 'A key is stored', $html );
	}

	public function test_the_page_waits_at_most_three_seconds_for_the_events_list(): void {
		$this->client->shouldReceive( 'list_events' )->once()->with( 3 )->andReturn( $this->events_result() );

		$this->render( $this->page() );
		$this->assertSame( 3, Settings_Page::LIST_TIMEOUT );
	}

	public function test_the_page_skips_the_events_list_while_odavent_is_backing_off(): void {
		$this->repo = Mockery::mock( Event_Repository::class );
		$this->repo->shouldReceive( 'is_backing_off' )->andReturn( true );
		$this->options[ Options::OPTION ]['default_event_id'] = 42;
		$this->client->shouldReceive( 'list_events' )->never();

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<select id="odavent_events_default_event"', $html, 'the default event is not a select' );
		$this->assertMatchesRegularExpression( '/<input[^>]*type="number"[^>]*name="odavent_events_settings\[default_event_id\]"[^>]*value="42"/', $html );
		$this->assertStringContainsString( 'name="odavent_events_settings[cache_ttl]"', $html, 'the page still renders the other fields' );
	}

	public function test_the_list_is_fetched_once_per_render(): void {
		$this->client->shouldReceive( 'list_events' )->once()->andReturn( $this->events_result() );

		$page = $this->page();
		$this->render( $page );
	}

	public function test_permalink_notice_only_when_pretty_permalinks_are_off(): void {
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$this->permalinks = '';
		$plain            = $this->render( $this->page() );
		$this->assertStringContainsString( 'Live updates need pretty permalinks; pages refresh every 15 minutes instead.', $plain );

		$this->permalinks = '/%postname%/';
		$pretty           = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'pretty permalinks', $pretty );
	}

	public function test_persistent_notices_for_disabled_connection_invalid_key_and_contract(): void {
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$quiet = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'no longer includes', $quiet );
		$this->assertStringNotContainsString( 'was rejected', $quiet );
		$this->assertStringNotContainsString( 'newer data format', $quiet );

		$this->transients[ Options::CONN_DISABLED_FLAG ]          = 1;
		$this->transients[ Event_Repository::INVALID_KEY_NOTICE ] = 1;
		$this->transients[ Event_Repository::CONTRACT_NOTICE ]    = 1;

		$loud = $this->render( $this->page() );
		$this->assertStringNotContainsString( 'no longer includes', $loud, 'a rejected key disables the key routes too; that is not a plan matter' );
		$this->assertStringContainsString( 'Your Odavent site key was rejected; check it below.', $loud );
		$this->assertStringContainsString( 'Odavent has a newer data format than this plugin supports; update the Odavent plugin.', $loud );
		$this->assertArrayHasKey( Event_Repository::INVALID_KEY_NOTICE, $this->transients, 'persistent, not consumed by viewing' );

		unset( $this->transients[ Event_Repository::INVALID_KEY_NOTICE ] );
		$plan = $this->render( $this->page() );
		$this->assertStringContainsString( 'Your Odavent plan no longer includes the WordPress plugin; published events keep showing.', $plan );
		$this->assertStringNotContainsString( 'was rejected', $plan );
	}

	public function test_the_two_action_forms_post_to_admin_post_with_nonces(): void {
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( 'https://wp.test/wp-admin/admin-post.php', $html );
		$this->assertStringContainsString( 'value="odavent_events_test_connection"', $html );
		$this->assertStringContainsString( 'value="odavent_events_clear_cache"', $html );
		$this->assertStringContainsString( 'nonce-odavent_events_test_connection', $html );
		$this->assertStringContainsString( 'nonce-odavent_events_clear_cache', $html );
	}

	// ---- appearance ----------------------------------------------------------------------------------

	private function appearance_field( string $html, string $field ): string {
		$this->assertSame( 1, preg_match( '/<(?:input|select)[^>]*name="odavent_events_settings\[appearance\]\[' . $field . '\]"[^>]*>/', $html, $m ), $field . ' is rendered once' );

		return $m[0];
	}

	public function test_appearance_section_and_fields_are_registered(): void {
		$this->page()->register_settings();

		$this->assertContains( 'odavent_events_appearance', array_column( $this->sections, 0 ) );
		$ids = array();
		foreach ( $this->fields as $field ) {
			if ( 'odavent_events_appearance' === $field[4] ) {
				$ids[] = $field[0];
			}
		}
		$this->assertSame(
			array(
				'odavent_events_appearance_accent',
				'odavent_events_appearance_contrast',
				'odavent_events_appearance_text',
				'odavent_events_appearance_border',
				'odavent_events_appearance_radius',
				'odavent_events_appearance_spacing',
				'odavent_events_appearance_button_style',
				'odavent_events_appearance_preview',
			),
			$ids
		);
	}

	public function test_appearance_fields_render_with_saved_values(): void {
		$this->options[ Options::OPTION ]['appearance'] = array(
			'accent'       => '#1E3A8A',
			'contrast'     => '#fefefe',
			'text'         => '#111111',
			'border'       => '#cccccc',
			'radius'       => 12,
			'spacing'      => 'roomy',
			'button_style' => 'outline',
		);
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertStringContainsString( '<h2>Appearance</h2>', $html );
		$this->assertStringContainsString( 'value="#1e3a8a"', $this->appearance_field( $html, 'accent' ), 'stored values come back validated' );
		$this->assertStringContainsString( 'value="#fefefe"', $this->appearance_field( $html, 'contrast' ) );
		$this->assertStringContainsString( 'value="#111111"', $this->appearance_field( $html, 'text' ) );
		$this->assertStringContainsString( 'value="#cccccc"', $this->appearance_field( $html, 'border' ) );
		$radius = $this->appearance_field( $html, 'radius' );
		$this->assertStringContainsString( 'type="number"', $radius );
		$this->assertStringContainsString( 'min="0"', $radius );
		$this->assertStringContainsString( 'max="24"', $radius );
		$this->assertStringContainsString( 'value="12"', $radius );
		$this->assertMatchesRegularExpression( '/<option value="roomy" selected>/', $html );
		$this->assertMatchesRegularExpression( '/<option value="outline" selected>/', $html );
		foreach ( array( '', 'compact', 'comfortable' ) as $spacing ) {
			$this->assertStringContainsString( '<option value="' . $spacing . '">', $html );
		}
		$this->assertStringContainsString( '<option value="filled">', $html );

		// A manual text colour: the Automatic box is unchecked and the manual picker is shown.
		$auto = $this->appearance_field( $html, 'contrast_auto' );
		$this->assertStringContainsString( 'type="checkbox"', $auto );
		$this->assertStringNotContainsString( 'checked', $auto );
		$this->assertMatchesRegularExpression( '/id="odavent_events_appearance_contrast_manual"(?![^>]*hidden)/', $html );

		// The preview: the plugin's real classes, the ratio of the saved pair, no warning for a readable one.
		$this->assertStringContainsString( 'class="odv-appearance-preview', $html );
		$this->assertStringContainsString( 'odv-register__button', $html );
		$this->assertStringContainsString( 'odv-tickets__item', $html );
		$this->assertStringContainsString( 'odv-tickets__register', $html );
		$this->assertStringContainsString( 'odv-speakers__item', $html );
		$this->assertStringContainsString( '10.27', $html, 'the ratio of #fefefe on #1e3a8a' );
		$this->assertMatchesRegularExpression( '/class="odv-appearance-preview__warning"[^>]*hidden/', $html );
		$this->assertStringNotContainsString( 'Turn on Default styles to use these settings.', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="odavent_events_settings\[appearance\][^"]*"[^>]*disabled/', $html );
	}

	public function test_unset_values_render_empty_with_automatic_text_and_the_default_choices(): void {
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		foreach ( array( 'accent', 'contrast', 'text', 'border', 'radius' ) as $field ) {
			$this->assertStringContainsString( 'value=""', $this->appearance_field( $html, $field ), $field );
		}
		$this->assertStringContainsString( 'checked', $this->appearance_field( $html, 'contrast_auto' ) );
		$this->assertMatchesRegularExpression( '/id="odavent_events_appearance_contrast_manual"[^>]*hidden/', $html );
		$this->assertMatchesRegularExpression( '/<option value="" selected>/', $html );
		$this->assertMatchesRegularExpression( '/<option value="filled" selected>/', $html );
	}

	public function test_hostile_stored_values_never_reach_the_page(): void {
		$this->options[ Options::OPTION ]['appearance'] = array(
			'accent'       => '"><script>alert(1)</script>',
			'radius'       => '" onfocus="x',
			'spacing'      => '"><b>',
			'button_style' => '"><i>',
		);
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( 'onfocus', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( 'value=""', $this->appearance_field( $html, 'accent' ) );
	}

	public function test_fields_are_disabled_when_default_styles_are_off(): void {
		$this->options[ Options::OPTION ]['load_css']   = false;
		$this->options[ Options::OPTION ]['appearance'] = array( 'accent' => '#1e3a8a' );
		$this->client->shouldReceive( 'list_events' )->andReturn( $this->events_result() );

		$html = $this->render( $this->page() );

		$this->assertSame( 1, substr_count( $html, 'Turn on Default styles to use these settings.' ) );
		foreach ( array( 'accent', 'contrast_auto', 'contrast', 'text', 'border', 'radius', 'spacing', 'button_style' ) as $field ) {
			$this->assertMatchesRegularExpression( '/ disabled[ >]/', $this->appearance_field( $html, $field ), $field . ' is disabled' );
		}
		$this->assertStringContainsString( 'value="#1e3a8a"', $this->appearance_field( $html, 'accent' ), 'the saved value is still shown' );
		$this->assertStringNotContainsString( 'odv-appearance-preview', $html, 'no stylesheet, nothing to preview' );
		$this->assertMatchesRegularExpression( '/name="odavent_events_settings\[cache_ttl\]"(?![^>]*disabled)/', $html, 'the other sections stay usable' );
		$this->assertMatchesRegularExpression( '/name="odavent_events_settings\[load_css\]"(?![^>]*disabled)/', $html );
	}

	public function test_saving_while_default_styles_are_off_keeps_the_stored_appearance(): void {
		// Disabled controls are not submitted: the form sends no appearance at all.
		$stored                                          = array(
			'accent'       => '#1e3a8a',
			'contrast'     => '',
			'text'         => '#111111',
			'border'       => '',
			'radius'       => 4,
			'spacing'      => 'compact',
			'button_style' => 'outline',
		);
		$this->options[ Options::OPTION ]['load_css']   = false;
		$this->options[ Options::OPTION ]['appearance'] = $stored;

		$out = $this->page()->sanitize( array( 'cache_ttl' => '1200' ) );

		$this->assertSame( $stored, $out['appearance'] );
		$this->assertFalse( $out['load_css'] );
		$this->assertSame( array(), $this->errors );
	}

	public function test_automatic_checkbox_stores_an_empty_text_on_accent(): void {
		$out = $this->page()->sanitize(
			array(
				'load_css'   => '1',
				'appearance' => array(
					'accent'        => '#ffeb3b',
					'contrast'      => '#ffffff',
					'contrast_auto' => '1',
				),
			)
		);

		$this->assertSame( '', $out['appearance']['contrast'], 'automatic wins over the hidden manual value' );
		$this->assertArrayNotHasKey( 'contrast_auto', $out['appearance'] );
		$this->assertSame( array(), $this->errors, 'automatic text is always readable' );

		$manual = $this->page()->sanitize(
			array(
				'load_css'   => '1',
				'appearance' => array(
					'accent'   => '#1e3a8a',
					'contrast' => '#FFFFFF',
				),
			)
		);
		$this->assertSame( '#ffffff', $manual['appearance']['contrast'], 'unchecked: the manual colour is kept' );
	}

	public function test_low_contrast_saves_with_a_warning_and_a_good_pair_has_none(): void {
		$page = $this->page();
		$low  = array(
			'load_css'   => '1',
			'appearance' => array(
				'accent'   => '#777777',
				'contrast' => '#ffffff',
			),
		);

		$out = $page->sanitize( $low );
		$page->sanitize( $low );

		$this->assertSame( '#777777', $out['appearance']['accent'], 'saved, not refused' );
		$this->assertSame( '#ffffff', $out['appearance']['contrast'] );
		$this->assertCount( 1, $this->errors, 'once, although WordPress may sanitize twice' );
		list( $setting, $code, $message, $type ) = $this->errors[0];
		$this->assertSame( Options::SETTINGS_ERROR_NAME, $setting );
		$this->assertSame( 'odavent_events_appearance_contrast', $code );
		$this->assertSame( 'warning', $type );
		$this->assertStringContainsString( '4.48:1', $message );
		$this->assertStringContainsString( '4.5:1', $message );

		foreach ( array(
			'good manual pair' => array( 'accent' => '#1e3a8a', 'contrast' => '#ffffff' ),
			'automatic'        => array( 'accent' => '#777777', 'contrast' => '' ),
			'accent unset'     => array( 'accent' => '', 'contrast' => '#eeeeee' ),
		) as $label => $appearance ) {
			$this->errors = array();
			$this->page()->sanitize( array( 'load_css' => '1', 'appearance' => $appearance ) );
			$this->assertSame( array(), $this->errors, $label );
		}

		// Turning the styles off in the same save: nothing on the site uses the pair, so no warning; still saved.
		$this->errors = array();
		$off          = $this->page()->sanitize( array( 'appearance' => array( 'accent' => '#777777', 'contrast' => '#ffffff' ) ) );
		$this->assertSame( array(), $this->errors, 'styles off' );
		$this->assertSame( '#777777', $off['appearance']['accent'] );
	}

	public function test_a_hand_made_post_with_hostile_appearance_values_is_sanitised(): void {
		$out = $this->page()->sanitize(
			array(
				'load_css'   => '1',
				'appearance' => array(
					'accent'        => 'red;}body{display:none',
					'contrast'      => 'url(x)',
					'text'          => '#12',
					'border'        => array( '#ffffff' ),
					'radius'        => '9999',
					'spacing'       => 'roomy;}',
					'button_style'  => '"><script>',
					'contrast_auto' => array( 'x' ),
					'extra'         => 'body{display:none}',
				),
			)
		);

		$this->assertSame(
			array(
				'accent'       => '',
				'contrast'     => '',
				'text'         => '',
				'border'       => '',
				'radius'       => 24,
				'spacing'      => '',
				'button_style' => 'filled',
			),
			$out['appearance']
		);
		$this->assertSame( array(), $this->errors, 'nothing set, nothing to warn about' );

		$negative = $this->page()->sanitize( array( 'appearance' => array( 'radius' => '-5' ) ) );
		$this->assertSame( 0, $negative['appearance']['radius'] );

		$this->options[ Options::OPTION ]['appearance'] = array( 'accent' => '#1e3a8a' );
		$kept = $this->page()->sanitize( array( 'appearance' => 'body{display:none}' ) );
		$this->assertSame( '#1e3a8a', $kept['appearance']['accent'], 'not a list: the stored values stay' );
	}

	public function test_color_picker_assets_load_only_on_this_page(): void {
		$page = $this->page();
		$page->register();
		$hook = $this->hook( 'admin_enqueue_scripts' );
		$this->assertNotNull( $hook );
		$page->add_menu();

		call_user_func( $hook[2], 'edit.php' );
		call_user_func( $hook[2], 'settings_page_other' );
		$this->assertSame( array(), $this->enqueued, 'nothing on other admin screens' );

		call_user_func( $hook[2], 'settings_page_odavent-events' );
		$handles = array();
		foreach ( $this->enqueued as $call ) {
			$handles[ $call[0] . ':' . $call[1] ] = $call;
		}
		$this->assertArrayHasKey( 'style:wp-color-picker', $handles );
		$this->assertArrayHasKey( 'style:odavent-events', $handles, 'the plugin stylesheet, so the preview uses the real styles' );
		$this->assertArrayHasKey( 'script:odavent-events-admin-appearance', $handles );
		$script = $handles['script:odavent-events-admin-appearance'];
		$this->assertStringEndsWith( 'assets/js/admin-appearance.js', $script[2] );
		$this->assertContains( 'wp-color-picker', $script[3] );
		$this->assertArrayHasKey( 'style:odavent-events-admin-appearance', $handles );
		$this->assertStringEndsWith( 'assets/css/admin-appearance.css', $handles['style:odavent-events-admin-appearance'][2] );
	}

	public function test_no_appearance_assets_before_the_menu_exists_or_when_default_styles_are_off(): void {
		$page = $this->page();
		$page->register();
		$hook = $this->hook( 'admin_enqueue_scripts' );

		// add_options_page() returned false (no capability): no page, nothing to match.
		Functions\when( 'add_options_page' )->justReturn( false );
		$page->add_menu();
		call_user_func( $hook[2], '' );
		call_user_func( $hook[2], false );
		$this->assertSame( array(), $this->enqueued );

		$this->options[ Options::OPTION ]['load_css'] = false;
		Functions\when( 'add_options_page' )->justReturn( 'settings_page_odavent-events' );
		$page->add_menu();
		call_user_func( $hook[2], 'settings_page_odavent-events' );
		$this->assertSame( array(), $this->enqueued, 'the fields are disabled: no picker and no preview' );
	}

	public function test_the_admin_script_has_no_translatable_strings_and_reads_its_labels_from_the_page(): void {
		$js = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/js/admin-appearance.js' );

		$this->assertStringNotContainsString( 'wp.i18n', $js );
		$this->assertStringContainsString( 'data-ratio-label', $js );
	}
}

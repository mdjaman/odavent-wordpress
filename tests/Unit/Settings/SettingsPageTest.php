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
		Functions\when( 'add_settings_section' )->justReturn( null );
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
		Functions\when( 'do_settings_sections' )->alias(
			function () {
				foreach ( $this->fields as $field ) {
					call_user_func( $field[2] );
				}
			}
		);

		$this->client = Mockery::mock( Client::class );
		$this->repo   = Mockery::mock( Event_Repository::class );
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

		$this->assertSame( 'Odavent', $captured[0] );
		$this->assertSame( 'Odavent', $captured[1] );
		$this->assertSame( 'manage_options', $captured[2] );
		$this->assertSame( 'odavent-events', $captured[3] );
	}

	public function test_ensure_exists_is_hooked_on_admin_init_before_the_settings_api(): void {
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
		$this->assertSame( array(), $args['default'] );
		$this->assertSame( array( $page, 'sanitize' ), $args['sanitize_callback'] );
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

		$this->assertStringContainsString( '<select', $html );
		$this->assertStringContainsString( 'name="odavent_events_settings[default_event_id]"', $html );
		$this->assertStringContainsString( '<option value="0">', $html );
		$this->assertMatchesRegularExpression( '/<option value="42" selected[^>]*>/', $html );
		$this->assertStringContainsString( 'Meetup Fintech 2025', $html );
	}

	public function test_default_event_falls_back_to_a_number_input_when_the_list_fails(): void {
		$this->options[ Options::OPTION ]['default_event_id'] = 42;
		$this->client->shouldReceive( 'list_events' )->andReturn( Result::fail( Result::UNAVAILABLE, 0, 'x' ) );

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<select', $html );
		$this->assertMatchesRegularExpression( '/<input[^>]*type="number"[^>]*name="odavent_events_settings\[default_event_id\]"[^>]*value="42"/', $html );
		$this->assertStringContainsString( 'Could not load your events', $html );
		$this->assertStringContainsString( 'name="odavent_events_settings[cache_ttl]"', $html, 'the page still renders the other fields' );
	}

	public function test_default_event_makes_no_call_without_a_usable_key(): void {
		$this->options[ Options::OPTION ] = array();
		$this->client->shouldReceive( 'list_events' )->never();

		$html = $this->render( $this->page() );

		$this->assertStringNotContainsString( '<select', $html );
		$this->assertStringNotContainsString( 'A key is stored', $html );
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
		$this->assertStringContainsString( 'Your Odavent plan no longer includes the WordPress plugin; published events keep showing.', $loud );
		$this->assertStringContainsString( 'Your Odavent site key was rejected; check it below.', $loud );
		$this->assertStringContainsString( 'Odavent has a newer data format than this plugin supports; update the Odavent plugin.', $loud );
		$this->assertArrayHasKey( Event_Repository::INVALID_KEY_NOTICE, $this->transients, 'persistent, not consumed by viewing' );
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
}

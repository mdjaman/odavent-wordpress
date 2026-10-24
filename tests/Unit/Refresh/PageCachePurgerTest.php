<?php
namespace Odavent\Events\Tests\Unit\Refresh;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Refresh\Page_Cache_Purger;
use Odavent\Events\Tests\TestCase;

/**
 * $wpdb is a Mockery double installed as $GLOBALS['wpdb']; esc_like and prepare behave like
 * WordPress's (escaping and placeholder substitution recorded, not executed).
 */
class PageCachePurgerTest extends TestCase {

	/** @var Mockery\MockInterface */
	private $wpdb;

	/** @var array The arguments prepare() received: [query, ...args]. */
	private $prepared = array();

	/** @var int[] What get_col() answers. */
	private $ids = array( 11, 12 );

	/** @var int[] clean_post_cache() calls. */
	private $cleaned = array();

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		$this->wpdb        = Mockery::mock( 'wpdb' );
		$this->wpdb->posts = 'wp_posts';
		$this->wpdb->shouldReceive( 'esc_like' )->andReturnUsing(
			static function ( $text ) {
				return addcslashes( $text, '_%\\' );
			}
		);
		$this->wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function ( ...$args ) {
				$this->prepared = $args;
				return 'PREPARED';
			}
		);
		$this->wpdb->shouldReceive( 'get_col' )->with( 'PREPARED' )->andReturnUsing(
			function () {
				return array_map( 'strval', $this->ids );
			}
		)->byDefault();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post' => 'post',
				'page' => 'page',
				'talk' => 'talk',
			)
		);
		Functions\when( 'is_active_widget' )->justReturn( false );
		Functions\when( 'clean_post_cache' )->alias(
			function ( $id ) {
				$this->cleaned[] = $id;
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	public function test_purges_every_post_using_an_odavent_block_or_shortcode(): void {
		Actions\expectDone( 'odavent_events_purge_post' )->twice()->with( Mockery::anyOf( 11, 12 ) );
		Actions\expectDone( 'litespeed_purge_post' )->twice()->with( Mockery::anyOf( 11, 12 ) );
		Functions\expect( 'rocket_clean_post' )->twice()->with( Mockery::anyOf( 11, 12 ) );
		Functions\expect( 'w3tc_flush_post' )->twice()->with( Mockery::anyOf( 11, 12 ) );
		Functions\expect( 'wp_cache_post_change' )->twice()->with( Mockery::anyOf( 11, 12 ) );

		$count = ( new Page_Cache_Purger() )->purge_event( 7 );

		$this->assertSame( 2, $count );
		$this->assertSame( array( 11, 12 ), $this->cleaned );
	}

	public function test_query_is_prepared_with_escaped_like_patterns_and_a_limit(): void {
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\when( 'w3tc_flush_post' )->justReturn( null );
		Functions\when( 'wp_cache_post_change' )->justReturn( null );

		( new Page_Cache_Purger() )->purge_event( 7 );

		$query = $this->prepared[0];
		$args  = array_slice( $this->prepared, 1 );

		$this->assertStringContainsString( 'FROM wp_posts', $query );
		$this->assertStringContainsString( "post_status = 'publish'", $query );
		$this->assertStringContainsString( 'post_type IN (%s, %s, %s)', $query );
		$this->assertStringContainsString( 'post_content LIKE %s OR post_content LIKE %s', $query );
		$this->assertStringEndsWith( 'ORDER BY ID DESC LIMIT 500', trim( $query ), 'the most recent posts win when the cap bites' );
		$this->assertStringNotContainsString( 'odavent', $query, 'patterns are passed as prepared arguments, never inlined' );
		$this->assertSame( array( 'post', 'page', 'talk', '%wp:odavent-events/%', '%[odavent\\_%' ), $args );
	}

	public function test_post_ids_are_looked_up_once_per_request(): void {
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\when( 'w3tc_flush_post' )->justReturn( null );
		Functions\when( 'wp_cache_post_change' )->justReturn( null );
		$this->wpdb->shouldReceive( 'get_col' )->once()->andReturn( array( '11', '12' ) );

		$purger = new Page_Cache_Purger();
		$this->assertSame( 2, $purger->purge_event( 7 ) );
		$this->assertSame( 2, $purger->purge_event( 8 ) );
	}

	public function test_filter_can_add_and_remove_post_ids(): void {
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\when( 'w3tc_flush_post' )->justReturn( null );
		Functions\when( 'wp_cache_post_change' )->justReturn( null );
		Filters\expectApplied( 'odavent_events_purge_post_ids' )->once()->with( array( 11, 12 ), 7 )->andReturn( array( 12, 30, '31', 0, -4, 'x', 30, "5\n", '٣', '1e3', ' 8', '' ) );

		$count = ( new Page_Cache_Purger() )->purge_event( 7 );

		$this->assertSame( 3, $count );
		$this->assertSame( array( 12, 30, 31 ), $this->cleaned );
	}

	public function test_filter_returning_garbage_purges_nothing(): void {
		Filters\expectApplied( 'odavent_events_purge_post_ids' )->once()->andReturn( 'nope' );

		$this->assertSame( 0, ( new Page_Cache_Purger() )->purge_event( 7 ) );
		$this->assertSame( array(), $this->cleaned );
	}

	public function test_no_public_post_types_means_no_query(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		$this->wpdb->shouldNotReceive( 'get_col' );

		$this->assertSame( 0, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}

	public function test_database_error_purges_nothing(): void {
		$this->wpdb->shouldReceive( 'get_col' )->andReturn( null );

		$this->assertSame( 0, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}

	/**
	 * In a fresh process none of the page-cache plugins' functions exist (Brain Monkey's
	 * definitions persist for the life of a process). Only rocket_clean_post is defined here;
	 * calling the others would be a fatal "undefined function".
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_missing_page_cache_functions_are_ignored(): void {
		$this->assertFalse( function_exists( 'w3tc_flush_post' ) );
		$this->assertFalse( function_exists( 'wp_cache_post_change' ) );
		Functions\expect( 'rocket_clean_post' )->twice();
		Actions\expectDone( 'odavent_events_purge_post' )->twice();

		$this->assertSame( 2, ( new Page_Cache_Purger() )->purge_event( 7 ) );
		$this->assertFalse( function_exists( 'w3tc_flush_post' ) );
	}

	// ---- widgets are not post content: an active one needs the whole site purged ----------------

	/** Which widget id bases the stub answers true for: all when true, none when false. */
	private function widgets_active( bool $active ): void {
		Functions\when( 'is_active_widget' )->alias(
			function ( $callback, $widget_id, $id_base, $skip_inactive ) use ( $active ) {
				return $active && false === $callback && false === $widget_id && true === $skip_inactive && 0 === strpos( (string) $id_base, 'odavent_events_' ) ? 'sidebar-1' : false;
			}
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_active_widget_purges_the_whole_site_cache(): void {
		$this->widgets_active( true );
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\expect( 'rocket_clean_domain' )->once();
		Actions\expectDone( 'litespeed_purge_all' )->once();
		Actions\expectDone( 'odavent_events_purge_all' )->once();
		Filters\expectApplied( 'odavent_events_purge_all_on_widget' )->once()->with( true, 7 )->andReturn( true );

		$this->assertSame( 2, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}

	/**
	 * Only the first plugin function that exists runs: W3TC's and WP Super Cache's are not called after WP Rocket's.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_only_the_first_existing_whole_site_function_runs(): void {
		$this->widgets_active( true );
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		$this->assertFalse( function_exists( 'rocket_clean_domain' ) );
		Functions\expect( 'w3tc_flush_all' )->once();
		Functions\expect( 'wp_cache_clear_cache' )->never();

		( new Page_Cache_Purger() )->purge_event( 7 );
	}

	public function test_the_filter_can_turn_the_whole_site_purge_off(): void {
		$this->widgets_active( true );
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\when( 'w3tc_flush_post' )->justReturn( null );
		Functions\when( 'wp_cache_post_change' )->justReturn( null );
		Filters\expectApplied( 'odavent_events_purge_all_on_widget' )->once()->andReturn( false );
		Actions\expectDone( 'litespeed_purge_all' )->never();
		Actions\expectDone( 'odavent_events_purge_all' )->never();

		$this->assertSame( 2, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}

	public function test_no_widget_means_no_whole_site_purge(): void {
		$this->widgets_active( false );
		Functions\when( 'rocket_clean_post' )->justReturn( null );
		Functions\when( 'w3tc_flush_post' )->justReturn( null );
		Functions\when( 'wp_cache_post_change' )->justReturn( null );
		Actions\expectDone( 'litespeed_purge_all' )->never();
		Actions\expectDone( 'odavent_events_purge_all' )->never();
		Filters\expectApplied( 'odavent_events_purge_all_on_widget' )->never();

		$this->assertSame( 2, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}

	public function test_an_active_widget_purges_the_whole_site_even_when_no_post_qualifies(): void {
		$this->widgets_active( true );
		$this->ids = array();
		Actions\expectDone( 'odavent_events_purge_all' )->once();

		$this->assertSame( 0, ( new Page_Cache_Purger() )->purge_event( 7 ) );
	}
}

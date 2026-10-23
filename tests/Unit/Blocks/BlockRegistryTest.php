<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Brain\Monkey\Functions;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Blocks\Renderers\Event_Header;
use Odavent\Events\Blocks\Renderers\Find_Registration;
use Odavent\Events\Blocks\Renderers\Register_Button;
use Odavent\Events\Blocks\Renderers\Tickets;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class BlockRegistryTest extends BlockTestCase {

	// ---- event resolution --------------------------------------------------------

	public function test_the_event_id_attribute_wins_over_slug_and_default(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array( 'other' => 7 ), 9 );
		$registry->render_block( 'odavent-events/event-header', array( 'eventId' => 42, 'eventSlug' => 'other' ) );

		$this->assertSame( array( array( 42, 'other', false ) ), $this->repo_calls );
	}

	public function test_the_slug_is_used_when_there_is_no_id(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array( 'sommet' => 42 ), 9 );
		$registry->render_block( 'odavent-events/event-header', array( 'eventId' => 0, 'eventSlug' => 'sommet' ) );

		$this->assertSame( array( array( 42, 'sommet', false ) ), $this->repo_calls );
	}

	public function test_the_default_event_is_used_when_the_block_names_none(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array(), 42 );
		$html     = $registry->render_block( 'odavent-events/event-header', array() );

		$this->assertSame( array( array( 42, '', false ) ), $this->repo_calls );
		$this->assertStringContainsString( 'odv-header', $html );
	}

	public function test_an_unknown_slug_does_not_fall_back_to_the_default_event(): void {
		$this->editor = true;
		$registry     = $this->registry( $this->payload(), Event_View::GONE, array(), 42 );
		$html         = $registry->render_block( 'odavent-events/event-header', array( 'eventSlug' => 'nope' ) );

		$this->assertSame( array(), $this->repo_calls );
		$this->assertStringContainsString( 'odv-notice', $html );
	}

	public function test_digit_strings_and_junk_ids(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array(), 0 );
		$registry->render_block( 'event-header', array( 'eventId' => '42' ) );
		$registry->render_block( 'event-header', array( 'eventId' => array( 42 ) ) );
		$registry->render_block( 'event-header', array( 'eventId' => -3 ) );
		$registry->render_block( 'event-header', array( 'eventId' => '4x2' ) );

		$this->assertSame( array( array( 42, '', false ) ), $this->repo_calls );
	}

	public function test_no_event_chosen_is_a_notice_for_editors_and_nothing_for_visitors(): void {
		$registry = $this->registry( $this->payload() );
		$this->assertSame( '', $registry->render_block( 'odavent-events/event-header', array() ) );
		$this->assertSame( array(), $this->enqueued_styles );

		$this->editor = true;
		$html         = $registry->render_block( 'odavent-events/event-header', array() );
		$this->assertStringContainsString( 'Choose an event', $html );
		$this->assertStringStartsWith( '<div class="odv-notice odv-notice--choose" role="status">', $html );
		$this->assertSame( array(), $this->repo_calls );
	}

	// ---- failures never escape ---------------------------------------------------------

	private function throwing_registry(): Block_Registry {
		$registry = $this->registry( $this->payload() );
		$registry->use_renderer(
			'event-header',
			new class() implements Renderer {
				public function render( Event_View $view, array $atts ): string {
					throw new \RuntimeException( 'boom <secret>' );
				}
			}
		);

		return $registry;
	}

	public function test_a_throwing_renderer_gives_visitors_nothing(): void {
		$this->assertSame( '', $this->throwing_registry()->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) ) );
	}

	public function test_a_throwing_renderer_gives_editors_a_notice_without_the_error_text(): void {
		$this->editor = true;
		$html         = $this->throwing_registry()->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );

		$this->assertStringStartsWith( '<div class="odv-notice odv-notice--error" role="status">', $html );
		$this->assertStringNotContainsString( 'boom', $html );
	}

	public function test_a_throwing_repository_never_escapes(): void {
		$repo = \Mockery::mock( \Odavent\Events\Data\Event_Repository::class );
		$repo->shouldReceive( 'get' )->andThrow( new \Error( 'type error' ) );
		$registry = new Block_Registry( $repo, $this->options() );

		$this->assertSame( '', $registry->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) ) );
		$this->editor = true;
		$this->assertStringContainsString( 'odv-notice--error', $registry->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) ) );
	}

	public function test_an_unknown_block_renders_nothing_for_visitors(): void {
		$this->assertSame( '', $this->registry( $this->payload() )->render_block( 'odavent-events/nope', array( 'eventId' => 42 ) ) );
		$this->assertSame( array(), $this->repo_calls );
	}

	// ---- styles ------------------------------------------------------------------------

	public function test_styles_are_enqueued_when_something_rendered(): void {
		$this->registry( $this->payload() )->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events' ), $this->enqueued_styles );
	}

	public function test_styles_are_not_enqueued_when_a_block_renders_nothing(): void {
		$payload            = $this->payload();
		$payload['tickets'] = array();
		$this->assertSame( '', $this->registry( $payload )->render_block( 'odavent-events/tickets', array( 'eventId' => 42 ) ) );
		$this->assertSame( array(), $this->enqueued_styles );
	}

	// ---- markup contract -------------------------------------------------------------------

	public function test_every_block_root_is_a_section_with_the_event_id_and_no_id_attribute(): void {
		$registry = $this->registry( $this->payload() );
		$roots    = array(
			'event-header'      => 'odv-header',
			'register-button'   => 'odv-register',
			'tickets'           => 'odv-tickets',
			'find-registration' => 'odv-find-registration',
		);
		foreach ( $roots as $block => $root ) {
			$html = trim( $registry->render_block( 'odavent-events/' . $block, array( 'eventId' => 42, 'className' => 'is-style-x my"class <b>' ) ) );
			$this->assertStringStartsWith( '<section class="' . $root . ' is-style-x myclass b" data-odv-event="42">', $html, $block );
			$this->assertDoesNotMatchRegularExpression( '/\sid\s*=/', $html, $block );
			$this->assertDoesNotMatchRegularExpression( '/\sstyle\s*=/', $html, $block );
		}
	}

	public function test_renderers_map_the_four_core_blocks(): void {
		$map = $this->registry( null )->renderers();

		$this->assertSame( Event_Header::class, $map['event-header'] );
		$this->assertSame( Register_Button::class, $map['register-button'] );
		$this->assertSame( Tickets::class, $map['tickets'] );
		$this->assertSame( Find_Registration::class, $map['find-registration'] );
		foreach ( $map as $class ) {
			$this->assertTrue( is_subclass_of( $class, Renderer::class ) );
		}
	}

	// ---- registration with WordPress -----------------------------------------------------------

	/** @var array[] */
	private $registered = array();

	/** @var array[] */
	private $inline = array();

	/** @var array[] */
	private $scripts = array();

	private function capture_registration(): void {
		Functions\when( 'register_block_type' )->alias(
			function ( $dir, $args = array() ) {
				$this->registered[] = array( $dir, $args );
				return true;
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function () {
				$this->scripts[] = func_get_args();
				return true;
			}
		);
		Functions\when( 'wp_add_inline_script' )->alias(
			function () {
				$this->inline[] = func_get_args();
				return true;
			}
		);
		Functions\when( 'wp_set_script_translations' )->justReturn( true );
	}

	public function test_register_registers_each_block_directory_exactly_once(): void {
		$this->capture_registration();
		$registry = $this->registry( $this->payload() );
		$registry->register();
		$registry->register();

		$dirs     = array_map( 'dirname', glob( ODAVENT_EVENTS_DIR . 'blocks/*/block.json' ) );
		$expected = array_map( 'realpath', $dirs );
		sort( $expected );

		$got = array_map(
			function ( $row ) {
				return realpath( $row[0] );
			},
			$this->registered
		);
		sort( $got );

		$this->assertGreaterThanOrEqual( 4, count( $expected ) );
		$this->assertSame( $expected, $got );
		foreach ( $this->registered as $row ) {
			$this->assertIsCallable( $row[1]['render_callback'] );
		}
		$this->assertCount( 1, $this->scripts, 'Editor script registered once' );
	}

	public function test_the_render_callback_goes_through_render_block(): void {
		$this->capture_registration();
		$registry = $this->registry( $this->payload() );
		$registry->register();

		foreach ( $this->registered as $row ) {
			if ( 'event-header' === basename( $row[0] ) ) {
				$html = call_user_func( $row[1]['render_callback'], array( 'eventId' => 42 ), '', null );
				$this->assertStringContainsString( 'odv-header__title', $html );
				return;
			}
		}
		$this->fail( 'event-header not registered' );
	}

	public function test_the_editor_script_handle_and_dependencies(): void {
		$this->capture_registration();
		$this->registry( null )->register();

		$this->assertSame( 'odavent-events-editor', $this->scripts[0][0] );
		$this->assertSame( 'https://blog.example.com/wp-content/plugins/odavent-events/assets/js/editor.js', $this->scripts[0][1] );
		$this->assertSame(
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' ),
			$this->scripts[0][2]
		);
		$this->assertFileExists( ODAVENT_EVENTS_DIR . 'assets/js/editor.js' );
	}

	public function test_the_inline_metadata_lists_every_block(): void {
		$this->capture_registration();
		$this->registry( null )->register();

		$this->assertCount( 1, $this->inline );
		list( $handle, $code, $position ) = $this->inline[0];
		$this->assertSame( 'odavent-events-editor', $handle );
		$this->assertSame( 'before', $position );
		$this->assertStringStartsWith( 'window.odaventEventsBlocks = ', $code );
		$this->assertStringEndsWith( ';', $code );

		$meta  = json_decode( substr( $code, strlen( 'window.odaventEventsBlocks = ' ), -1 ), true );
		$names = array_column( $meta, 'name' );
		foreach ( glob( ODAVENT_EVENTS_DIR . 'blocks/*/block.json' ) as $file ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			$this->assertContains( $json['name'], $names );
			$row = $meta[ array_search( $json['name'], $names, true ) ];
			$this->assertSame( $json['title'], $row['title'] );
			$this->assertSame( $json['description'], $row['description'] );
			$this->assertSame( $json['attributes'], $row['attributes'] );
			foreach ( array_keys( $json['attributes'] ) as $attribute ) {
				$this->assertIsString( $row['labels'][ $attribute ] ?? null, $json['name'] . ' label for ' . $attribute );
			}
		}
	}

	public function test_the_inline_metadata_cannot_break_out_of_the_script_element(): void {
		$dir = sys_get_temp_dir() . '/odv-blocks-' . uniqid();
		mkdir( $dir . '/evil', 0777, true );
		file_put_contents(
			$dir . '/evil/block.json',
			json_encode(
				array(
					'apiVersion'  => 3,
					'name'        => 'odavent-events/evil',
					'title'       => '</script><script>alert(1)</script><!--<script>',
					'description' => 'a & b',
					'attributes'  => array( 'eventId' => array( 'type' => 'integer', 'default' => 0 ) ),
				)
			)
		);

		$this->capture_registration();
		( new Block_Registry( $this->repository( null ), $this->options(), null, $dir ) )->register();

		$code = $this->inline[0][1];
		$this->assertStringNotContainsString( '<', $code );
		$this->assertStringNotContainsString( '>', $code );
		$this->assertStringNotContainsString( '&', $code );
		$meta = json_decode( substr( $code, strlen( 'window.odaventEventsBlocks = ' ), -1 ), true );
		$this->assertSame( '</script><script>alert(1)</script><!--<script>', $meta[0]['title'] );

		unlink( $dir . '/evil/block.json' );
		rmdir( $dir . '/evil' );
		rmdir( $dir );
	}

	public function test_the_block_category_is_added_once(): void {
		$registry   = $this->registry( null );
		$categories = $registry->add_category( array( array( 'slug' => 'text', 'title' => 'Text' ) ) );

		$this->assertSame( 'odavent-events', $categories[1]['slug'] );
		$this->assertSame( 'Odavent', $categories[1]['title'] );
		$this->assertCount( 2, $registry->add_category( $categories ) );
		$this->assertSame( 'junk', $registry->add_category( 'junk' ), 'A filter value that is not an array is passed through.' );
	}

	// ---- page-level hooks -------------------------------------------------------------------------

	private function on_post( string $content, bool $singular = true ): void {
		Functions\when( 'is_singular' )->justReturn( $singular );
		$post               = new \stdClass();
		$post->post_content = $content;
		Functions\when( 'get_post' )->justReturn( $post );
	}

	public function odavent_content(): array {
		return array(
			'block'     => array( '<!-- wp:odavent-events/tickets {"eventId":42} /-->' ),
			'shortcode' => array( '<p>[odavent_tickets event="42"]</p>' ),
		);
	}

	/**
	 * In a separate process: DONOTCACHEPAGE, once defined by another test, would stay defined.
	 *
	 * @dataProvider odavent_content
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_preview_pages_are_not_cached_for_editors( string $content ): void {
		$this->editor = true;
		$this->on_post( $content );
		Functions\expect( 'nocache_headers' )->once();
		$this->assertFalse( defined( 'DONOTCACHEPAGE' ) );

		$this->registry( null )->prevent_preview_caching();

		$this->assertTrue( defined( 'DONOTCACHEPAGE' ) );
	}

	public function test_visitors_pages_stay_cacheable(): void {
		$this->editor = false;
		$this->on_post( '<!-- wp:odavent-events/tickets /-->' );
		Functions\expect( 'nocache_headers' )->never();

		$this->registry( null )->prevent_preview_caching();
	}

	public function test_editors_on_pages_without_odavent_content_stay_cacheable(): void {
		$this->editor = true;
		Functions\expect( 'nocache_headers' )->never();

		$this->on_post( '<!-- wp:paragraph --><p>odavent-events</p><!-- /wp:paragraph -->' );
		$this->registry( null )->prevent_preview_caching();

		$this->on_post( '<!-- wp:odavent-events/tickets /-->', false );
		$this->registry( null )->prevent_preview_caching();
	}

	/**
	 * @dataProvider odavent_content
	 */
	public function test_styles_are_enqueued_early_on_pages_with_odavent_content( string $content ): void {
		$this->on_post( $content );
		$this->registry( null )->enqueue_early_styles();

		$this->assertSame( array( 'odavent-events' ), $this->enqueued_styles );
	}

	public function test_styles_are_not_enqueued_early_elsewhere(): void {
		$this->on_post( '<p>Hello</p>' );
		$this->registry( null )->enqueue_early_styles();
		$this->on_post( '<!-- wp:odavent-events/tickets /-->', false );
		$this->registry( null )->enqueue_early_styles();

		$this->assertSame( array(), $this->enqueued_styles );
	}
}

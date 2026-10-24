<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Brain\Monkey\Filters;
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

	/** @var string[] Temporary blocks directories to remove after each test. */
	private $temp_dirs = array();

	protected function setUp(): void {
		parent::setUp();
		\WP_Block_Type_Registry::$types = array();
	}

	protected function tearDown(): void {
		\WP_Block_Type_Registry::$types = array();
		foreach ( $this->temp_dirs as $dir ) {
			foreach ( glob( $dir . '/*/block.json' ) ?: array() as $file ) {
				unlink( $file );
			}
			foreach ( glob( $dir . '/*', GLOB_ONLYDIR ) ?: array() as $sub ) {
				rmdir( $sub );
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
		$this->temp_dirs = array();
		parent::tearDown();
	}

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

	public function test_agenda_and_countdown_scripts_are_enqueued_for_the_event_page(): void {
		Filters\expectApplied( 'odavent_events_now' )->zeroOrMoreTimes()->andReturn( strtotime( '2026-11-01T00:00:00Z' ) );

		$this->registry( $this->payload() )->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );
		$this->assertEqualsCanonicalizing( array( 'odavent-events-countdown', 'odavent-events-agenda-filter' ), $this->enqueued_scripts );

		// Only the scripts whose markup is on the page.
		$this->enqueued_scripts = array();
		$this->registry( $this->payload() )->render_block( 'odavent-events/event-page', array( 'eventId' => 42, 'sections' => 'speakers,agenda' ) );
		$this->assertSame( array( 'odavent-events-agenda-filter' ), $this->enqueued_scripts );

		$this->enqueued_scripts = array();
		$this->registry( $this->payload() )->render_block( 'odavent-events/event-page', array( 'eventId' => 42, 'sections' => 'speakers,venue' ) );
		$this->assertSame( array(), $this->enqueued_scripts );

		// An ended event: no countdown on the page, so no countdown script.
		$this->enqueued_scripts = array();
		$this->registry( $this->payload( self::state_overrides()['ended closed'] ) )->render_block( 'odavent-events/event-page', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events-agenda-filter' ), $this->enqueued_scripts );
	}

	public function test_the_event_page_carries_the_lifecycle_notice(): void {
		$this->assertContains( 'event-page', Block_Registry::NOTICE_BLOCKS );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Event_Page', $this->registry( null )->renderers()['event-page'] );
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

	public function test_renderers_map_the_content_blocks(): void {
		$map = $this->registry( null )->renderers();

		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Speakers', $map['speakers'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Agenda', $map['agenda'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Sponsors', $map['sponsors'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Countdown', $map['countdown'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Venue', $map['venue'] );
		$this->assertSame( 'Odavent\\Events\\Blocks\\Renderers\\Add_To_Calendar', $map['add-to-calendar'] );
	}

	public function test_every_renderer_has_a_block_json_and_every_block_json_a_renderer(): void {
		$slugs = array();
		foreach ( glob( ODAVENT_EVENTS_DIR . 'blocks/*/block.json' ) as $file ) {
			$slugs[] = basename( dirname( $file ) );
		}
		$mapped = array_keys( $this->registry( null )->renderers() );
		sort( $slugs );
		sort( $mapped );

		$this->assertSame( $mapped, $slugs );
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

	public function test_register_alone_adds_no_inline_metadata(): void {
		$this->capture_registration();
		$this->registry( null )->register();

		$this->assertSame( array(), $this->inline, 'Front-end requests must not build the editor metadata.' );
	}

	public function test_the_inline_metadata_is_added_once(): void {
		$this->capture_registration();
		$registry = $this->registry( null );
		$registry->register();
		$registry->add_editor_metadata();
		$registry->add_editor_metadata();

		$this->assertCount( 1, $this->inline );
	}

	public function test_the_inline_metadata_uses_the_translated_registered_titles(): void {
		$this->capture_registration();
		$type              = new \stdClass();
		$type->title       = 'Billets';
		$type->description = 'Les billets de l’événement.';
		\WP_Block_Type_Registry::$types['odavent-events/tickets'] = $type;

		$registry = $this->registry( null );
		$registry->register();
		$registry->add_editor_metadata();

		$meta = json_decode( substr( $this->inline[0][1], strlen( 'window.odaventEventsBlocks = ' ), -1 ), true );
		$rows = array_column( $meta, null, 'name' );
		$this->assertSame( 'Billets', $rows['odavent-events/tickets']['title'] );
		$this->assertSame( 'Les billets de l’événement.', $rows['odavent-events/tickets']['description'] );
		$this->assertSame( 'Event header', $rows['odavent-events/event-header']['title'], 'Not registered: block.json title.' );
	}

	public function test_block_json_is_read_once_per_file(): void {
		$dir = sys_get_temp_dir() . '/odv-blocks-' . uniqid();
		mkdir( $dir . '/one', 0777, true );
		$file = $dir . '/one/block.json';
		file_put_contents( $file, json_encode( array( 'name' => 'odavent-events/one', 'title' => 'First', 'attributes' => array() ) ) );

		$registry = new Block_Registry( $this->repository( null ), $this->options(), null, $dir );
		$this->assertSame( 'First', $registry->editor_metadata()[0]['title'] );

		file_put_contents( $file, json_encode( array( 'name' => 'odavent-events/one', 'title' => 'Second', 'attributes' => array() ) ) );
		$this->assertSame( 'First', $registry->editor_metadata()[0]['title'], 'memoised' );

		unlink( $file );
		rmdir( $dir . '/one' );
		rmdir( $dir );
	}

	/**
	 * A temporary blocks directory holding {slug}/block.json files with the given raw contents.
	 *
	 * @param array<string, string> $files Slug => raw block.json text.
	 */
	private function blocks_dir( array $files ): string {
		$dir = sys_get_temp_dir() . '/odv-blocks-' . uniqid( '', true );
		foreach ( $files as $slug => $raw ) {
			mkdir( $dir . '/' . $slug, 0777, true );
			file_put_contents( $dir . '/' . $slug . '/block.json', $raw );
		}
		if ( array() === $files ) {
			mkdir( $dir, 0777, true );
		}
		$this->temp_dirs[] = $dir;

		return $dir;
	}

	public function test_block_attributes_of_a_missing_file_are_empty(): void {
		$dir = $this->blocks_dir( array() );
		mkdir( $dir . '/speakers' ); // The folder exists, block.json does not.

		$this->assertSame( array(), ( new Block_Registry( $this->repository( null ), $this->options(), null, $dir ) )->block_attributes( 'speakers' ) );
		$this->assertSame( array(), ( new Block_Registry( $this->repository( null ), $this->options(), null, $dir . '/nowhere' ) )->block_attributes( 'speakers' ) );
	}

	public function test_block_attributes_of_invalid_json_are_empty(): void {
		foreach ( array( '{not json', '', '"text"', '5', 'null', '[1,2]', '{"attributes": "nope"}', '{"attributes": 5}', '{"name": "x"}' ) as $raw ) {
			$dir = $this->blocks_dir( array( 'speakers' => $raw ) );

			$this->assertSame( array(), ( new Block_Registry( $this->repository( null ), $this->options(), null, $dir ) )->block_attributes( 'speakers' ), var_export( $raw, true ) );
		}
	}

	public function test_block_attributes_of_an_unknown_slug_are_empty_even_when_a_file_exists(): void {
		$dir = $this->blocks_dir( array( 'nope' => '{"attributes": {"columns": {"type": "integer", "default": 3}}}' ) );

		$registry = new Block_Registry( $this->repository( null ), $this->options(), null, $dir );

		$this->assertSame( array(), $registry->block_attributes( 'nope' ) );
		$this->assertSame( array(), $registry->block_attributes( '../nope' ) );
		$this->assertSame( array(), $registry->block_attributes( '' ) );
	}

	public function test_block_attributes_drop_non_string_names_and_non_array_definitions(): void {
		$dir = $this->blocks_dir(
			array(
				// A JSON list has integer keys; "ok" is kept; scalars, null and booleans are not definitions.
				'speakers' => '{"attributes": {"columns": {"type": "integer", "default": 3}, "text": "string", "n": 5, "nothing": null, "flag": true, "empty": [], "0": {"type": "string"}}}',
				'venue'    => '{"attributes": [{"type": "string"}, {"type": "integer"}]}',
			)
		);

		$registry = new Block_Registry( $this->repository( null ), $this->options(), null, $dir );

		// "0" is decoded to the integer key 0: not a name. Scalars, null and booleans are not definitions.
		$this->assertSame(
			array( 'columns' => array( 'type' => 'integer', 'default' => 3 ), 'empty' => array() ),
			$registry->block_attributes( 'speakers' )
		);

		$this->assertSame( array(), $registry->block_attributes( 'venue' ), 'A JSON list gives integer names: none survive.' );
	}

	public function test_the_inline_metadata_lists_every_block(): void {
		$this->capture_registration();
		$registry = $this->registry( null );
		$registry->register();
		$registry->add_editor_metadata();

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
		$registry = new Block_Registry( $this->repository( null ), $this->options(), null, $dir );
		$registry->register();
		$registry->add_editor_metadata();

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

	// ---- preview capability ---------------------------------------------------------------

	public function test_the_preview_capability_defaults_to_edit_posts(): void {
		$this->assertSame( 'edit_posts', Block_Registry::preview_capability() );
	}

	public function test_a_junk_filtered_capability_falls_back_to_edit_posts(): void {
		foreach ( array( '', '  ', array( 'x' ), null, 42 ) as $junk ) {
			Filters\expectApplied( 'odavent_events_preview_capability' )->once()->andReturn( $junk );
			$this->assertSame( 'edit_posts', Block_Registry::preview_capability() );
		}
	}

	/**
	 * The filtered capability decides preview, editor notices and the early no-cache alike.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_a_filtered_capability_is_used_by_preview_and_no_cache(): void {
		Filters\expectApplied( 'odavent_events_preview_capability' )->andReturn( 'edit_others_posts' );
		$asked = array();
		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) use ( &$asked ) {
				$asked[] = $capability;
				return 'edit_others_posts' === $capability;
			}
		);

		$registry = $this->registry( $this->payload( self::state_overrides()['draft'] ) );
		$html     = $registry->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) );
		$this->assertSame( array( array( 42, '', true ) ), $this->repo_calls );
		$this->assertStringContainsString( 'Draft preview', $html );

		$this->on_post( '<!-- wp:odavent-events/tickets /-->' );
		Functions\expect( 'nocache_headers' )->once();
		$registry->prevent_preview_caching();

		$this->assertSame( array( 'edit_others_posts' ), array_values( array_unique( $asked ) ) );
	}

	public function test_a_user_with_only_edit_posts_is_a_visitor_when_the_capability_is_raised(): void {
		Filters\expectApplied( 'odavent_events_preview_capability' )->andReturn( 'edit_others_posts' );
		$this->editor = true; // Answers true for edit_posts only.

		$registry = $this->registry( $this->payload( self::state_overrides()['draft'] ) );
		$this->assertSame( '', $registry->render_block( 'odavent-events/event-header', array( 'eventId' => 42 ) ) );
		$this->assertSame( array( array( 42, '', false ) ), $this->repo_calls );

		$this->on_post( '<!-- wp:odavent-events/tickets /-->' );
		Functions\expect( 'nocache_headers' )->never();
		$registry->prevent_preview_caching();
	}
}

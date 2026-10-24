<?php
namespace Odavent\Events\Tests\Unit\Widgets;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Api\Result;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Blocks\Renderers\Event_Page;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Shortcodes\Shortcodes;
use Odavent\Events\Tests\Support\BlockTestCase;
use Odavent\Events\Tests\Support\WpStubs;
use Odavent\Events\Widgets\Block_Widget;
use Odavent\Events\Widgets\Widgets;

/**
 * Classic widgets: one per block, generated from block.json, sanitised on save and again on render, and
 * printing nothing at all (no wrapper, no title) when the block has nothing to show.
 */
class WidgetsTest extends BlockTestCase {

	const ARGS = array(
		'before_widget' => '<section class="widget odavent_events_widget">',
		'after_widget'  => '</section>',
		'before_title'  => '<h2 class="widget-title">',
		'after_title'   => '</h2>',
	);

	/** @var Block_Widget[] Widgets passed to register_widget(), by id base. */
	private $registered = array();

	protected function setUp(): void {
		parent::setUp();
		$this->registered = array();
		Format::disable_intl( true );
		Event_Page::reset_ids();
		Filters\expectApplied( 'odavent_events_now' )->zeroOrMoreTimes()->andReturn( strtotime( '2026-11-01T00:00:00Z' ) );

		Functions\when( 'register_widget' )->alias(
			function ( $widget ) {
				$this->registered[ $widget->id_base ] = $widget;
			}
		);
		// Close enough to WordPress for these tests: tags stripped, whitespace collapsed, trimmed.
		Functions\when( 'sanitize_text_field' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			static function ( $value ) {
				return trim( (string) preg_replace( '/[^a-z0-9_\-]+/', '-', strtolower( strip_tags( (string) $value ) ) ), '-' );
			}
		);
	}

	protected function tearDown(): void {
		\WP_Block_Type_Registry::$types = array();
		Format::disable_intl( false );
		Event_Page::reset_ids();
		parent::tearDown();
	}

	private function widgets( Block_Registry $registry, ?Event_Repository $repository = null ): Widgets {
		$widgets = new Widgets( $registry, new Shortcodes( $registry ), $repository );
		$widgets->register();

		return $widgets;
	}

	private function widget( string $slug, ?Block_Registry $registry = null, ?Event_Repository $repository = null ): Block_Widget {
		$this->registered = array();
		$this->widgets( null !== $registry ? $registry : $this->registry( $this->payload(), Event_View::GONE, array(), 42 ), $repository );
		$this->assertArrayHasKey( Widgets::id_base( $slug ), $this->registered );

		return $this->registered[ Widgets::id_base( $slug ) ];
	}

	private function output( Block_Widget $widget, $instance, array $args = self::ARGS ): string {
		ob_start();
		$widget->widget( $args, $instance );

		return (string) ob_get_clean();
	}

	private function form( Block_Widget $widget, $instance ): string {
		ob_start();
		$widget->form( $instance );

		return (string) ob_get_clean();
	}

	/**
	 * A repository double that knows events 42 (the full payload) and $extra, and has the editor list $list
	 * (null: the list cannot be loaded).
	 *
	 * @param array<int, array> $extra Event id => payload.
	 * @param array|null        $list  Rows of the editor list.
	 */
	private function repo( array $extra = array(), ?array $list = null ): Event_Repository {
		$payloads = array( 42 => $this->payload() ) + $extra;
		$repo     = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'get' )->andReturnUsing(
			function ( $id, $slug = '', $preview = false ) use ( $payloads ) {
				$this->repo_calls[] = array( $id, $slug, $preview );

				return isset( $payloads[ $id ] ) ? Event_View::from_payload( $payloads[ $id ], $preview ) : Event_View::empty( Event_View::GONE );
			}
		);
		$repo->shouldReceive( 'resolve_id' )->andReturn( null );
		$repo->shouldReceive( 'list_for_editors' )->andReturn( null !== $list ? Result::ok( $list ) : Result::fail( Result::INVALID_KEY ) );

		return $repo;
	}

	private function registry_over( Event_Repository $repo, int $default_event = 42 ): Block_Registry {
		return new Block_Registry( $repo, $this->options( $default_event ) );
	}

	/**
	 * Attributes the renderer of a block receives when a widget renders $instance.
	 */
	private function seen_by_renderer( string $slug, $instance ): array {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array(), 42 );
		$seen     = array();
		$registry->use_renderer(
			$slug,
			new class( $seen ) implements Renderer {
				/** @var array */
				private $seen;

				public function __construct( array &$seen ) {
					$this->seen = &$seen;
				}

				public function render( Event_View $view, array $atts ): string {
					$this->seen[] = $atts;

					return '<p>seen</p>';
				}
			}
		);

		$this->assertStringContainsString( '<p>seen</p>', $this->output( $this->widget( $slug, $registry ), $instance ) );
		$this->assertCount( 1, $seen );

		return $seen[0];
	}

	// ---- registration -----------------------------------------------------------------

	public function test_one_widget_per_block_including_the_event_page(): void {
		$type        = new \stdClass();
		$type->title = 'Intervenants';
		\WP_Block_Type_Registry::$types['odavent-events/speakers'] = $type;

		$registry = $this->registry( $this->payload() );
		$this->widgets( $registry );

		$expected = array();
		foreach ( array_keys( $registry->renderers() ) as $slug ) {
			$expected[] = 'odavent_events_' . str_replace( '-', '_', $slug );
		}
		$this->assertCount( 11, $expected );
		$this->assertEqualsCanonicalizing( $expected, array_keys( $this->registered ) );
		$this->assertEqualsCanonicalizing( $expected, Widgets::id_bases() );
		$this->assertContains( 'odavent_events_event_page', Widgets::id_bases() );

		foreach ( $this->registered as $widget ) {
			$this->assertInstanceOf( Block_Widget::class, $widget );
			$this->assertInstanceOf( \WP_Widget::class, $widget );
			$this->assertSame( 'odavent_events_widget', $widget->widget_options['classname'] );
			$this->assertStringStartsWith( 'Odavent: ', $widget->name );
		}

		$this->assertSame( 'Odavent: Intervenants', $this->registered['odavent_events_speakers']->name, 'The translated registered block title.' );
		$this->assertSame( 'Odavent: Event page', $this->registered['odavent_events_event_page']->name, 'Not registered yet: the block.json title.' );
		$this->assertSame( 'Odavent: Add to calendar', $this->registered['odavent_events_add_to_calendar']->name );
		$this->assertNotSame( '', $this->registered['odavent_events_tickets']->widget_options['description'] );
	}

	// ---- saving -------------------------------------------------------------------------

	public function test_update_casts_and_clamps_like_the_shortcodes(): void {
		$saved = $this->widget( 'speakers' )->update(
			array(
				'title'       => "  <b>Our</b>\n speakers ",
				'eventId'     => '42',
				'columns'     => '99',
				'limit'       => 'abc',
				'speakerId'   => '7',
				'showBio'     => '1',
				'showCompany' => '1',
			),
			array()
		);

		$this->assertSame(
			array(
				'title'       => 'Our speakers',
				'eventId'     => 42,
				'columns'     => 4,
				'showBio'     => true,
				'showCompany' => true,
				'speakerId'   => 7,
			),
			self::sorted_like( $saved, array( 'title', 'eventId', 'columns', 'showBio', 'showCompany', 'speakerId' ) )
		);
		$this->assertArrayNotHasKey( 'limit', $saved, '"abc" is not an integer: dropped, so the default applies.' );

		// Below the range: the minimum. Sponsors columns are 1..6.
		$this->assertSame( 2, $this->widget( 'speakers' )->update( array( 'columns' => '1' ), array() )['columns'] );
		$this->assertSame( 1, $this->widget( 'sponsors' )->update( array( 'columns' => '0' ), array() )['columns'] );
		$this->assertSame( 6, $this->widget( 'sponsors' )->update( array( 'columns' => '12' ), array() )['columns'] );

		// Text: sanitised and at most 500 characters, as for a shortcode.
		$tickets = $this->widget( 'tickets' )->update( array( 'buttonLabel' => '<script>x</script>Buy ' . str_repeat( 'a', 900 ) ), array() );
		$this->assertStringStartsWith( 'xBuy aaa', $tickets['buttonLabel'] );
		$this->assertLessThanOrEqual( 500, strlen( $tickets['buttonLabel'] ) );

		// The same casting as the shortcode for every scalar attribute.
		$registry   = $this->registry( $this->payload() );
		$shortcodes = new Shortcodes( $registry );
		$from_short = $shortcodes->to_block_attributes( 'tickets', array( 'event_id' => '42', 'show_description' => 'off', 'show_availability' => 'yes', 'button_label' => 'Buy' ) );
		$from_form  = $this->widget( 'tickets' )->update( array( 'eventId' => '42', 'showDescription' => '0', 'showAvailability' => '1', 'buttonLabel' => 'Buy' ), array() );
		unset( $from_form['title'] );
		ksort( $from_short );
		ksort( $from_form );
		$this->assertSame( $from_short, $from_form );
	}

	public function test_empty_event_means_the_default_event_and_the_managed_attributes_cannot_be_set(): void {
		$saved = $this->widget( 'event-page' )->update(
			array(
				'eventId'                    => '',
				'eventSlug'                  => 'another-event',
				'className'                  => 'evil',
				'lock'                       => array( 'remove' => true ),
				Event_Page::IN_PAGE          => '1',
				Event_Page::IN_PAGE_REGISTER_SHOWN => '1',
				'showHeadings'               => '1',
			),
			array( 'eventId' => 42 )
		);

		$this->assertArrayNotHasKey( 'eventId', $saved, 'Empty: the default event.' );
		foreach ( array( 'eventSlug', 'className', 'lock', 'metadata', Event_Page::IN_PAGE, Event_Page::IN_PAGE_REGISTER_SHOWN ) as $key ) {
			$this->assertArrayNotHasKey( $key, $saved, $key );
		}
		$this->assertTrue( $saved['showHeadings'] );
		$this->assertSame( '', $saved['title'] );

		$this->assertArrayNotHasKey( 'eventId', $this->widget( 'speakers' )->update( array( 'eventId' => '0' ), array() ) );
		$this->assertArrayNotHasKey( 'eventId', $this->widget( 'speakers' )->update( array( 'eventId' => '-3' ), array() ) );
	}

	public function test_unchecked_checkbox_is_saved_as_false_not_the_default(): void {
		$widget = $this->widget( 'speakers' );
		// The browser sends no field for an unchecked box: showCompany (default true) and showBio are absent.
		$saved = $widget->update( array( 'title' => '', 'eventId' => '42', 'columns' => '3' ), array( 'showCompany' => true ) );

		$this->assertFalse( $saved['showCompany'] );
		$this->assertFalse( $saved['showBio'] );

		$html = $this->output( $widget, $saved );
		$this->assertStringContainsString( 'odv-speakers', $html );
		$this->assertStringNotContainsString( 'odv-speakers__company', $html, 'Saved false: the company is hidden.' );

		// And the form shows the box unchecked, not the default.
		$form = $this->form( $widget, $saved );
		$this->assertSame( 1, preg_match( '/<input[^>]*name="' . preg_quote( $widget->get_field_name( 'showCompany' ), '/' ) . '"[^>]*>/', $form, $m ), $form );
		$this->assertStringNotContainsString( 'checked', $m[0] );
	}

	// ---- rendering ----------------------------------------------------------------------

	public function test_malformed_saved_instance_renders_defaults_or_nothing(): void {
		$registry = $this->registry_over( $this->repo() );
		$widget   = $this->widget( 'speakers', $registry );
		$defaults = $registry->render_block( 'speakers', array() );
		$this->assertStringContainsString( 'odv-speakers__company', $defaults, 'Default showCompany true.' );

		// Not an array at all, or missing every key: the defaults, for the default event.
		foreach ( array( null, false, 'a:1:{', 17, array(), array( 'title' => '' ) ) as $instance ) {
			$html = $this->output( $widget, $instance );
			$this->assertSame( self::ARGS['before_widget'] . $defaults . self::ARGS['after_widget'], $html, var_export( $instance, true ) );
		}

		// Arrays and objects where scalars are expected: dropped, so the defaults apply; no warning, no fatal.
		$html = $this->output(
			$widget,
			array(
				'title'       => array( 'x' ),
				'eventId'     => array( 42 ),
				'columns'     => array( '9' ),
				'showCompany' => new \stdClass(),
				'showBio'     => array( true ),
			)
		);
		$this->assertSame( self::ARGS['before_widget'] . $defaults . self::ARGS['after_widget'], $html );
		$this->assertStringNotContainsString( 'widget-title', $html, 'A title that is not text is no title.' );

		// Stored values from another version are re-sanitised on render: out-of-range columns are clamped,
		// a stored HTML label is stripped.
		$html = $this->output( $widget, array( 'columns' => 99 ) );
		$this->assertStringContainsString( 'odv-speakers__list--cols-4', $html );
		$tickets = $this->output( $this->widget( 'tickets', $registry ), array( 'buttonLabel' => '<img src=x onerror=alert(1)>Book' ) );
		$this->assertStringContainsString( '">Book</a>', $tickets, 'The stored label is stripped again, not only escaped.' );
		$this->assertStringNotContainsString( 'onerror', $tickets );

		// An event that no longer exists: nothing for visitors (no wrapper, no title), a notice for editors.
		$this->assertSame( '', $this->output( $widget, array( 'title' => 'Speakers', 'eventId' => 999 ) ) );
		$this->editor = true;
		$notice       = $this->output( $widget, array( 'title' => 'Speakers', 'eventId' => 999 ) );
		$this->assertStringContainsString( 'odv-notice', $notice );
		$this->assertStringNotContainsString( 'odv-speakers__item', $notice );
	}

	public function test_internal_flags_never_reach_the_renderer_from_an_instance(): void {
		$seen = $this->seen_by_renderer(
			'register-button',
			array(
				Event_Page::IN_PAGE                => true,
				Event_Page::IN_PAGE_REGISTER_SHOWN => true,
				'eventSlug'                        => 'other',
				'className'                        => 'x',
				'label'                            => 'Join',
			)
		);

		$this->assertSame( array( 'label' => 'Join' ), $seen );
	}

	public function test_widget_with_nothing_to_show_prints_no_wrapper_and_no_title(): void {
		$widget = $this->widget( 'speakers', $this->registry( null, Event_View::GONE, array(), 42 ) );

		$this->assertSame( '', $this->output( $widget, array( 'title' => 'Our speakers', 'eventId' => 42 ) ) );

		// A block that renders '' for a live event (no speakers): nothing either.
		$payload             = $this->payload();
		$payload['speakers'] = array();
		$widget              = $this->widget( 'speakers', $this->registry( $payload, Event_View::GONE, array(), 42 ) );
		$this->assertSame( '', $this->output( $widget, array( 'title' => 'Our speakers' ) ) );

		// No event chosen and no default event: nothing for a visitor.
		$widget = $this->widget( 'speakers', $this->registry( $this->payload(), Event_View::GONE, array(), 0 ) );
		$this->assertSame( '', $this->output( $widget, array( 'title' => 'Our speakers' ) ) );
	}

	public function test_a_widget_for_a_cancelled_event_offers_no_register_link(): void {
		$cancelled = $this->payload( array( 'status' => 'cancelled', 'registration_open' => true ) );
		$repo      = $this->repo( array( 43 => $cancelled ) );

		foreach ( array( 'register-button', 'tickets', 'event-page' ) as $slug ) {
			$live = $this->output( $this->widget( $slug, $this->registry_over( $repo ) ), array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'sommet-tech-abidjan-2026/register', $live, $slug . ': the live event links to registration.' );

			$html = $this->output( $this->widget( $slug, $this->registry_over( $repo ) ), array( 'title' => 'Join us', 'eventId' => 43 ) );
			$this->assertStringNotContainsString( 'odv-register__button', $html, $slug );
			$this->assertStringNotContainsString( 'odv-tickets__register', $html, $slug );
			$this->assertStringNotContainsString( '/register', $html, $slug );
		}
	}

	public function test_title_is_escaped(): void {
		$widget = $this->widget( 'speakers' );

		// A stored title from an older version or a hand-edited option: sanitised again, then escaped.
		$html = $this->output( $widget, array( 'title' => 'Tom & Jerry <script>alert(1)</script>"><b>' ) );
		$this->assertStringStartsWith( self::ARGS['before_widget'] . '<h2 class="widget-title">Tom &amp; Jerry alert(1)&quot;&gt;</h2>', $html );
		$this->assertStringNotContainsString( '<script', $html );

		// A theme without title markup gets the default heading.
		$args = array( 'before_widget' => '<div>', 'after_widget' => '</div>' );
		$this->assertStringStartsWith( '<div><h2 class="widget-title">Speakers</h2><section class="odv-speakers"', $this->output( $widget, array( 'title' => 'Speakers' ), $args ) );

		// Whatever the widget_title filter returns is escaped too.
		Filters\expectApplied( 'widget_title' )->once()->with( 'Speakers', array( 'title' => 'Speakers' ), 'odavent_events_speakers' )->andReturn( '<i onclick="x()">Hi</i>' );
		$html = $this->output( $widget, array( 'title' => 'Speakers' ) );
		$this->assertStringContainsString( '<h2 class="widget-title">&lt;i onclick=&quot;x()&quot;&gt;Hi&lt;/i&gt;</h2>', $html );
	}

	// ---- form ----------------------------------------------------------------------------

	public function test_form_has_a_control_for_every_block_attribute_and_none_for_the_managed_ones(): void {
		$registry = $this->registry( $this->payload() );

		foreach ( array_keys( $registry->renderers() ) as $slug ) {
			$widget = $this->widget( $slug, $registry );
			$html   = $this->form( $widget, array() );
			$xpath  = new \DOMXPath( $this->dom( $html ) );

			$names = array();
			foreach ( $xpath->query( '//input[@name]|//select[@name]|//textarea[@name]' ) as $control ) {
				$names[] = $control->getAttribute( 'name' );
			}

			$expected = array( $widget->get_field_name( 'title' ), $widget->get_field_name( 'eventId' ) );
			foreach ( $registry->block_attributes( $slug ) as $name => $definition ) {
				if ( in_array( $name, Block_Registry::EDITOR_MANAGED, true ) ) {
					continue;
				}
				$expected[] = $widget->get_field_name( $name );

				$control = $xpath->query( '//*[@name="' . $widget->get_field_name( $name ) . '"]' )->item( 0 );
				$this->assertNotNull( $control, $slug . '.' . $name );
				$type = $definition['type'];
				if ( 'boolean' === $type ) {
					$this->assertSame( 'checkbox', $control->getAttribute( 'type' ), $slug . '.' . $name );
					$this->assertSame( (bool) $definition['default'], $control->hasAttribute( 'checked' ), $slug . '.' . $name . ': the default with no saved value' );
				} elseif ( 'integer' === $type ) {
					$this->assertSame( 'number', $control->getAttribute( 'type' ), $slug . '.' . $name );
					$this->assertSame( (string) $definition['default'], $control->getAttribute( 'value' ) );
					if ( isset( $definition['maximum'] ) ) {
						$this->assertSame( (string) $definition['maximum'], $control->getAttribute( 'max' ) );
					}
				} else {
					$this->assertSame( 'text', $control->getAttribute( 'type' ), $slug . '.' . $name );
				}

				// Labelled with the editor's label.
				$id    = $control->getAttribute( 'id' );
				$label = $xpath->query( '//label[@for="' . $id . '"]' )->item( 0 );
				$this->assertNotNull( $label, $slug . '.' . $name . ' has a label' );
				$this->assertSame( $registry->attribute_label( $slug, $name ), trim( $label->textContent ) );
			}

			$this->assertEqualsCanonicalizing( $expected, $names, $slug );
			foreach ( array( 'eventSlug', 'className', 'lock', 'metadata', Event_Page::IN_PAGE, Event_Page::IN_PAGE_REGISTER_SHOWN ) as $managed ) {
				$this->assertStringNotContainsString( '[' . $managed . ']', $html, $slug );
			}
		}

		$this->assertSame( 'Show company', $registry->attribute_label( 'speakers', 'showCompany' ) );
		$this->assertSame( 'Link text', $registry->attribute_label( 'find-registration', 'label' ) );
		$this->assertSame( 'Button text', $registry->attribute_label( 'register-button', 'label' ) );
	}

	public function test_event_field_is_a_list_when_the_connection_has_one_and_a_number_otherwise(): void {
		// No list (no key, rejected, unreachable): a number field, empty for the default event.
		$repo   = $this->repo();
		$widget = $this->widget( 'speakers', $this->registry_over( $repo ), $repo );
		$xpath  = new \DOMXPath( $this->dom( $this->form( $widget, array() ) ) );
		$field  = $xpath->query( '//input[@name="' . $widget->get_field_name( 'eventId' ) . '"]' )->item( 0 );
		$this->assertNotNull( $field );
		$this->assertSame( 'number', $field->getAttribute( 'type' ) );
		$this->assertSame( '', $field->getAttribute( 'value' ) );

		$xpath = new \DOMXPath( $this->dom( $this->form( $widget, array( 'eventId' => 42 ) ) ) );
		$this->assertSame( '42', $xpath->query( '//input[@name="' . $widget->get_field_name( 'eventId' ) . '"]' )->item( 0 )->getAttribute( 'value' ) );

		// With the cached editor list: a select, "default event" first, names escaped.
		$list   = array(
			array( 'id' => 42, 'slug' => 'summit', 'name' => 'Annual <b>Summit</b>', 'status' => 'published' ),
			array( 'id' => 7, 'slug' => 'gala', 'name' => 'Gala', 'status' => 'draft' ),
			array( 'id' => 'x', 'name' => 'Broken row' ),
			'not a row',
		);
		$repo   = $this->repo( array(), $list );
		$widget = $this->widget( 'speakers', $this->registry_over( $repo ), $repo );
		$html   = $this->form( $widget, array( 'eventId' => 7 ) );
		$xpath  = new \DOMXPath( $this->dom( $html ) );
		$select = $xpath->query( '//select[@name="' . $widget->get_field_name( 'eventId' ) . '"]' )->item( 0 );
		$this->assertNotNull( $select, $html );

		$options = array();
		foreach ( $xpath->query( './option', $select ) as $option ) {
			$options[] = array( $option->getAttribute( 'value' ), trim( $option->textContent ), $option->hasAttribute( 'selected' ) );
		}
		$this->assertSame( array( '', '42', '7' ), array_column( $options, 0 ) );
		$this->assertSame( 'Annual <b>Summit</b>', $options[1][1] );
		$this->assertStringNotContainsString( '<b>Summit', $html );
		$this->assertSame( array( false, false, true ), array_column( $options, 2 ) );

		// A saved event that is no longer in the list stays selected (saving must not switch events silently).
		$xpath  = new \DOMXPath( $this->dom( $this->form( $widget, array( 'eventId' => 999 ) ) ) );
		$values = array();
		foreach ( $xpath->query( '//select[@name="' . $widget->get_field_name( 'eventId' ) . '"]/option[@selected]' ) as $option ) {
			$values[] = $option->getAttribute( 'value' );
		}
		$this->assertSame( array( '999' ), $values );
	}

	public function test_the_event_list_is_asked_only_by_the_form_and_once(): void {
		$repo = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'list_for_editors' )->once()->andReturn( Result::ok( array() ) );
		$registry = $this->registry_over( $this->repo() );
		$this->widgets( $registry, $repo );

		foreach ( $this->registered as $widget ) {
			$this->form( $widget, array() );
		}
	}

	public function test_a_failing_event_list_degrades_to_the_number_field(): void {
		$repo = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'list_for_editors' )->andThrow( new \RuntimeException( 'boom' ) );
		$widget = $this->widget( 'venue', $this->registry_over( $this->repo() ), $repo );

		$xpath = new \DOMXPath( $this->dom( $this->form( $widget, array() ) ) );
		$this->assertSame( 'number', $xpath->query( '//input[@name="' . $widget->get_field_name( 'eventId' ) . '"]' )->item( 0 )->getAttribute( 'type' ) );
	}

	/**
	 * $values with its keys in $order (to compare without depending on the key order).
	 */
	private static function sorted_like( array $values, array $order ): array {
		$out = array();
		foreach ( $order as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$out[ $key ] = $values[ $key ];
			}
		}

		return $out + $values;
	}

	// ---- Odavent content placed in a widget area (block widgets, Text widgets) -----------------------

	/**
	 * @param array<string, mixed> $sidebars Sidebar => widget ids, as wp_get_sidebars_widgets() answers.
	 */
	private function sidebars( array $sidebars ): void {
		Functions\when( 'wp_get_sidebars_widgets' )->justReturn( $sidebars );
	}

	public function test_nothing_is_active_on_a_site_without_odavent_widgets(): void {
		$this->sidebars( array( 'sidebar-1' => array( 'search-2', 'block-3' ) ) );
		WpStubs::$options['widget_block'] = array( 3 => array( 'content' => '<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->' ) );

		$this->assertFalse( Widgets::any_active() );
	}

	public function test_an_odavent_block_in_a_block_widget_area_counts_as_active(): void {
		$this->sidebars( array( 'sidebar-1' => array( 'search-2', 'block-3' ) ) );
		WpStubs::$options['widget_block'] = array( 3 => array( 'content' => '<!-- wp:odavent-events/countdown {"eventId":42} /-->' ) );

		$this->assertTrue( Widgets::any_active() );
	}

	public function test_a_shortcode_in_a_text_widget_counts_as_active(): void {
		$this->sidebars( array( 'footer-1' => array( 'text-5' ) ) );
		WpStubs::$options['widget_text'] = array( 5 => array( 'text' => '<p>[odavent_tickets event="42"]</p>' ) );

		$this->assertTrue( Widgets::any_active() );
	}

	public function test_a_shortcode_in_a_custom_html_widget_counts_as_active(): void {
		$this->sidebars( array( 'footer-1' => array( 'custom_html-2' ) ) );
		WpStubs::$options['widget_custom_html'] = array( 2 => array( 'content' => '[odavent_countdown]' ) );

		$this->assertTrue( Widgets::any_active() );
	}

	public function test_odavent_content_in_an_inactive_or_orphaned_widget_is_ignored(): void {
		$this->sidebars(
			array(
				'wp_inactive_widgets'  => array( 'block-3' ),
				'orphaned_widgets_1'   => array( 'text-5' ),
				'sidebar-1'            => array(),
				'array_version'        => 3,
			)
		);
		WpStubs::$options['widget_block'] = array( 3 => array( 'content' => '<!-- wp:odavent-events/countdown /-->' ) );
		WpStubs::$options['widget_text']  = array( 5 => array( 'text' => '[odavent_countdown]' ) );

		$this->assertFalse( Widgets::any_active() );
	}

	public function test_a_widget_placed_in_a_sidebar_but_with_other_content_is_not_odavent(): void {
		$this->sidebars( array( 'sidebar-1' => array( 'block-4' ) ) );
		WpStubs::$options['widget_block'] = array(
			3 => array( 'content' => '<!-- wp:odavent-events/countdown /-->' ), // Exists but is not placed.
			4 => array( 'content' => '<p>Hello</p>' ),
		);

		$this->assertFalse( Widgets::any_active() );
	}

	public function test_malformed_widget_data_never_fatals_and_is_not_odavent(): void {
		foreach (
			array(
				'sidebars not an array' => array( 'sidebars' => 'junk', 'block' => array() ),
				'sidebar not an array'  => array( 'sidebars' => array( 'sidebar-1' => 'block-3' ), 'block' => array() ),
				'ids not strings'       => array( 'sidebars' => array( 'sidebar-1' => array( 3, null, array( 'block-3' ) ) ), 'block' => array() ),
				'odd ids'               => array( 'sidebars' => array( 'sidebar-1' => array( 'block-', 'block-x', 'block-3 ', "block-3\n", 'unknown-3', '' ) ), 'block' => array( 3 => array( 'content' => '[odavent_x]' ) ) ),
				'option not an array'   => array( 'sidebars' => array( 'sidebar-1' => array( 'block-3' ) ), 'block' => 'junk' ),
				'instance not an array' => array( 'sidebars' => array( 'sidebar-1' => array( 'block-3' ) ), 'block' => array( 3 => 'junk' ) ),
				'content not a string'  => array( 'sidebars' => array( 'sidebar-1' => array( 'block-3' ) ), 'block' => array( 3 => array( 'content' => array( '[odavent_x]' ) ) ) ),
				'content missing'       => array( 'sidebars' => array( 'sidebar-1' => array( 'block-3' ) ), 'block' => array( 3 => array() ) ),
				'instance missing'      => array( 'sidebars' => array( 'sidebar-1' => array( 'block-3' ) ), 'block' => array( 9 => array( 'content' => '[odavent_x]' ) ) ),
			) as $label => $case
		) {
			$this->sidebars( array( 'sidebar-1' => array() ) );
			Functions\when( 'wp_get_sidebars_widgets' )->justReturn( $case['sidebars'] );
			WpStubs::$options['widget_block'] = $case['block'];

			$this->assertFalse( Widgets::any_active(), $label );
		}
	}

	public function test_the_classic_widget_check_wins_without_reading_the_content_options(): void {
		Functions\when( 'is_active_widget' )->alias(
			static function ( $callback, $widget_id, $id_base ) {
				return 'odavent_events_speakers' === $id_base ? 'sidebar-1' : false;
			}
		);
		Functions\expect( 'wp_get_sidebars_widgets' )->never();

		$this->assertTrue( Widgets::any_active() );
	}
}

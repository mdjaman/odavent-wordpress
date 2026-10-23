<?php
namespace Odavent\Events\Tests\Unit\Shortcodes;

use Brain\Monkey\Functions;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Shortcodes\Shortcodes;
use Odavent\Events\Tests\Support\BlockTestCase;

class ShortcodesTest extends BlockTestCase {

	/** @var array<string, callable> add_shortcode( tag, callback ) calls. */
	private $registered = array();

	protected function setUp(): void {
		parent::setUp();
		$this->registered = array();

		Functions\when( 'add_shortcode' )->alias(
			function ( $tag, $callback ) {
				$this->registered[ $tag ] = $callback;
			}
		);
		// Close enough to WordPress for these tests: tags stripped, whitespace collapsed, trimmed.
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $value ) {
				$value = strip_tags( (string) $value );
				$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

				return trim( (string) $value );
			}
		);
		Functions\when( 'sanitize_title' )->alias(
			function ( $value ) {
				$value = strtolower( strip_tags( (string) $value ) );
				$value = preg_replace( '/[^a-z0-9_\-]+/', '-', $value );

				return trim( (string) $value, '-' );
			}
		);
	}

	private function shortcodes( ?Block_Registry $registry = null ): Shortcodes {
		return new Shortcodes( null !== $registry ? $registry : $this->registry( $this->payload() ) );
	}

	/**
	 * Attributes the renderer of a block receives through the shortcode layer.
	 *
	 * @return array[]
	 */
	private function captured( string $slug, $atts, int $default_event = 42 ): array {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array( 'annual-summit' => 42 ), $default_event );
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

		$out = ( new Shortcodes( $registry ) )->handle( $slug, $atts );
		$this->assertSame( '<p>seen</p>', $out );

		return $seen;
	}

	// ---- registration -----------------------------------------------------------

	public function test_every_block_gets_a_shortcode_named_after_its_slug(): void {
		$registry = $this->registry( $this->payload() );
		( new Shortcodes( $registry ) )->register();

		$expected = array();
		foreach ( array_keys( $registry->renderers() ) as $slug ) {
			$expected[] = 'odavent_' . str_replace( '-', '_', $slug );
		}

		$this->assertCount( 10, $expected );
		$this->assertEqualsCanonicalizing( $expected, array_keys( $this->registered ) );
		$this->assertEqualsCanonicalizing(
			array( 'odavent_event_header', 'odavent_register_button', 'odavent_tickets', 'odavent_find_registration', 'odavent_speakers', 'odavent_agenda', 'odavent_sponsors', 'odavent_countdown', 'odavent_venue', 'odavent_add_to_calendar' ),
			array_keys( $this->registered )
		);
	}

	public function test_a_registered_shortcode_renders_its_own_block_and_returns_a_string_for_any_atts(): void {
		( new Shortcodes( $this->registry( $this->payload(), Event_View::GONE, array(), 42 ) ) )->register();

		foreach ( array( '', null, array(), array( 'columns' => '2' ) ) as $atts ) {
			$out = call_user_func( $this->registered['odavent_speakers'], $atts, null, 'odavent_speakers' );
			$this->assertIsString( $out );
			$this->assertStringContainsString( 'odv-speakers', $out );
		}
		$this->assertSame( array(), array_filter( $this->enqueued_scripts ) );
	}

	public function test_each_shortcode_renders_the_same_html_as_its_block(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array( 'annual-summit' => 42 ), 0 );
		( new Shortcodes( $registry ) )->register();

		$cases = array(
			'speakers' => array( array( 'event' => 'annual-summit', 'columns' => '2', 'show_bio' => 'yes', 'class' => 'wide' ), array( 'eventSlug' => 'annual-summit', 'columns' => 2, 'showBio' => true, 'className' => 'wide' ) ),
			'tickets'  => array( array( 'event_id' => '42', 'show_availability' => 'true', 'button_label' => 'Buy' ), array( 'eventId' => 42, 'showAvailability' => true, 'buttonLabel' => 'Buy' ) ),
			'agenda'   => array( array( 'event_id' => '42', 'track_filter' => 'off' ), array( 'eventId' => 42, 'trackFilter' => false ) ),
			'venue'    => array( array( 'event_id' => '42' ), array( 'eventId' => 42 ) ),
		);

		foreach ( $cases as $slug => list( $atts, $block_atts ) ) {
			$tag = 'odavent_' . str_replace( '-', '_', $slug );
			$this->assertNotSame( '', $registry->render_block( $slug, $block_atts ), $slug );
			$this->assertSame( $registry->render_block( $slug, $block_atts ), call_user_func( $this->registered[ $tag ], $atts, '', $tag ), $slug );
		}
	}

	public function test_a_shortcode_for_an_unknown_event_renders_nothing(): void {
		$out = $this->shortcodes( $this->registry( $this->payload(), Event_View::GONE, array(), 0 ) )->handle( 'speakers', array( 'event' => 'nope' ) );
		$this->assertSame( '', $out );
	}

	public function test_the_default_event_is_used_when_no_event_is_given(): void {
		$seen = $this->captured( 'speakers', '' );
		$this->assertCount( 1, $seen );
		$this->assertSame( array( array( 42, '', false ) ), $this->repo_calls );
	}

	// ---- attribute mapping ------------------------------------------------------

	public function test_the_documented_example_maps_to_block_attributes(): void {
		$this->assertSame(
			array( 'eventSlug' => 'annual-summit', 'columns' => 2, 'showBio' => true ),
			$this->shortcodes()->to_block_attributes( 'speakers', array( 'event' => 'annual-summit', 'columns' => '2', 'show_bio' => 'yes' ) )
		);
	}

	public function test_event_id_is_a_positive_integer_or_dropped(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'eventId' => 12 ), $sc->to_block_attributes( 'speakers', array( 'event_id' => '12' ) ) );
		$this->assertSame( array( 'eventId' => 12 ), $sc->to_block_attributes( 'speakers', array( 'event_id' => ' 12 ' ) ) );
		foreach ( array( 'abc', '-3', '0', '', '1.5', '12abc', '99999999999999999999999' ) as $bad ) {
			$this->assertSame( array(), $sc->to_block_attributes( 'speakers', array( 'event_id' => $bad ) ), $bad );
		}
	}

	public function test_a_missing_or_blank_event_passes_no_id_and_no_slug(): void {
		$sc = $this->shortcodes();
		foreach ( array( array(), array( 'event' => '' ), array( 'event' => '   ' ), array( 'event' => '<>' ) ) as $atts ) {
			$out = $sc->to_block_attributes( 'speakers', $atts );
			$this->assertArrayNotHasKey( 'eventId', $out );
			$this->assertArrayNotHasKey( 'eventSlug', $out );
		}
	}

	public function test_the_event_slug_is_sanitised(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'eventSlug' => 'annual-summit' ), $sc->to_block_attributes( 'speakers', array( 'event' => 'Annual-Summit' ) ) );
		$out = $sc->to_block_attributes( 'speakers', array( 'event' => '<script>alert(1)</script>' ) );
		$this->assertMatchesRegularExpression( '/^[a-z0-9_-]*$/', $out['eventSlug'] ?? '' );
	}

	public function test_unknown_attributes_are_ignored(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'columns' => 3 ), $sc->to_block_attributes( 'speakers', array( 'columns' => '3', 'onclick' => 'x()', 'foo_bar' => '1', 'show_cover' => 'true', 'lock' => 'x', 'metadata' => 'x' ) ) );
	}

	public function test_camel_case_spellings_are_not_accepted_only_snake_case(): void {
		$this->assertSame( array(), $this->shortcodes()->to_block_attributes( 'speakers', array( 'showBio' => 'true', 'eventId' => '4', 'eventSlug' => 'x' ) ) );
	}

	public function test_attribute_names_are_matched_case_insensitively_like_wordpress_does(): void {
		$this->assertSame( array( 'showBio' => true ), $this->shortcodes()->to_block_attributes( 'speakers', array( 'SHOW_BIO' => 'true' ) ) );
	}

	public function test_snake_case_matches_every_block_json_attribute_name(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'speakerId' => 5 ), $sc->to_block_attributes( 'speakers', array( 'speaker_id' => '5' ) ) );
		$this->assertSame( array( 'labelLive' => 'On air' ), $sc->to_block_attributes( 'countdown', array( 'label_live' => 'On air' ) ) );
		$this->assertSame( array( 'labelEnded' => 'Done' ), $sc->to_block_attributes( 'countdown', array( 'label_ended' => 'Done' ) ) );
		$this->assertSame( array( 'ticketId' => 9 ), $sc->to_block_attributes( 'register-button', array( 'ticket_id' => '9' ) ) );
		$this->assertSame( array( 'showJsonLd' => false ), $sc->to_block_attributes( 'event-header', array( 'show_json_ld' => 'no' ) ) );
		$this->assertSame( array( 'groupByType' => false ), $sc->to_block_attributes( 'sponsors', array( 'group_by_type' => '0' ) ) );
	}

	public function test_an_attribute_of_another_block_is_unknown_here(): void {
		$this->assertSame( array(), $this->shortcodes()->to_block_attributes( 'venue', array( 'columns' => '2', 'show_bio' => 'true' ) ) );
		$this->assertSame( array( 'showMapLink' => false ), $this->shortcodes()->to_block_attributes( 'venue', array( 'show_map_link' => 'false' ) ) );
	}

	public function test_an_unknown_block_slug_gives_no_attributes(): void {
		$this->assertSame( array(), $this->shortcodes()->to_block_attributes( 'nope', array( 'columns' => '2', 'event_id' => '4' ) ) );
	}

	/**
	 * @dataProvider boolean_table
	 */
	public function test_boolean_casting( string $raw, ?bool $expected ): void {
		$out = $this->shortcodes()->to_block_attributes( 'speakers', array( 'show_bio' => $raw ) );
		if ( null === $expected ) {
			$this->assertArrayNotHasKey( 'showBio', $out, 'garbage is dropped so the default applies' );
		} else {
			$this->assertSame( array( 'showBio' => $expected ), $out );
		}
	}

	public function boolean_table(): array {
		return array(
			'true'       => array( 'true', true ),
			'TRUE'       => array( 'TRUE', true ),
			'True'       => array( 'True', true ),
			'1'          => array( '1', true ),
			'yes'        => array( 'yes', true ),
			'Yes'        => array( 'Yes', true ),
			'on'         => array( 'on', true ),
			'padded'     => array( ' on ', true ),
			'false'      => array( 'false', false ),
			'FALSE'      => array( 'FALSE', false ),
			'0'          => array( '0', false ),
			'no'         => array( 'no', false ),
			'NO'         => array( 'NO', false ),
			'off'        => array( 'off', false ),
			'empty'      => array( '', false ),
			'blank'      => array( '   ', false ),
			'garbage'    => array( 'maybe', null ),
			'2'          => array( '2', null ),
			'truee'      => array( 'truee', null ),
			'script'     => array( '<script>', null ),
		);
	}

	public function test_a_boolean_default_true_attribute_can_be_switched_off(): void {
		$this->assertSame( array( 'showCompany' => false ), $this->shortcodes()->to_block_attributes( 'speakers', array( 'show_company' => 'off' ) ) );
	}

	public function test_integer_casting(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'columns' => 4 ), $sc->to_block_attributes( 'speakers', array( 'columns' => ' 4 ' ) ) );
		$this->assertSame( array( 'columns' => 0 ), $sc->to_block_attributes( 'speakers', array( 'columns' => '0' ) ) );
		// Out of range is the renderer's business (it clamps); it is still a whole number.
		$this->assertSame( array( 'columns' => 99 ), $sc->to_block_attributes( 'speakers', array( 'columns' => '99' ) ) );
		foreach ( array( '4px', '-1', '1;DROP', '2.5', 'four', '', '0x10', '1e3', '99999999999999999999999' ) as $bad ) {
			$this->assertSame( array(), $sc->to_block_attributes( 'speakers', array( 'columns' => $bad ) ), $bad );
		}
	}

	public function test_string_attributes_are_sanitised(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'label' => 'x' ), $sc->to_block_attributes( 'register-button', array( 'label' => '<b>x</b>' ) ) );
		$this->assertSame( array( 'label' => 'Join us' ), $sc->to_block_attributes( 'register-button', array( 'label' => "  Join \n us  " ) ) );
		$this->assertSame( array( 'label' => '' ), $sc->to_block_attributes( 'register-button', array( 'label' => '' ) ) );
	}

	public function test_class_maps_to_class_name_on_every_block(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array( 'className' => 'a b' ), $sc->to_block_attributes( 'speakers', array( 'class' => 'a b' ) ) );
		$this->assertSame( array( 'className' => 'a b' ), $sc->to_block_attributes( 'venue', array( 'class' => '<i>a</i>   b' ) ) );
		$this->assertArrayNotHasKey( 'className', $sc->to_block_attributes( 'venue', array( 'class' => '' ) ) );
		$this->assertArrayNotHasKey( 'className', $sc->to_block_attributes( 'venue', array() ) );
	}

	public function test_atts_that_are_not_arrays_work(): void {
		$sc = $this->shortcodes();
		$this->assertSame( array(), $sc->to_block_attributes( 'speakers', '' ) );
		$this->assertSame( array(), $sc->to_block_attributes( 'speakers', null ) );
		$this->assertSame( array(), $sc->to_block_attributes( 'speakers', array() ) );
		$this->assertSame( array(), $sc->to_block_attributes( 'speakers', 'columns=2' ) );
		$this->assertSame( array(), $sc->to_block_attributes( 'speakers', 7 ) );
	}

	public function test_positional_and_non_scalar_values_are_ignored(): void {
		$out = $this->shortcodes()->to_block_attributes( 'speakers', array( 0 => 'positional', 'columns' => array( '2' ), 'show_bio' => new \stdClass(), 'limit' => null, 'speaker_id' => 3 ) );
		$this->assertSame( array( 'speakerId' => 3 ), $out );
	}

	public function test_a_huge_string_is_capped(): void {
		$out = $this->shortcodes()->to_block_attributes( 'register-button', array( 'label' => str_repeat( 'a', 100000 ) ) );
		$this->assertLessThanOrEqual( 500, strlen( $out['label'] ) );
		$this->assertGreaterThan( 0, strlen( $out['label'] ) );
	}

	// ---- hostile values never reach a renderer unsanitised ------------------------------

	public function test_hostile_values_reach_the_renderer_clean_or_not_at_all(): void {
		$seen = $this->captured(
			'speakers',
			array(
				'event_id' => '1;DROP TABLE',
				'columns'  => '1;DROP',
				'show_bio' => '<img src=x onerror=alert(1)>',
				'class'    => '"><script>x</script>',
				'limit'    => '2<script>',
			)
		);

		$this->assertCount( 1, $seen );
		$atts = $seen[0];
		$this->assertArrayNotHasKey( 'eventId', $atts );
		$this->assertArrayNotHasKey( 'columns', $atts );
		$this->assertArrayNotHasKey( 'showBio', $atts );
		$this->assertArrayNotHasKey( 'limit', $atts );
		foreach ( $atts as $value ) {
			$this->assertStringNotContainsString( '<', (string) $value );
		}
	}

	public function test_hostile_event_slug_with_a_slug_unknown_to_odavent_renders_nothing(): void {
		$registry = $this->registry( $this->payload(), Event_View::GONE, array(), 42 );
		$this->assertSame( '', ( new Shortcodes( $registry ) )->handle( 'speakers', array( 'event' => "x' OR '1'='1" ) ) );
		$this->assertSame( array(), $this->repo_calls );
	}

	public function test_handle_never_throws_even_if_the_registry_does(): void {
		$registry = \Mockery::mock( Block_Registry::class );
		$registry->shouldReceive( 'renderers' )->andReturn( array( 'speakers' => 'X' ) );
		$registry->shouldReceive( 'block_attributes' )->andThrow( new \RuntimeException( 'boom' ) );
		$registry->shouldReceive( 'render_block' )->andThrow( new \Error( 'boom' ) );

		$this->assertSame( '', ( new Shortcodes( $registry ) )->handle( 'speakers', array( 'columns' => '2' ) ) );
	}

	public function test_handle_always_returns_a_string(): void {
		$registry = \Mockery::mock( Block_Registry::class );
		$registry->shouldReceive( 'block_attributes' )->andReturn( array() );
		$registry->shouldReceive( 'render_block' )->andReturn( 'ok' );
		$this->assertSame( 'ok', ( new Shortcodes( $registry ) )->handle( 'speakers', null ) );
	}

	// ---- documentation and detection ------------------------------------------------------

	public function test_the_reference_lists_every_shortcode_with_snake_case_attributes(): void {
		$reference = $this->shortcodes()->reference();

		$this->assertCount( 10, $reference );
		$this->assertArrayHasKey( 'odavent_speakers', $reference );
		$speakers = $reference['odavent_speakers'];
		$this->assertSame( 'speakers', $speakers['block'] );
		$this->assertSame( 'integer', $speakers['attributes']['columns']['type'] );
		$this->assertSame( 3, $speakers['attributes']['columns']['default'] );
		$this->assertSame( 'boolean', $speakers['attributes']['show_bio']['type'] );
		$this->assertArrayHasKey( 'event', $speakers['attributes'] );
		$this->assertArrayHasKey( 'event_id', $speakers['attributes'] );
		$this->assertArrayHasKey( 'class', $speakers['attributes'] );
	}

	public function test_docs_list_every_shortcode_and_every_attribute(): void {
		$docs = (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/shortcodes.md' );
		$this->assertNotSame( '', $docs );

		foreach ( $this->shortcodes()->reference() as $tag => $entry ) {
			$this->assertStringContainsString( '[' . $tag, $docs, $tag . ' is missing from docs/shortcodes.md' );
			foreach ( array_keys( $entry['attributes'] ) as $attribute ) {
				$this->assertMatchesRegularExpression( '/`' . preg_quote( $attribute, '/' ) . '`/', $docs, $tag . ': ' . $attribute . ' is missing from docs/shortcodes.md' );
			}
		}

		// Every block.json attribute (not just the ones the reference reports) is in the docs in snake_case.
		foreach ( glob( dirname( __DIR__, 3 ) . '/blocks/*/block.json' ) as $file ) {
			$json = json_decode( (string) file_get_contents( $file ), true );
			foreach ( array_keys( $json['attributes'] ) as $attribute ) {
				$snake = 'eventSlug' === $attribute ? 'event' : strtolower( preg_replace( '/([A-Z])/', '_$1', $attribute ) );
				$this->assertStringContainsString( '`' . $snake . '`', $docs, $json['name'] . ': ' . $snake );
			}
		}
	}

	public function test_early_style_detection_recognises_every_odavent_shortcode(): void {
		Functions\when( 'is_singular' )->justReturn( true );

		foreach ( array_keys( $this->shortcodes()->reference() ) as $tag ) {
			Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'Hello [' . $tag . ' event="x"] bye' ) );
			$this->assertTrue( Block_Registry::page_has_odavent_content(), $tag );
		}

		Functions\when( 'get_post' )->justReturn( (object) array( 'post_content' => 'Hello [gallery] bye' ) );
		$this->assertFalse( Block_Registry::page_has_odavent_content() );
	}
}

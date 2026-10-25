<?php
namespace Odavent\Events\Blocks\Renderers;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Blocks\Lifecycle;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Template;

/**
 * The whole event page from one block: the chosen sections, each rendered by the existing block renderer,
 * in a fixed order, with a heading above the content sections.
 *
 * The registry handles everything around the page as for any block (which event, empty and draft views,
 * the lifecycle notice above it, assets, errors). The sections are called directly, never through
 * render_block(), so that notice is printed once for the page; each section gets the internal attribute
 * `inEventPage`, which tells Register, Tickets and Countdown not to repeat it (and the stale notice, which
 * this page prints once instead); when the Register section is shown, the tickets also leave out its status
 * and secure-payment lines. A section with nothing to show is dropped with its heading and wrapper,
 * and one that throws is dropped (editors see the error notice in its place) while the rest still render.
 */
final class Event_Page implements Renderer {

	/** Every section, in the order the page shows them (the `sections` attribute only turns them on or off). */
	const DEFAULT_SECTIONS = array( 'header', 'countdown', 'register', 'tickets', 'find-registration', 'speakers', 'agenda', 'sponsors', 'venue', 'calendar' );

	/** Section slug => the renderer of the matching block. */
	const SECTION_RENDERERS = array(
		'header'            => Event_Header::class,
		'countdown'         => Countdown::class,
		'register'          => Register_Button::class,
		'tickets'           => Tickets::class,
		'find-registration' => Find_Registration::class,
		'speakers'          => Speakers::class,
		'agenda'            => Agenda::class,
		'sponsors'          => Sponsors::class,
		'venue'             => Venue::class,
		'calendar'          => Add_To_Calendar::class,
	);

	/** The sections that get a heading; the others speak for themselves. */
	const HEADED_SECTIONS = array( 'tickets', 'speakers', 'agenda', 'sponsors', 'venue' );

	/** The attribute the page passes to its sections. Not in any block.json, and removed from block input by the registry. */
	const IN_PAGE = 'inEventPage';

	/**
	 * Passed to the tickets section when the Register section above it rendered something: its status line
	 * and secure-payment line are then already on the page. Internal too, removed from block input likewise.
	 */
	const IN_PAGE_REGISTER_SHOWN = 'inEventPageRegisterShown';

	/** Every internal attribute, for the registry to strip from block and shortcode input. */
	const INTERNAL_ATTRIBUTES = array( self::IN_PAGE, self::IN_PAGE_REGISTER_SHOWN );

	/** @var array<string, int> Section ids handed out in this request => how many times. */
	private static $ids = array();

	/** @var array<string, Renderer> */
	private $renderers = array();

	/**
	 * @param array<string, Renderer> $renderers Tests only: section slug => renderer to use instead of the built-in one.
	 */
	public function __construct( array $renderers = array() ) {
		foreach ( $renderers as $slug => $renderer ) {
			if ( is_string( $slug ) && $renderer instanceof Renderer ) {
				$this->renderers[ $slug ] = $renderer;
			}
		}
	}

	public function render( Event_View $view, array $atts ): string {
		$chosen   = self::parse_sections( self::raw_sections( $atts['sections'] ?? null ) );
		$event_id = Attributes::event_id( $view->event() );
		$labels   = self::section_labels();

		$sections       = array();
		$register_shown = false;
		foreach ( $chosen as $slug ) {
			$title = in_array( $slug, self::HEADED_SECTIONS, true ) ? $labels[ $slug ] : '';

			$failed = false;
			try {
				$html = $this->section_renderer( $slug )->render( $view, self::section_attributes( $slug, $atts, $register_shown ) );
			} catch ( \Throwable $e ) {
				self::log( $e );
				$html   = self::error_notice();
				$title  = '';
				$failed = true;
			}

			if ( '' === trim( $html ) ) {
				continue;
			}
			// Register comes before Tickets (fixed order): what it printed is known when Tickets renders.
			$register_shown = $register_shown || ( 'register' === $slug && ! $failed );

			$sections[] = array(
				'slug'  => $slug,
				'id'    => self::unique_id( 'odv-' . $slug . '-' . $event_id ),
				'title' => $title,
				'html'  => $html,
			);
		}

		// The stale notice stands in for the Register links, so it is shown only when the page offers them.
		$offers_registration = in_array( 'register', $chosen, true ) || in_array( 'tickets', $chosen, true );
		$stale_notice        = $offers_registration ? Lifecycle::stale_notice_html( $view ) : '';

		// Nothing to show, unless the stale notice explains why the Register links are missing.
		if ( array() === $sections && '' === $stale_notice ) {
			return '';
		}

		return Template::render(
			'event-page',
			array(
				'classes'       => Attributes::root_classes( 'odv-event-page', $atts ),
				'event_id'      => $event_id,
				'stale_notice'  => $stale_notice,
				'sections'      => $sections,
				'show_headings' => Attributes::flag( $atts, 'showHeadings', true ),
				'view'          => $view,
			)
		);
	}

	/**
	 * The sections to show from the `sections` attribute (a comma list): known slugs only, lower-cased,
	 * each once, in DEFAULT_SECTIONS order. Every section when the list is empty or names none of them.
	 *
	 * @param string $raw The attribute value, e.g. "agenda, speakers".
	 * @return string[]
	 */
	public static function parse_sections( string $raw ): array {
		$asked = array();
		foreach ( explode( ',', strtolower( $raw ) ) as $part ) {
			$asked[ trim( $part ) ] = true;
		}

		$sections = array();
		foreach ( self::DEFAULT_SECTIONS as $slug ) {
			if ( isset( $asked[ $slug ] ) ) {
				$sections[] = $slug;
			}
		}

		return array() === $sections ? self::DEFAULT_SECTIONS : $sections;
	}

	/**
	 * Section slug => translated name, in page order (the headings, and the section toggles in the editor).
	 *
	 * @return array<string, string>
	 */
	public static function section_labels(): array {
		return array(
			'header'            => __( 'Event header', 'odavent-events' ),
			'countdown'         => __( 'Countdown', 'odavent-events' ),
			'register'          => __( 'Register button', 'odavent-events' ),
			'tickets'           => __( 'Tickets', 'odavent-events' ),
			'find-registration' => __( 'Find my registration', 'odavent-events' ),
			'speakers'          => __( 'Speakers', 'odavent-events' ),
			'agenda'            => __( 'Agenda', 'odavent-events' ),
			'sponsors'          => __( 'Sponsors and partners', 'odavent-events' ),
			'venue'             => __( 'Venue', 'odavent-events' ),
			'calendar'          => __( 'Add to calendar', 'odavent-events' ),
		);
	}

	/**
	 * @internal Tests only: forgets the section ids handed out so far in this request.
	 */
	public static function reset_ids(): void {
		self::$ids = array();
	}

	/**
	 * The `sections` value as text. Block attributes are validated by WordPress, but a hand-written block
	 * comment may carry a list: its strings are joined, anything else counts as empty.
	 *
	 * @param mixed $value Attribute value.
	 */
	private static function raw_sections( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}

		return is_array( $value ) ? implode( ',', array_filter( $value, 'is_string' ) ) : '';
	}

	/**
	 * What one section renderer gets: the in-page marker and the settings the page passes on to it.
	 *
	 * @param string $slug           Section slug.
	 * @param array  $atts           The page's attributes.
	 * @param bool   $register_shown Whether the Register section rendered something above.
	 */
	private static function section_attributes( string $slug, array $atts, bool $register_shown ): array {
		$section = array( self::IN_PAGE => true );

		switch ( $slug ) {
			case 'speakers':
				$section['columns'] = Attributes::bounded_int( $atts, 'speakersColumns', Speakers::MIN_COLUMNS, Speakers::MAX_COLUMNS, 3 );
				$section['showBio'] = Attributes::flag( $atts, 'showSpeakerBio', false );
				break;
			case 'sponsors':
				$section['columns'] = Attributes::bounded_int( $atts, 'sponsorsColumns', Sponsors::MIN_COLUMNS, Sponsors::MAX_COLUMNS, 4 );
				break;
			case 'tickets':
				$section['showAvailability'] = Attributes::flag( $atts, 'showAvailability', false );
				if ( $register_shown ) {
					$section[ self::IN_PAGE_REGISTER_SHOWN ] = true;
				}
				break;
		}

		return $section;
	}

	private function section_renderer( string $slug ): Renderer {
		if ( ! isset( $this->renderers[ $slug ] ) ) {
			$class                    = self::SECTION_RENDERERS[ $slug ];
			$this->renderers[ $slug ] = new $class();
		}

		return $this->renderers[ $slug ];
	}

	/**
	 * An id not handed out yet in this request: the base, then base-2, base-3... (two event pages for the
	 * same event on one page keep their anchors unique).
	 *
	 * @param string $base Id built from known slugs and an integer.
	 */
	private static function unique_id( string $base ): string {
		$count              = ( self::$ids[ $base ] ?? 0 ) + 1;
		self::$ids[ $base ] = $count;

		return 1 === $count ? $base : $base . '-' . $count;
	}

	/**
	 * What replaces a section that failed: the registry's error notice for editors, nothing for visitors
	 * (and nothing at all if even the notice fails).
	 */
	private static function error_notice(): string {
		try {
			return Block_Registry::user_can_preview() ? Lifecycle::render_notice( 'error', __( 'This Odavent block could not be displayed. Turn on WP_DEBUG to log the error.', 'odavent-events' ) ) : '';
		} catch ( \Throwable $ignored ) {
			return '';
		}
	}

	private static function log( \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Odavent Events: event page section failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

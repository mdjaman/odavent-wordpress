<?php
namespace Odavent\Events\Blocks;

use Odavent\Events\Blocks\Renderers\Event_Header;
use Odavent\Events\Blocks\Renderers\Find_Registration;
use Odavent\Events\Blocks\Renderers\Register_Button;
use Odavent\Events\Blocks\Renderers\Tickets;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Assets;
use Odavent\Events\Settings\Options;

/**
 * The Odavent blocks: registration with WordPress, the editor script, and the single render path that
 * blocks and shortcodes share.
 *
 * render_block() decides everything around a block: which event (attribute id, then slug, then the
 * default event), whether there is anything to show (visitors get nothing for a deleted, unreachable or
 * draft event; editors get a notice), the lifecycle notice above the header and Register blocks, the
 * stylesheet, and that no error ever reaches the page.
 */
class Block_Registry {

	const NAMESPACE_PREFIX = 'odavent-events/';
	const CATEGORY         = 'odavent-events';
	const EDITOR_HANDLE    = 'odavent-events-editor';
	const EDITOR_DEPS      = array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-api-fetch', 'wp-i18n' );

	/** Blocks that carry the lifecycle notice (ended, paused, cancelled, draft preview). */
	const NOTICE_BLOCKS = array( 'event-header', 'register-button' );

	/** Attributes the editor handles itself (event picker, core class name field). */
	const EDITOR_MANAGED = array( 'eventId', 'eventSlug', 'className', 'lock', 'metadata' );

	/**
	 * Block slug => renderer class. Later blocks are added here.
	 *
	 * @var array<string, string>
	 */
	private const RENDERERS = array(
		'event-header'      => Event_Header::class,
		'register-button'   => Register_Button::class,
		'tickets'           => Tickets::class,
		'find-registration' => Find_Registration::class,
	);

	/** @var Event_Repository */
	private $repository;

	/** @var Options */
	private $options;

	/** @var Assets */
	private $assets;

	/** @var string Directory holding {slug}/block.json. */
	private $blocks_dir;

	/** @var array<string, Renderer> */
	private $instances = array();

	/** @var bool */
	private $registered = false;

	/** @var bool */
	private $metadata_added = false;

	/** @var array<string, array|null> Decoded block.json per path (read once per request). */
	private $json_cache = array();

	/**
	 * @param Event_Repository $repository Event source.
	 * @param Options          $options    Settings (default event, stylesheet switch).
	 * @param Assets|null      $assets     Stylesheet loader (built from $options when null).
	 * @param string|null      $blocks_dir Tests only: where the block.json folders are.
	 */
	public function __construct( Event_Repository $repository, Options $options, ?Assets $assets = null, ?string $blocks_dir = null ) {
		$this->repository = $repository;
		$this->options    = $options;
		$this->assets     = null !== $assets ? $assets : new Assets( $options );
		$this->blocks_dir = null !== $blocks_dir ? rtrim( $blocks_dir, '/\\' ) : dirname( __DIR__, 2 ) . '/blocks';
	}

	/**
	 * Block slug => renderer class name. Shortcodes use the same map.
	 *
	 * @return array<string, string>
	 */
	public function renderers(): array {
		return self::RENDERERS;
	}

	/**
	 * @internal Tests only: replaces the renderer of one block.
	 *
	 * @param string   $slug     Block slug.
	 * @param Renderer $renderer Renderer to use.
	 */
	public function use_renderer( string $slug, Renderer $renderer ): void {
		$this->instances[ $slug ] = $renderer;
	}

	// ---- WordPress registration ---------------------------------------------------

	/**
	 * Registers the editor script and every block type. Runs on `init`, after the stylesheet handle is
	 * registered (block.json names it as editorStyle). The editor metadata is attached later, only in the
	 * block editor (add_editor_metadata() on `enqueue_block_editor_assets`).
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		wp_register_script(
			self::EDITOR_HANDLE,
			plugins_url( 'assets/js/editor.js', ODAVENT_EVENTS_FILE ),
			self::EDITOR_DEPS,
			ODAVENT_EVENTS_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( self::EDITOR_HANDLE, 'odavent-events', ODAVENT_EVENTS_DIR . 'languages' );
		}

		foreach ( $this->block_files() as $file ) {
			$name = $this->block_name( $file );
			if ( '' === $name ) {
				continue;
			}

			register_block_type(
				dirname( $file ),
				array(
					'render_callback' => function ( $attributes = array() ) use ( $name ) {
						return $this->render_block( $name, is_array( $attributes ) ? $attributes : array() );
					},
				)
			);
		}
	}

	/**
	 * `enqueue_block_editor_assets`: prints `window.odaventEventsBlocks` before editor.js, once. Encoded with
	 * JSON_HEX_TAG | JSON_HEX_AMP so no title or description can close the script element.
	 */
	public function add_editor_metadata(): void {
		if ( $this->metadata_added ) {
			return;
		}
		$this->metadata_added = true;

		$json = wp_json_encode( $this->editor_metadata(), JSON_HEX_TAG | JSON_HEX_AMP );
		if ( is_string( $json ) ) {
			wp_add_inline_script( self::EDITOR_HANDLE, 'window.odaventEventsBlocks = ' . $json . ';', 'before' );
		}
	}

	/**
	 * `block_categories_all` filter: adds the "Odavent" category once.
	 *
	 * @param mixed $categories Categories from WordPress (passed through untouched if not an array).
	 * @return mixed
	 */
	public function add_category( $categories ) {
		if ( ! is_array( $categories ) ) {
			return $categories;
		}

		foreach ( $categories as $category ) {
			if ( is_array( $category ) && self::CATEGORY === ( $category['slug'] ?? null ) ) {
				return $categories;
			}
		}

		$categories[] = array(
			'slug'  => self::CATEGORY,
			'title' => __( 'Odavent', 'odavent-events' ),
			'icon'  => null,
		);

		return $categories;
	}

	/**
	 * What editor.js needs to register each block: name, title, description, icon, attributes, supports
	 * and a translated label per attribute.
	 *
	 * @return array[]
	 */
	public function editor_metadata(): array {
		$meta = array();
		foreach ( $this->block_files() as $file ) {
			$json = $this->read_json( $file );
			if ( null === $json || '' === $this->block_name( $file ) ) {
				continue;
			}

			$attributes = isset( $json['attributes'] ) && is_array( $json['attributes'] ) ? $json['attributes'] : array();
			$labels     = array();
			foreach ( array_keys( $attributes ) as $attribute ) {
				$labels[ $attribute ] = self::label( (string) $attribute, substr( $json['name'], strlen( self::NAMESPACE_PREFIX ) ) );
			}

			// Title and description from the registered block type: WordPress translated them from block.json.
			$registered  = class_exists( 'WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance()->get_registered( $json['name'] ) : null;
			$title       = is_object( $registered ) && isset( $registered->title ) && is_string( $registered->title ) && '' !== $registered->title ? $registered->title : ( isset( $json['title'] ) && is_string( $json['title'] ) ? $json['title'] : $json['name'] );
			$description = is_object( $registered ) && isset( $registered->description ) && is_string( $registered->description ) ? $registered->description : ( isset( $json['description'] ) && is_string( $json['description'] ) ? $json['description'] : '' );

			$meta[] = array(
				'name'        => $json['name'],
				'title'       => $title,
				'description' => $description,
				'icon'        => isset( $json['icon'] ) && is_string( $json['icon'] ) ? $json['icon'] : 'calendar-alt',
				'category'    => self::CATEGORY,
				'attributes'  => $attributes,
				'supports'    => isset( $json['supports'] ) && is_array( $json['supports'] ) ? $json['supports'] : array(),
				'labels'      => $labels,
				'managed'     => self::EDITOR_MANAGED,
			);
		}

		return $meta;
	}

	// ---- rendering ------------------------------------------------------------------

	/**
	 * The single render path for blocks and shortcodes. Never throws.
	 *
	 * @param string $name       Block name (`odavent-events/tickets`) or slug (`tickets`).
	 * @param array  $attributes Block attributes.
	 */
	public function render_block( string $name, array $attributes ): string {
		$editor = false;

		try {
			$editor   = self::user_can_preview();
			$slug     = 0 === strpos( $name, self::NAMESPACE_PREFIX ) ? substr( $name, strlen( self::NAMESPACE_PREFIX ) ) : $name;
			$renderer = $this->renderer( $slug );
			if ( null === $renderer ) {
				return $this->editor_notice( $editor, 'error', __( 'This Odavent block is not available in this version of the plugin.', 'odavent-events' ) );
			}

			$event_id = $this->resolve_event_id( $attributes );
			if ( null === $event_id ) {
				return $this->editor_notice( $editor, 'choose', __( 'Choose an event for this Odavent block in the block settings, or set a default event under Settings > Odavent Events.', 'odavent-events' ) );
			}

			$view = $this->repository->get( $event_id, Attributes::text( $attributes, 'eventSlug' ), $editor );
			if ( ! $view->has_content() ) {
				return $this->editor_notice( $editor, self::empty_kind( $view ), self::empty_message( $view ) );
			}

			$html = $renderer->render( $view, $attributes );
			if ( in_array( $slug, self::NOTICE_BLOCKS, true ) ) {
				$html = Lifecycle::notice_html( $view ) . $html;
			}

			if ( '' !== $html ) {
				$this->assets->enqueue_styles();
			}

			return $html;
		} catch ( \Throwable $e ) {
			self::log( $e );

			try {
				return $this->editor_notice( $editor, 'error', __( 'This Odavent block could not be displayed. Turn on WP_DEBUG to log the error.', 'odavent-events' ) );
			} catch ( \Throwable $ignored ) {
				return '';
			}
		}
	}

	/**
	 * Event id from the attributes: `eventId` (> 0), else `eventSlug` resolved through Odavent, else the
	 * default event from the settings. A slug that does not resolve gives null: never another event.
	 *
	 * @param array $attributes Block attributes.
	 */
	private function resolve_event_id( array $attributes ): ?int {
		$id = Attributes::positive_int( $attributes, 'eventId' );
		if ( $id > 0 ) {
			return $id;
		}

		$slug = Attributes::text( $attributes, 'eventSlug' );
		if ( '' !== $slug ) {
			$resolved = $this->repository->resolve_id( $slug );

			return null !== $resolved && $resolved > 0 ? $resolved : null;
		}

		$default = $this->options->default_event_id();

		return $default > 0 ? $default : null;
	}

	private function renderer( string $slug ): ?Renderer {
		if ( isset( $this->instances[ $slug ] ) ) {
			return $this->instances[ $slug ];
		}

		$map = $this->renderers();
		if ( ! isset( $map[ $slug ] ) ) {
			return null;
		}

		$class    = $map[ $slug ];
		$instance = new $class();
		if ( ! $instance instanceof Renderer ) {
			return null;
		}

		$this->instances[ $slug ] = $instance;

		return $instance;
	}

	/**
	 * A notice for users who can edit posts; '' for everyone else.
	 */
	private function editor_notice( bool $editor, string $kind, string $message ): string {
		if ( ! $editor ) {
			return '';
		}

		$html = Lifecycle::render_notice( $kind, $message );
		if ( '' !== $html ) {
			$this->assets->enqueue_styles();
		}

		return $html;
	}

	private static function empty_kind( Event_View $view ): string {
		return Event_View::DRAFT === $view->state() ? 'draft' : 'unavailable';
	}

	private static function empty_message( Event_View $view ): string {
		switch ( $view->state() ) {
			case Event_View::GONE:
				return __( 'This Odavent event was not found or is no longer published. Choose another event in the block settings. Visitors see nothing here.', 'odavent-events' );
			case Event_View::DRAFT:
				return __( 'This event is a draft on Odavent. Visitors see nothing here until it is published.', 'odavent-events' );
			default:
				return __( 'Odavent could not be reached and there is no saved copy of this event yet. Visitors see nothing here until it is back.', 'odavent-events' );
		}
	}

	// ---- page-level hooks ---------------------------------------------------------------

	/**
	 * `template_redirect`: an editor viewing a page with Odavent content may see a draft preview, so the
	 * response must not be cached. Headers have to go out here: by the time a block renders they are sent.
	 */
	public function prevent_preview_caching(): void {
		if ( ! self::user_can_preview() || ! self::page_has_odavent_content() ) {
			return;
		}

		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/**
	 * `wp_enqueue_scripts`: load the stylesheet in the head on pages that contain Odavent content, so the
	 * blocks do not flash unstyled. Rendering still enqueues it as a fallback (widgets, templates).
	 */
	public function enqueue_early_styles(): void {
		if ( self::page_has_odavent_content() ) {
			$this->assets->enqueue_styles();
		}
	}

	/**
	 * Whether the current singular post contains an Odavent block or shortcode. A cheap string check.
	 *
	 * Filter `odavent_events_page_has_content` (bool $has, object|null $post) to cover content the check
	 * cannot see (synced patterns, template parts, widgets).
	 */
	public static function page_has_odavent_content(): bool {
		$post = null;
		$has  = false;

		if ( is_singular() ) {
			$post    = get_post();
			$content = is_object( $post ) && isset( $post->post_content ) && is_string( $post->post_content ) ? $post->post_content : '';
			$has     = false !== strpos( $content, '<!-- wp:' . self::NAMESPACE_PREFIX ) || false !== strpos( $content, '[odavent_' );
		}

		return (bool) apply_filters( 'odavent_events_page_has_content', $has, $post );
	}

	// ---- helpers ----------------------------------------------------------------------

	/**
	 * The capability that sees draft previews and editor notices, skips page caching on pages with Odavent
	 * content, and may list events in the editor. Filter `odavent_events_preview_capability` (default
	 * `edit_posts`); anything but a non-blank string falls back to the default.
	 */
	public static function preview_capability(): string {
		$capability = apply_filters( 'odavent_events_preview_capability', 'edit_posts' );

		return is_string( $capability ) && '' !== trim( $capability ) ? trim( $capability ) : 'edit_posts';
	}

	/**
	 * Whether the current user has the preview capability.
	 */
	public static function user_can_preview(): bool {
		return (bool) current_user_can( self::preview_capability() );
	}

	/**
	 * @return string[] Sorted block.json paths.
	 */
	private function block_files(): array {
		$files = glob( $this->blocks_dir . '/*/block.json' );
		if ( ! is_array( $files ) ) {
			return array();
		}
		sort( $files );

		return $files;
	}

	/**
	 * The block's name when its block.json is readable and in the plugin namespace, else ''.
	 */
	private function block_name( string $file ): string {
		$json = $this->read_json( $file );
		$name = is_array( $json ) ? ( $json['name'] ?? null ) : null;

		return is_string( $name ) && 0 === strpos( $name, self::NAMESPACE_PREFIX ) ? $name : '';
	}

	private function read_json( string $file ): ?array {
		if ( ! array_key_exists( $file, $this->json_cache ) ) {
			$raw  = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = is_string( $raw ) ? json_decode( $raw, true ) : null;

			$this->json_cache[ $file ] = is_array( $json ) ? $json : null;
		}

		return $this->json_cache[ $file ];
	}

	/**
	 * Inspector label for an attribute.
	 *
	 * @param string $attribute Attribute name.
	 * @param string $block     Block slug (a "{slug}.{attribute}" entry wins over the plain one).
	 */
	private static function label( string $attribute, string $block ): string {
		$labels = array(
			'find-registration.label' => __( 'Link text', 'odavent-events' ),
			'eventId'          => __( 'Event', 'odavent-events' ),
			'eventSlug'        => __( 'Event slug', 'odavent-events' ),
			'showCover'        => __( 'Show cover image', 'odavent-events' ),
			'showLogo'         => __( 'Show logo', 'odavent-events' ),
			'showDescription'  => __( 'Show description', 'odavent-events' ),
			'showVenue'        => __( 'Show venue', 'odavent-events' ),
			'showJsonLd'       => __( 'Add structured data for search engines', 'odavent-events' ),
			'label'            => __( 'Button text', 'odavent-events' ),
			'ticketId'         => __( 'Preselect ticket (ID, 0 for none)', 'odavent-events' ),
			'showAvailability' => __( 'Show remaining places', 'odavent-events' ),
			'buttonLabel'      => __( 'Button text', 'odavent-events' ),
		);

		foreach ( array( $block . '.' . $attribute, $attribute ) as $key ) {
			if ( isset( $labels[ $key ] ) ) {
				return $labels[ $key ];
			}
		}

		// "showSpeakerPhotos" -> "Show speaker photos".
		return ucfirst( strtolower( trim( (string) preg_replace( '/([A-Z])/', ' $1', $attribute ) ) ) );
	}

	private static function log( \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Odavent Events: block render failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

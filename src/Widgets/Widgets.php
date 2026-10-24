<?php
namespace Odavent\Events\Widgets;

use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Shortcodes\Shortcodes;

/**
 * One classic widget per block, for themes with widget areas (and, through WordPress's Legacy Widget block,
 * block-based widget areas).
 *
 * Like the shortcodes, the widgets are generated: one Block_Widget instance per slug in
 * Block_Registry::renderers(), with a control per block.json attribute, so a new block or attribute gets
 * its widget with no change here. A widget instance is a set of block attributes (plus `title`) that goes
 * through the shortcode casting (Shortcodes::to_block_attributes()) and the block.json range on save AND
 * again on render: an instance saved by another version, or edited by hand, is not trusted.
 *
 * Id base: `odavent_events_` + the block slug with `-` replaced by `_` (`add-to-calendar` ->
 * `odavent_events_add_to_calendar`).
 */
final class Widgets {

	const ID_PREFIX = 'odavent_events_';
	const CLASSNAME = 'odavent_events_widget';

	/** Block attribute types a widget has a control for (the same as the shortcodes accept). */
	const TYPES = array( 'boolean', 'integer', 'number', 'string' );

	/** @var Block_Registry */
	private $blocks;

	/** @var Shortcodes */
	private $shortcodes;

	/** @var Event_Repository|null */
	private $repository;

	/** @var array<int, string>|false|null Editor event list, id => name: null not asked yet, false unavailable. */
	private $events = null;

	/**
	 * @param Block_Registry        $blocks     Blocks and their single render path.
	 * @param Shortcodes            $shortcodes The shortcode casting rules.
	 * @param Event_Repository|null $repository Source of the event list in the form (none: a number field).
	 */
	public function __construct( Block_Registry $blocks, Shortcodes $shortcodes, ?Event_Repository $repository = null ) {
		$this->blocks     = $blocks;
		$this->shortcodes = $shortcodes;
		$this->repository = $repository;
	}

	/**
	 * `widgets_init`: registers a widget for every block.
	 */
	public function register(): void {
		foreach ( array_keys( $this->blocks->renderers() ) as $slug ) {
			register_widget( new Block_Widget( (string) $slug, $this, $this->blocks ) );
		}
	}

	/**
	 * Every widget id base (`odavent_events_speakers`, ...), for code that asks whether a widget is active.
	 *
	 * @return string[]
	 */
	public static function id_bases(): array {
		return array_map( array( self::class, 'id_base' ), Block_Registry::slugs() );
	}

	/**
	 * Whether any Odavent widget sits in a sidebar (inactive ones do not count). A widget is not post content,
	 * so the page cache purge, the preview no-cache headers and the early stylesheet ask this separately.
	 */
	public static function any_active(): bool {
		foreach ( self::id_bases() as $id_base ) {
			if ( is_active_widget( false, false, $id_base, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Widget id base for a block slug.
	 *
	 * @param string $slug Block slug.
	 */
	public static function id_base( string $slug ): string {
		return self::ID_PREFIX . str_replace( '-', '_', $slug );
	}

	/**
	 * The attributes a widget has a control for: block.json attributes of a supported type, without the ones
	 * the editor manages (the event is the widget's own field; class name and lock do not apply).
	 *
	 * @param string $slug Block slug.
	 * @return array<string, array> Attribute name => block.json definition.
	 */
	public function controls( string $slug ): array {
		$controls = array();
		foreach ( $this->blocks->block_attributes( $slug ) as $name => $definition ) {
			$type = isset( $definition['type'] ) && is_string( $definition['type'] ) ? $definition['type'] : '';
			if ( ! in_array( $name, Block_Registry::EDITOR_MANAGED, true ) && in_array( $type, self::TYPES, true ) ) {
				$controls[ $name ] = $definition;
			}
		}

		return $controls;
	}

	/**
	 * A widget instance -> block attributes: only `eventId` and the attributes in controls(), cast by the
	 * shortcode rules, numbers clamped to the block.json range, values outside a block.json `enum` dropped.
	 * A dropped or missing value is simply absent, so the block's default applies.
	 *
	 * @param string $slug      Block slug.
	 * @param mixed  $instance  Submitted form values or a stored instance (anything: it is not trusted).
	 * @param bool   $submitted True for a form submission: a checkbox the browser did not send is false.
	 * @return array<string, mixed>
	 */
	public function sanitize_instance( string $slug, $instance, bool $submitted ): array {
		if ( ! is_array( $instance ) ) {
			$instance = array();
		}

		$controls = $this->controls( $slug );

		// In the shortcode spelling (snake_case) so the shortcode layer casts it; eventId becomes event_id.
		$atts = array();
		foreach ( array_merge( array( 'eventId' => array() ), $controls ) as $name => $definition ) {
			$key = strtolower( (string) preg_replace( '/([A-Z])/', '_$1', $name ) );
			if ( array_key_exists( $name, $instance ) ) {
				$atts[ $key ] = $instance[ $name ];
			} elseif ( $submitted && 'boolean' === ( $definition['type'] ?? '' ) ) {
				$atts[ $key ] = false;
			}
		}

		$clean = $this->shortcodes->to_block_attributes( $slug, $atts );

		foreach ( $controls as $name => $definition ) {
			if ( ! array_key_exists( $name, $clean ) ) {
				continue;
			}

			$value = $clean[ $name ];
			if ( is_int( $value ) || is_float( $value ) ) {
				if ( isset( $definition['minimum'] ) && is_numeric( $definition['minimum'] ) && $value < $definition['minimum'] ) {
					$value = $definition['minimum'] + 0;
				}
				if ( isset( $definition['maximum'] ) && is_numeric( $definition['maximum'] ) && $value > $definition['maximum'] ) {
					$value = $definition['maximum'] + 0;
				}
				$clean[ $name ] = 'integer' === $definition['type'] ? (int) $value : $value;
			}

			if ( isset( $definition['enum'] ) && is_array( $definition['enum'] ) && ! in_array( $clean[ $name ], $definition['enum'], true ) ) {
				unset( $clean[ $name ] );
			}
		}

		return $clean;
	}

	/**
	 * The events for the form's event field, id => name, from the editor list (cached by the repository,
	 * asked once per request); null when there is none (no key, rejected key, Odavent unreachable).
	 *
	 * @return array<int, string>|null
	 */
	public function event_choices(): ?array {
		if ( null === $this->events ) {
			$this->events = false;

			try {
				$result = null !== $this->repository ? $this->repository->list_for_editors() : null;
				if ( null !== $result && $result->ok && is_array( $result->data ) ) {
					$events = array();
					foreach ( $result->data as $row ) {
						if ( is_array( $row ) && is_int( $row['id'] ?? null ) && $row['id'] > 0 && is_string( $row['name'] ?? null ) ) {
							$events[ $row['id'] ] = $row['name'];
						}
					}
					$this->events = $events;
				}
			} catch ( \Throwable $e ) {
				$this->events = false;
			}
		}

		return false === $this->events ? null : $this->events;
	}
}

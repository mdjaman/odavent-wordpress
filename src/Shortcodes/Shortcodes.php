<?php
namespace Odavent\Events\Shortcodes;

use Odavent\Events\Blocks\Block_Registry;

/**
 * One shortcode per block, for sites and page builders without the block editor.
 *
 * A shortcode is a thin spelling of the block: its attributes are turned into block attributes and handed
 * to Block_Registry::render_block(), the same render path the block uses, so the output is identical. Both
 * the list of shortcodes (Block_Registry::renderers()) and the accepted attributes (each block's
 * block.json) are derived at runtime, so a new block or attribute gets its shortcode with no change here.
 *
 * Naming: `odavent_` + the block slug with `-` replaced by `_` (`add-to-calendar` -> `[odavent_add_to_calendar]`).
 * Attributes: `event` (slug), `event_id`, `class`, and every block attribute in snake_case
 * (`showBio` -> `show_bio`). Values are cast by the type block.json declares:
 *
 * - boolean: true/false, 1/0, yes/no, on/off (any case); anything else is dropped, so the default applies;
 * - integer: digits only; a value outside the block's range is passed on (the renderer clamps it), text is dropped;
 * - number: numeric;
 * - string: sanitize_text_field(), at most 500 characters.
 *
 * Unknown attributes are ignored. With no `event` and no `event_id` the default event from the settings is
 * used. reference() returns the table (shortcode -> block -> attributes); docs/shortcodes.md prints it and a
 * test keeps the two in step.
 */
class Shortcodes {

	const PREFIX     = 'odavent_';
	const MAX_STRING = 500;

	/** @var Block_Registry */
	private $blocks;

	/** @var array<string, array<string, array{0: string, 1: string}>> Per block slug: shortcode attribute => [block attribute, type]. */
	private $maps = array();

	public function __construct( Block_Registry $blocks ) {
		$this->blocks = $blocks;
	}

	/**
	 * `init`: registers a shortcode for every block.
	 */
	public function register(): void {
		foreach ( array_keys( $this->blocks->renderers() ) as $slug ) {
			$slug = (string) $slug;

			add_shortcode(
				self::tag( $slug ),
				function ( $atts = array(), $content = null, $tag = '' ) use ( $slug ) {
					return $this->handle( $slug, $atts, $content, is_string( $tag ) ? $tag : '' );
				}
			);
		}
	}

	/**
	 * Renders one block for a shortcode call. Always returns a string; never throws.
	 *
	 * @param string $slug    Block slug.
	 * @param mixed  $atts    Shortcode attributes ('' when none were given).
	 * @param mixed  $content Enclosed content (unused).
	 * @param string $tag     Shortcode tag (unused).
	 */
	public function handle( string $slug, $atts = array(), $content = null, $tag = '' ): string {
		try {
			$html = $this->blocks->render_block( $slug, $this->to_block_attributes( $slug, $atts ) );

			return is_string( $html ) ? $html : '';
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( 'Odavent Events: shortcode failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}

			return '';
		}
	}

	/**
	 * Shortcode attributes -> block attributes (only the ones the block declares, cast and sanitised).
	 *
	 * @param string $slug Block slug.
	 * @param mixed  $atts Shortcode attributes.
	 * @return array<string, mixed>
	 */
	public function to_block_attributes( string $slug, $atts ): array {
		if ( ! is_array( $atts ) || ! isset( $this->blocks->renderers()[ $slug ] ) ) {
			return array();
		}

		$input = array();
		foreach ( $atts as $key => $value ) {
			if ( is_string( $key ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) || is_bool( $value ) ) ) {
				$input[ strtolower( $key ) ] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}
		}

		$out = array();

		if ( isset( $input['event_id'] ) && preg_match( '/^\s*([0-9]{1,18})\s*\z/', $input['event_id'], $m ) && (int) $m[1] > 0 ) {
			$out['eventId'] = (int) $m[1];
		}

		if ( isset( $input['event'] ) ) {
			$event = sanitize_title( self::limit( $input['event'] ) );
			if ( is_string( $event ) && '' !== $event ) {
				$out['eventSlug'] = $event;
			}
		}

		if ( isset( $input['class'] ) ) {
			$class = sanitize_text_field( self::limit( $input['class'] ) );
			if ( is_string( $class ) && '' !== $class ) {
				$out['className'] = $class;
			}
		}

		foreach ( $this->map( $slug ) as $name => $target ) {
			if ( ! isset( $input[ $name ] ) ) {
				continue;
			}

			$cast = self::cast( $input[ $name ], $target[1] );
			if ( null !== $cast ) {
				$out[ $target[0] ] = $cast;
			}
		}

		return $out;
	}

	/**
	 * The shortcodes and what they accept, read from the blocks' block.json files.
	 *
	 * @return array<string, array{block: string, attributes: array<string, array{type: string, default: mixed, block_attribute: string}>}>
	 */
	public function reference(): array {
		$reference = array();

		foreach ( array_keys( $this->blocks->renderers() ) as $slug ) {
			$slug       = (string) $slug;
			$attributes = array(
				'event'    => array(
					'type'            => 'string',
					'default'         => '',
					'block_attribute' => 'eventSlug',
				),
				'event_id' => array(
					'type'            => 'integer',
					'default'         => 0,
					'block_attribute' => 'eventId',
				),
				'class'    => array(
					'type'            => 'string',
					'default'         => '',
					'block_attribute' => 'className',
				),
			);

			$schema = $this->blocks->block_attributes( $slug );
			foreach ( $this->map( $slug ) as $name => $target ) {
				$attributes[ $name ] = array(
					'type'            => $target[1],
					'default'         => $schema[ $target[0] ]['default'] ?? null,
					'block_attribute' => $target[0],
				);
			}

			$reference[ self::tag( $slug ) ] = array(
				'block'      => $slug,
				'attributes' => $attributes,
			);
		}

		return $reference;
	}

	/**
	 * Shortcode tag for a block slug.
	 *
	 * @param string $slug Block slug.
	 */
	public static function tag( string $slug ): string {
		return self::PREFIX . str_replace( '-', '_', $slug );
	}

	/**
	 * snake_case attribute => [block.json attribute, type] for one block. The event picker attributes and
	 * className are handled separately, so they are not part of this map.
	 *
	 * @param string $slug Block slug.
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function map( string $slug ): array {
		if ( ! isset( $this->maps[ $slug ] ) ) {
			$map = array();
			foreach ( $this->blocks->block_attributes( $slug ) as $name => $definition ) {
				$type = isset( $definition['type'] ) && is_string( $definition['type'] ) ? $definition['type'] : '';
				if ( in_array( $name, Block_Registry::EDITOR_MANAGED, true ) || ! in_array( $type, array( 'boolean', 'integer', 'number', 'string' ), true ) ) {
					continue;
				}

				$map[ strtolower( (string) preg_replace( '/([A-Z])/', '_$1', $name ) ) ] = array( $name, $type );
			}
			$this->maps[ $slug ] = $map;
		}

		return $this->maps[ $slug ];
	}

	/**
	 * Casts a shortcode value to the declared type; null when it is not a valid value of that type.
	 *
	 * @param string $value Raw value.
	 * @param string $type  boolean|integer|number|string.
	 * @return bool|int|float|string|null
	 */
	private static function cast( string $value, string $type ) {
		switch ( $type ) {
			case 'boolean':
				$flag = strtolower( trim( $value ) );
				if ( in_array( $flag, array( 'true', '1', 'yes', 'on' ), true ) ) {
					return true;
				}

				return in_array( $flag, array( 'false', '0', 'no', 'off', '' ), true ) ? false : null;

			case 'integer':
				return preg_match( '/^\s*([0-9]{1,18})\s*\z/', $value, $m ) ? (int) $m[1] : null;

			case 'number':
				$number = trim( $value );

				return is_numeric( $number ) && is_finite( (float) $number ) ? $number + 0 : null;

			default:
				$text = sanitize_text_field( self::limit( $value ) );

				return is_string( $text ) ? $text : null;
		}
	}

	/**
	 * Shortens a value to MAX_STRING characters before it is sanitised.
	 *
	 * @param string $value Raw value.
	 */
	private static function limit( string $value ): string {
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::MAX_STRING, 'UTF-8' ) : substr( $value, 0, self::MAX_STRING );
	}
}

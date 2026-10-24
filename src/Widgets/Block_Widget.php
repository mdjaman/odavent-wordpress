<?php
namespace Odavent\Events\Widgets;

use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Shortcodes\Shortcodes;

/**
 * The classic widget of one block. The same class serves every block: the slug decides the name, the
 * controls (one per block.json attribute) and what renders.
 *
 * Output goes through Block_Registry::render_block(), the path blocks and shortcodes use, so a widget
 * shows exactly what the block would (lifecycle notices, no Register link for a cancelled event, nothing
 * for visitors when the event is gone). When the block has nothing to show the widget prints nothing at
 * all: no before_widget/after_widget and no title.
 */
class Block_Widget extends \WP_Widget {

	/** @var string */
	private $slug;

	/** @var Widgets */
	private $widgets;

	/** @var Block_Registry */
	private $blocks;

	/**
	 * @param string         $slug    Block slug.
	 * @param Widgets        $widgets Instance sanitising and the form's event list.
	 * @param Block_Registry $blocks  Titles, labels and the render path.
	 */
	public function __construct( string $slug, Widgets $widgets, Block_Registry $blocks ) {
		$this->slug    = $slug;
		$this->widgets = $widgets;
		$this->blocks  = $blocks;

		parent::__construct(
			Widgets::id_base( $slug ),
			/* translators: %s: block title, such as "Speakers". */
			sprintf( __( 'Odavent: %s', 'odavent-events' ), $blocks->block_title( $slug ) ),
			array(
				'classname'   => Widgets::CLASSNAME,
				'description' => $blocks->block_description( $slug ),
			)
		);
	}

	/**
	 * Front end: the block, wrapped in the sidebar's markup and the optional title, or nothing.
	 *
	 * @param mixed $args     Sidebar arguments (before_widget, after_widget, before_title, after_title).
	 * @param mixed $instance Stored settings (re-sanitised: not trusted).
	 */
	public function widget( $args, $instance ) {
		try {
			$html = $this->blocks->render_block( $this->slug, $this->widgets->sanitize_instance( $this->slug, $instance, false ) );
			if ( '' === $html ) {
				return;
			}

			$args  = is_array( $args ) ? $args : array();
			$title = apply_filters( 'widget_title', self::title( $instance ), is_array( $instance ) ? $instance : array(), $this->id_base );

			$out = self::arg( $args, 'before_widget', '' );
			if ( is_string( $title ) && '' !== trim( $title ) ) {
				$out .= self::arg( $args, 'before_title', '<h2 class="widget-title">' ) . esc_html( $title ) . self::arg( $args, 'after_title', '</h2>' );
			}
			$out .= $html . self::arg( $args, 'after_widget', '' );

			// The sidebar markup comes from the theme (register_sidebar), the block html from the render path.
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
	}

	/**
	 * Saving: the block attributes cast and clamped like a shortcode's, plus the title. A checkbox the
	 * browser did not send is saved as false (it was unchecked), never as its default.
	 *
	 * @param mixed $new_instance Submitted values.
	 * @param mixed $old_instance Previous settings (unused: nothing is carried over).
	 * @return array|false False (keep the old settings) only if sanitising failed.
	 */
	public function update( $new_instance, $old_instance ) {
		try {
			return array( 'title' => self::title( $new_instance ) ) + $this->widgets->sanitize_instance( $this->slug, $new_instance, true );
		} catch ( \Throwable $e ) {
			self::log( $e );

			return false;
		}
	}

	/**
	 * Admin form: title, event, then one control per block attribute, labelled like the block editor.
	 *
	 * @param mixed $instance Stored settings.
	 */
	public function form( $instance ) {
		try {
			$values = $this->widgets->sanitize_instance( $this->slug, $instance, false );

			$this->text_field( 'title', __( 'Title (optional)', 'odavent-events' ), self::title( $instance ) );
			$this->event_field( isset( $values['eventId'] ) && is_int( $values['eventId'] ) ? $values['eventId'] : 0 );

			foreach ( $this->widgets->controls( $this->slug ) as $name => $definition ) {
				$label   = $this->blocks->attribute_label( $this->slug, $name );
				$default = $definition['default'] ?? null;
				$value   = array_key_exists( $name, $values ) ? $values[ $name ] : $default;

				if ( 'boolean' === $definition['type'] ) {
					$this->checkbox_field( $name, $label, (bool) $value );
				} elseif ( isset( $definition['enum'] ) && is_array( $definition['enum'] ) ) {
					$this->select_field( $name, $label, $definition['enum'], $value );
				} elseif ( 'integer' === $definition['type'] || 'number' === $definition['type'] ) {
					$this->number_field( $name, $label, $definition, is_int( $value ) || is_float( $value ) ? (string) $value : '' );
				} else {
					$this->text_field( $name, $label, is_string( $value ) ? $value : '' );
				}
			}
		} catch ( \Throwable $e ) {
			self::log( $e );
		}
	}

	// ---- form controls -----------------------------------------------------------------------

	/**
	 * The event: a list of the organization's events when the connection has one, else the event ID. Empty
	 * means the default event from the settings.
	 *
	 * @param int $current Saved event id, 0 for the default event.
	 */
	private function event_field( int $current ): void {
		$id      = $this->get_field_id( 'eventId' );
		$name    = $this->get_field_name( 'eventId' );
		$label   = $this->blocks->attribute_label( $this->slug, 'eventId' );
		$choices = $this->widgets->event_choices();

		echo '<p><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label> ';

		if ( null === $choices ) {
			printf(
				'<input class="small-text" type="number" min="1" step="1" id="%s" name="%s" value="%s">',
				esc_attr( $id ),
				esc_attr( $name ),
				$current > 0 ? esc_attr( (string) $current ) : ''
			);
			echo '<br><small>' . esc_html__( 'Enter the Odavent event ID, or leave empty to use the default event from Settings > Odavent Events.', 'odavent-events' ) . '</small></p>';
			return;
		}

		echo '<select class="widefat" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( 'Default event (Settings > Odavent Events)', 'odavent-events' ) . '</option>';
		foreach ( $choices as $event_id => $event_name ) {
			printf( '<option value="%d"%s>%s</option>', (int) $event_id, $event_id === $current ? ' selected' : '', esc_html( $event_name ) );
		}
		// A saved event that is not in the list any more stays selected: saving must not switch events silently.
		if ( $current > 0 && ! isset( $choices[ $current ] ) ) {
			/* translators: %d: Odavent event ID. */
			printf( '<option value="%d" selected>%s</option>', (int) $current, esc_html( sprintf( __( 'Event %d (not found in your Odavent events)', 'odavent-events' ), $current ) ) );
		}
		echo '</select></p>';
	}

	private function checkbox_field( string $field, string $label, bool $checked ): void {
		printf(
			'<p><input class="checkbox" type="checkbox" id="%1$s" name="%2$s" value="1"%3$s> <label for="%1$s">%4$s</label></p>',
			esc_attr( $this->get_field_id( $field ) ),
			esc_attr( $this->get_field_name( $field ) ),
			$checked ? ' checked' : '',
			esc_html( $label )
		);
	}

	/**
	 * @param string $field      Attribute name.
	 * @param string $label      Label.
	 * @param array  $definition block.json definition (type, minimum, maximum).
	 * @param string $value      Current value, '' for none.
	 */
	private function number_field( string $field, string $label, array $definition, string $value ): void {
		$range = '';
		foreach ( array( 'minimum' => 'min', 'maximum' => 'max' ) as $key => $attribute ) {
			if ( isset( $definition[ $key ] ) && is_numeric( $definition[ $key ] ) ) {
				$range .= ' ' . $attribute . '="' . esc_attr( (string) ( $definition[ $key ] + 0 ) ) . '"';
			}
		}

		printf(
			'<p><label for="%1$s">%2$s</label> <input class="tiny-text" type="number" step="%3$s"%4$s id="%1$s" name="%5$s" value="%6$s"></p>',
			esc_attr( $this->get_field_id( $field ) ),
			esc_html( $label ),
			'integer' === $definition['type'] ? '1' : 'any',
			$range, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped numbers above.
			esc_attr( $this->get_field_name( $field ) ),
			esc_attr( $value )
		);
	}

	private function text_field( string $field, string $label, string $value ): void {
		printf(
			'<p><label for="%1$s">%2$s</label> <input class="widefat" type="text" id="%1$s" name="%3$s" value="%4$s"></p>',
			esc_attr( $this->get_field_id( $field ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $field ) ),
			esc_attr( $value )
		);
	}

	/**
	 * @param string $field   Attribute name.
	 * @param string $label   Label.
	 * @param array  $choices block.json `enum`.
	 * @param mixed  $current Current value.
	 */
	private function select_field( string $field, string $label, array $choices, $current ): void {
		printf(
			'<p><label for="%1$s">%2$s</label> <select class="widefat" id="%1$s" name="%3$s">',
			esc_attr( $this->get_field_id( $field ) ),
			esc_html( $label ),
			esc_attr( $this->get_field_name( $field ) )
		);
		foreach ( $choices as $choice ) {
			if ( is_scalar( $choice ) ) {
				printf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( (string) $choice ), $choice === $current ? ' selected' : '' );
			}
		}
		echo '</select></p>';
	}

	// ---- helpers --------------------------------------------------------------------------------

	/**
	 * The title from an instance: text only, sanitised, at most Shortcodes::MAX_STRING characters.
	 *
	 * @param mixed $instance Instance or submitted values.
	 */
	private static function title( $instance ): string {
		$title = is_array( $instance ) && isset( $instance['title'] ) && is_string( $instance['title'] ) ? $instance['title'] : '';
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, Shortcodes::MAX_STRING, 'UTF-8' ) : substr( $title, 0, Shortcodes::MAX_STRING );
		$title = sanitize_text_field( $title );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * A sidebar argument that is a string, else $default.
	 */
	private static function arg( array $args, string $key, string $default ): string {
		return isset( $args[ $key ] ) && is_string( $args[ $key ] ) ? $args[ $key ] : $default;
	}

	private static function log( \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Odavent Events: widget failed: ' . get_class( $e ) . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

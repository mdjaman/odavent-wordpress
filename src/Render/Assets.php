<?php
namespace Odavent\Events\Render;

use Odavent\Events\Settings\Options;

/**
 * The plugin's stylesheet and scripts, registered and enqueued only when something rendered needs them.
 */
final class Assets {

	const STYLE_HANDLE = 'odavent-events';
	const SCRIPTS      = array( 'countdown', 'agenda-filter' );

	/** @var Options */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Enqueues the default stylesheet unless the site turned it off ("Load default styles").
	 */
	public function enqueue_styles(): void {
		if ( ! $this->options->load_css() ) {
			return;
		}

		if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			wp_register_style(
				self::STYLE_HANDLE,
				plugins_url( 'assets/css/odavent.css', ODAVENT_EVENTS_FILE ),
				array(),
				ODAVENT_EVENTS_VERSION
			);
		}

		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Enqueues one of the plugin's scripts: in the footer, deferred, with no dependencies.
	 *
	 * Before WordPress 6.3 the array form of the last argument is read as "in footer" (true), which is the same placement.
	 *
	 * @param string $handle `countdown` or `agenda-filter`; anything else is ignored.
	 */
	public function enqueue_script( string $handle ): void {
		if ( ! in_array( $handle, self::SCRIPTS, true ) ) {
			return;
		}

		$full = self::STYLE_HANDLE . '-' . $handle;

		if ( ! wp_script_is( $full, 'registered' ) ) {
			wp_register_script(
				$full,
				plugins_url( 'assets/js/' . $handle . '.js', ODAVENT_EVENTS_FILE ),
				array(),
				ODAVENT_EVENTS_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}

		wp_enqueue_script( $full );
	}
}

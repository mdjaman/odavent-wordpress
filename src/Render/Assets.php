<?php
namespace Odavent\Events\Render;

use Odavent\Events\Settings\Appearance;
use Odavent\Events\Settings\Options;

/**
 * The plugin's stylesheet and scripts, registered and enqueued only when something rendered needs them.
 */
final class Assets {

	const STYLE_HANDLE = 'odavent-events';
	const SCRIPTS      = array( 'countdown', 'agenda-filter', 'image-fallback' );

	/** @var Options */
	private $options;

	/** @var bool Whether the Appearance CSS was already attached to the handle (it would print twice). */
	private $inline_added = false;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Registers the default stylesheet handle.
	 *
	 * Runs on `init` before the blocks are registered: their block.json names the handle as editorStyle, so
	 * the block editor loads it. On the front end a registered handle loads nothing by itself (only
	 * enqueue_styles() does, and it checks "Load default styles"), so it is registered without reading the
	 * settings: a page without Odavent content costs no option read. In wp-admin, where the block editor
	 * loads editorStyle, nothing is registered when the setting is off, so the editor matches the site.
	 */
	public function register_styles(): void {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}
		if ( is_admin() && ! $this->options->load_css() ) {
			return;
		}

		wp_register_style(
			self::STYLE_HANDLE,
			plugins_url( 'assets/css/odavent.css', ODAVENT_EVENTS_FILE ),
			array(),
			ODAVENT_EVENTS_VERSION
		);

		// Only in wp-admin, where the setting was just read and the block editor loads this handle: the
		// front end attaches it from enqueue_styles(), so a page without Odavent content reads nothing.
		if ( is_admin() ) {
			$this->add_appearance_style();
		}
	}

	/**
	 * Enqueues the default stylesheet unless the site turned it off ("Load default styles").
	 */
	public function enqueue_styles(): void {
		if ( ! $this->options->load_css() ) {
			return;
		}

		$this->register_styles();
		$this->add_appearance_style();
		wp_enqueue_style( self::STYLE_HANDLE );
	}

	/**
	 * Attaches the organizer's Appearance CSS to the stylesheet handle, once. Callers have checked "Load
	 * default styles": with it off there is no stylesheet to configure and nothing is added.
	 */
	private function add_appearance_style(): void {
		if ( $this->inline_added ) {
			return;
		}
		$this->inline_added = true;

		$css = Appearance::css( $this->options->appearance() );
		if ( '' !== $css ) {
			wp_add_inline_style( self::STYLE_HANDLE, $css );
		}
	}

	/**
	 * Enqueues one of the plugin's scripts: in the footer, deferred, with no dependencies.
	 *
	 * Before WordPress 6.3 the array form of the last argument is read as "in footer" (true), which is the same placement.
	 *
	 * @param string $handle `countdown`, `agenda-filter` or `image-fallback`; anything else is ignored.
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

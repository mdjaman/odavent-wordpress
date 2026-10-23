<?php
namespace Odavent\Events\Blocks;

use Odavent\Events\Data\Event_View;

/**
 * Turns one event into a block's HTML.
 *
 * The registry has already handled everything around the block (which event, lifecycle notices, empty and
 * failed views, errors). A renderer returns '' when it has nothing to show: never an empty wrapper.
 */
interface Renderer {

	/**
	 * @param Event_View $view An event view with content.
	 * @param array      $atts Block attributes (or shortcode attributes mapped to the same names).
	 */
	public function render( Event_View $view, array $atts ): string;
}

<?php
/**
 * Template: "Already registered?" block.
 *
 * Override in your theme as odavent/find-registration.php.
 *
 * @var string                            $classes  Root element classes.
 * @var int                               $event_id Odavent event id.
 * @var string                            $url      Odavent find-registration URL (http(s)).
 * @var string                            $label    Link text.
 * @var \Odavent\Events\Data\Event_View   $view     The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
	<p><a class="odv-find-registration__link" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a></p>
</section>

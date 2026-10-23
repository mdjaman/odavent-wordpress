<?php
/**
 * Template: venue block.
 *
 * Override in your theme as odavent/venue.php. Every value is already cleaned; escape on output.
 *
 * @var string                          $classes  Root element classes.
 * @var int                             $event_id Odavent event id.
 * @var string                          $name     Venue name ('' when unknown).
 * @var string                          $address  "Address, city, country" ('' when unknown).
 * @var string                          $map_url  OpenStreetMap search URL, or '' when the link is turned off.
 * @var \Odavent\Events\Data\Event_View $view     The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php if ( '' !== $name ) : ?>
	<h3 class="odv-venue__name"><?php echo esc_html( $name ); ?></h3>
<?php endif; ?>
<?php if ( '' !== $address ) : ?>
	<p class="odv-venue__address"><?php echo esc_html( $address ); ?></p>
<?php endif; ?>
<?php if ( '' !== $map_url ) : ?>
	<a class="odv-venue__map" href="<?php echo esc_url( $map_url ); ?>" rel="noopener"><?php esc_html_e( 'View on map', 'odavent-events' ); ?></a>
<?php endif; ?>
</section>

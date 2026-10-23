<?php
/**
 * Template: event header block.
 *
 * Override in your theme as odavent/event-header.php. Every value is already cleaned; escape on output.
 *
 * @var string                            $classes     Root element classes.
 * @var int                               $event_id    Odavent event id.
 * @var string                            $name        Event name (plain text).
 * @var string                            $dates       Formatted dates in the event's timezone ('' when unknown).
 * @var string                            $venue       "Venue, address, city, country" ('' when hidden or unknown).
 * @var string                            $cover_url   http(s) cover image URL or ''.
 * @var string                            $logo_url    http(s) logo URL or ''.
 * @var string                            $description Sanitised HTML (allow-listed tags only) or ''.
 * @var string                            $json_ld     schema.org script element or ''.
 * @var \Odavent\Events\Data\Event_View   $view        The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php if ( '' !== $cover_url ) : ?>
	<img class="odv-header__cover" src="<?php echo esc_url( $cover_url ); ?>" alt="" decoding="async">
<?php endif; ?>
<?php if ( '' !== $logo_url ) : ?>
	<img class="odv-header__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" decoding="async">
<?php endif; ?>
<?php if ( '' !== $name ) : ?>
	<h2 class="odv-header__title"><?php echo esc_html( $name ); ?></h2>
<?php endif; ?>
<?php if ( '' !== $dates ) : ?>
	<p class="odv-header__dates"><?php echo esc_html( $dates ); ?></p>
<?php endif; ?>
<?php if ( '' !== $venue ) : ?>
	<p class="odv-header__meta"><?php echo esc_html( $venue ); ?></p>
<?php endif; ?>
<?php if ( '' !== trim( $description ) ) : ?>
	<div class="odv-header__description"><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitizer::html() allow-list. ?></div>
<?php endif; ?>
<?php echo $json_ld; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Schema encodes with JSON_HEX_TAG. ?>
</section>

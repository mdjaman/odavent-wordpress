<?php
/**
 * Template: tickets block.
 *
 * Override in your theme as odavent/tickets.php. Every value is already cleaned; escape on output.
 *
 * @var string                            $classes      Root element classes.
 * @var int                               $event_id     Odavent event id.
 * @var array[]                           $tickets      Rows: name, description (sanitised HTML), price, sold_out (bool),
 *                                                      status, remaining, url ('' when the ticket cannot be bought here).
 * @var string                            $button_label Text of the per-ticket links.
 * @var string                            $message      Why there are no Register links ('' while registration is open).
 * @var string                            $notice       Secure-payment notice ('' when no ticket has a link).
 * @var \Odavent\Events\Data\Event_View   $view         The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
	<ul class="odv-tickets__list">
<?php foreach ( $tickets as $ticket ) : ?>
		<li class="odv-tickets__item<?php echo $ticket['sold_out'] ? ' odv-tickets__item--sold-out' : ''; ?>">
			<h3 class="odv-tickets__name"><?php echo esc_html( $ticket['name'] ); ?></h3>
<?php if ( '' !== $ticket['price'] ) : ?>
			<p class="odv-tickets__price"><?php echo esc_html( $ticket['price'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== trim( $ticket['description'] ) ) : ?>
			<div class="odv-tickets__description"><?php echo $ticket['description']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitizer::html() allow-list. ?></div>
<?php endif; ?>
<?php if ( '' !== $ticket['status'] ) : ?>
			<p class="odv-tickets__status"><?php echo esc_html( $ticket['status'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== $ticket['remaining'] ) : ?>
			<p class="odv-tickets__status"><?php echo esc_html( $ticket['remaining'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== $ticket['url'] ) : ?>
			<a class="odv-tickets__register" href="<?php echo esc_url( $ticket['url'] ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: 1: button text, 2: ticket name. */ __( '%1$s: %2$s', 'odavent-events' ), $button_label, $ticket['name'] ) ); ?>"><?php echo esc_html( $button_label ); ?></a>
<?php endif; ?>
		</li>
<?php endforeach; ?>
	</ul>
<?php if ( '' !== $message ) : ?>
	<p class="odv-tickets__message"><?php echo esc_html( $message ); ?></p>
<?php endif; ?>
<?php if ( '' !== $notice ) : ?>
	<p class="odv-register__notice"><?php echo esc_html( $notice ); ?></p>
<?php endif; ?>
</section>

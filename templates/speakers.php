<?php
/**
 * Template: speakers block.
 *
 * Override in your theme as odavent/speakers.php. Every value is already cleaned; escape on output.
 *
 * @var string                          $classes    Root element classes.
 * @var int                             $event_id   Odavent event id.
 * @var string                          $list_class Classes of the list (they carry the column count).
 * @var array[]                         $speakers   Rows: name, role, company, bio (sanitised HTML), photo_url (http(s) or ''),
 *                                                  initials (placeholder text), featured (bool).
 * @var \Odavent\Events\Data\Event_View $view       The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
	<ul class="<?php echo esc_attr( $list_class ); ?>">
<?php foreach ( $speakers as $speaker ) : ?>
		<li class="odv-speakers__item<?php echo $speaker['featured'] ? ' odv-speakers__item--featured' : ''; ?>">
<?php if ( '' !== $speaker['photo_url'] ) : ?>
			<img class="odv-speakers__photo" src="<?php echo esc_url( $speaker['photo_url'] ); ?>" alt="<?php echo esc_attr( $speaker['name'] ); ?>" loading="lazy" decoding="async" width="96" height="96" data-odv-img="photo" data-odv-initials="<?php echo esc_attr( $speaker['initials'] ); ?>">
<?php else : ?>
			<span class="odv-speakers__photo odv-speakers__photo--placeholder" aria-hidden="true"><?php echo esc_html( $speaker['initials'] ); ?></span>
<?php endif; ?>
			<h3 class="odv-speakers__name"><?php echo esc_html( $speaker['name'] ); ?></h3>
<?php if ( '' !== $speaker['role'] ) : ?>
			<p class="odv-speakers__role"><?php echo esc_html( $speaker['role'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== $speaker['company'] ) : ?>
			<p class="odv-speakers__company"><?php echo esc_html( $speaker['company'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== trim( $speaker['bio'] ) ) : ?>
			<div class="odv-speakers__bio"><?php echo $speaker['bio']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitizer::html() allow-list. ?></div>
<?php endif; ?>
		</li>
<?php endforeach; ?>
	</ul>
</section>

<?php
/**
 * Template: sponsors block.
 *
 * Override in your theme as odavent/sponsors.php. Every value is already cleaned; escape on output.
 *
 * @var string                          $classes    Root element classes.
 * @var int                             $event_id   Odavent event id.
 * @var string                          $list_class Classes of each list (they carry the column count).
 * @var array[]                         $groups     Rows: title ('' when the block is not grouped), items. An item has
 *                                                  name, logo_url (http(s) or ''), link_url (http(s) or ''),
 *                                                  description (sanitised HTML or ''), featured (bool).
 * @var \Odavent\Events\Data\Event_View $view       The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php foreach ( $groups as $group ) : ?>
<?php if ( '' !== $group['title'] ) : ?>
	<div class="odv-sponsors__group">
		<h3 class="odv-sponsors__group-title"><?php echo esc_html( $group['title'] ); ?></h3>
<?php endif; ?>
		<ul class="<?php echo esc_attr( $list_class ); ?>">
<?php foreach ( $group['items'] as $item ) : ?>
			<li class="odv-sponsors__item<?php echo $item['featured'] ? ' odv-sponsors__item--featured' : ''; ?>">
				<?php if ( '' !== $item['link_url'] ) : ?><a class="odv-sponsors__link" href="<?php echo esc_url( $item['link_url'] ); ?>" rel="sponsored noopener"><?php endif; ?><?php if ( '' !== $item['logo_url'] ) : ?><img class="odv-sponsors__logo" src="<?php echo esc_url( $item['logo_url'] ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" loading="lazy" decoding="async"><?php else : ?><span class="odv-sponsors__name"><?php echo esc_html( $item['name'] ); ?></span><?php endif; ?><?php if ( '' !== $item['link_url'] ) : ?></a><?php endif; ?>

<?php if ( '' !== trim( $item['description'] ) ) : ?>
				<div class="odv-sponsors__description"><?php echo $item['description']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitizer::html() allow-list. ?></div>
<?php endif; ?>
			</li>
<?php endforeach; ?>
		</ul>
<?php if ( '' !== $group['title'] ) : ?>
	</div>
<?php endif; ?>
<?php endforeach; ?>
</section>

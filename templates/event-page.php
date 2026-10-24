<?php
/**
 * Template: event page block (every chosen section of the event, in a fixed order).
 *
 * Override in your theme as odavent/event-page.php. Each section's HTML comes from that section's own
 * template (override those to change a section); this one only arranges them. The lifecycle notice
 * (ended, paused, cancelled, draft preview) is printed above this markup by the plugin, once.
 *
 * @var string                            $classes       Root element classes.
 * @var int                               $event_id      Odavent event id.
 * @var string                            $stale_notice  Notice HTML (templates/notice.php) when the data is a stale copy, else ''.
 * @var array[]                           $sections      Rows in page order: slug, id (unique on the page, for anchors),
 *                                                       title ('' for sections without a heading), html (the section's
 *                                                       rendered, escaped markup; never empty).
 * @var bool                              $show_headings Whether to print the section headings.
 * @var \Odavent\Events\Data\Event_View   $view          The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php if ( '' !== $stale_notice ) : ?>
	<?php echo $stale_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates/notice.php (escaped). ?>
<?php endif; ?>
<?php foreach ( $sections as $section ) : ?>
	<div class="odv-event-page__section odv-event-page__section--<?php echo esc_attr( $section['slug'] ); ?>" id="<?php echo esc_attr( $section['id'] ); ?>">
<?php if ( $show_headings && '' !== $section['title'] ) : ?>
		<h2 class="odv-event-page__heading"><?php echo esc_html( $section['title'] ); ?></h2>
<?php endif; ?>
		<?php echo $section['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by the section's own template (escaped). ?>
	</div>
<?php endforeach; ?>
</div>

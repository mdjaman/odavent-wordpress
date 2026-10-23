<?php
/**
 * Template: Register button block.
 *
 * Override in your theme as odavent/register-button.php. Keep the notice under the button.
 *
 * @var string                            $classes  Root element classes.
 * @var int                               $event_id Odavent event id.
 * @var string                            $url      Registration URL, or '' when registration is not offered.
 * @var string                            $label    Button text.
 * @var string                            $status   Why there is no button ('' when there is one).
 * @var string                            $stale_notice Notice HTML (templates/notice.php) when the data is a stale copy, else ''.
 * @var string                            $notice   "Registration and payment are handled securely by Odavent."
 * @var \Odavent\Events\Data\Event_View   $view     The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php if ( '' !== $url ) : ?>
	<a class="odv-register__button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $label ); ?></a>
	<p class="odv-register__notice"><?php echo esc_html( $notice ); ?></p>
<?php elseif ( '' !== $stale_notice ) : ?>
	<?php echo $stale_notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Rendered by templates/notice.php (escaped). ?>
<?php else : ?>
	<p class="odv-register__status"><?php echo esc_html( $status ); ?></p>
<?php endif; ?>
</section>

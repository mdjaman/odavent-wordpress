<?php
/**
 * Template: countdown block.
 *
 * Override in your theme as odavent/countdown.php. Keep the data-* attributes and the odv-countdown__* class
 * names: assets/js/countdown.js reads them to keep the numbers current. Every value is already cleaned.
 *
 * @var string                          $classes     Root element classes.
 * @var int                             $event_id    Odavent event id.
 * @var string                          $starts_at   Start, ISO 8601 with offset.
 * @var string                          $ends_at     End, ISO 8601 with offset ('' when unknown).
 * @var string                          $state       upcoming, live or ended (as of the page being built).
 * @var bool                            $ended_flag  Odavent says the event has ended.
 * @var string                          $label_live  Text shown while the event is live.
 * @var string                          $label_ended Text shown once it has ended.
 * @var string                          $days        Whole days left (numbers are meaningful while upcoming).
 * @var string                          $hours       Hours left, two digits.
 * @var string                          $minutes     Minutes left, two digits.
 * @var string                          $seconds     Seconds left, two digits.
 * @var \Odavent\Events\Data\Event_View $view        The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;

$odv_message = 'live' === $state ? $label_live : ( 'ended' === $state ? $label_ended : '' );
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>" data-starts-at="<?php echo esc_attr( $starts_at ); ?>"<?php echo '' !== $ends_at ? ' data-ends-at="' . esc_attr( $ends_at ) . '"' : ''; ?><?php echo $ended_flag ? ' data-ended="true"' : ''; ?> data-state="<?php echo esc_attr( $state ); ?>" data-label-live="<?php echo esc_attr( $label_live ); ?>" data-label-ended="<?php echo esc_attr( $label_ended ); ?>">
<?php if ( 'upcoming' === $state ) : ?>
	<div class="odv-countdown__timer">
		<div class="odv-countdown__unit"><span class="odv-countdown__value" data-unit="days"><?php echo esc_html( $days ); ?></span><span class="odv-countdown__label"><?php esc_html_e( 'Days', 'odavent-events' ); ?></span></div>
		<div class="odv-countdown__unit"><span class="odv-countdown__value" data-unit="hours"><?php echo esc_html( $hours ); ?></span><span class="odv-countdown__label"><?php esc_html_e( 'Hours', 'odavent-events' ); ?></span></div>
		<div class="odv-countdown__unit"><span class="odv-countdown__value" data-unit="minutes"><?php echo esc_html( $minutes ); ?></span><span class="odv-countdown__label"><?php esc_html_e( 'Minutes', 'odavent-events' ); ?></span></div>
		<div class="odv-countdown__unit"><span class="odv-countdown__value" data-unit="seconds"><?php echo esc_html( $seconds ); ?></span><span class="odv-countdown__label"><?php esc_html_e( 'Seconds', 'odavent-events' ); ?></span></div>
	</div>
<?php endif; ?>
	<p class="odv-countdown__message"<?php echo 'upcoming' === $state ? ' hidden' : ''; ?>><?php echo esc_html( $odv_message ); ?></p>
</section>

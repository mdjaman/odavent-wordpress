<?php
/**
 * Template: agenda block.
 *
 * Override in your theme as odavent/agenda.php. Keep the odv-agenda__* class names and the data-track
 * attributes: assets/js/agenda-filter.js uses them. Every value is already cleaned; escape on output.
 *
 * @var string                          $classes  Root element classes.
 * @var int                             $event_id Odavent event id.
 * @var string[]                        $tracks   Track names for the filter buttons (empty: no filter).
 * @var array[]                         $days     Rows: title (the day, '' for sessions without a start), sessions.
 *                                                A session has name, time, location, track, speakers (comma
 *                                                separated names), description (sanitised HTML) and rooms
 *                                                (sessions nested under it, same shape).
 * @var \Odavent\Events\Data\Event_View $view     The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;

/*
 * One session and, recursively, the rooms nested under it.
 */
$odv_session = static function ( array $session, bool $is_room ) use ( &$odv_session ) {
	?>
		<li class="odv-agenda__session<?php echo $is_room ? ' odv-agenda__session--child' : ''; ?>" data-track="<?php echo esc_attr( $session['track'] ); ?>">
<?php if ( '' !== $session['time'] ) : ?>
			<p class="odv-agenda__time"><?php echo esc_html( $session['time'] ); ?></p>
<?php endif; ?>
			<h4 class="odv-agenda__title"><?php echo esc_html( $session['name'] ); ?></h4>
<?php if ( '' !== $session['location'] ) : ?>
			<p class="odv-agenda__location"><?php echo esc_html( $session['location'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== $session['track'] ) : ?>
			<p class="odv-agenda__track"><?php echo esc_html( $session['track'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== $session['speakers'] ) : ?>
			<p class="odv-agenda__speakers"><?php echo esc_html( $session['speakers'] ); ?></p>
<?php endif; ?>
<?php if ( '' !== trim( $session['description'] ) ) : ?>
			<div class="odv-agenda__description"><?php echo $session['description']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Sanitizer::html() allow-list. ?></div>
<?php endif; ?>
<?php if ( array() !== $session['rooms'] ) : ?>
			<ul class="odv-agenda__rooms">
<?php foreach ( $session['rooms'] as $room ) : ?>
<?php $odv_session( $room, true ); ?>
<?php endforeach; ?>
			</ul>
<?php endif; ?>
		</li>
<?php
};
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
<?php if ( array() !== $tracks ) : ?>
	<div class="odv-agenda__filters" role="group" aria-label="<?php esc_attr_e( 'Filter by track', 'odavent-events' ); ?>">
		<button type="button" class="odv-agenda__filter" data-track="" aria-pressed="true"><?php esc_html_e( 'All', 'odavent-events' ); ?></button>
<?php foreach ( $tracks as $track ) : ?>
		<button type="button" class="odv-agenda__filter" data-track="<?php echo esc_attr( $track ); ?>" aria-pressed="false"><?php echo esc_html( $track ); ?></button>
<?php endforeach; ?>
	</div>
<?php endif; ?>
<?php foreach ( $days as $day ) : ?>
	<div class="odv-agenda__day">
<?php if ( '' !== $day['title'] ) : ?>
		<h3 class="odv-agenda__day-title"><?php echo esc_html( $day['title'] ); ?></h3>
<?php endif; ?>
		<ul class="odv-agenda__list">
<?php foreach ( $day['sessions'] as $session ) : ?>
<?php $odv_session( $session, false ); ?>
<?php endforeach; ?>
		</ul>
	</div>
<?php endforeach; ?>
</section>

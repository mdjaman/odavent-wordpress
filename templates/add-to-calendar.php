<?php
/**
 * Template: add-to-calendar block.
 *
 * Override in your theme as odavent/add-to-calendar.php. Every value is already cleaned; escape on output.
 *
 * @var string                          $classes  Root element classes.
 * @var int                             $event_id Odavent event id.
 * @var array[]                         $links    Rows: url (http(s)), label, download (bool, true for the .ics file).
 * @var \Odavent\Events\Data\Event_View $view     The full event view.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<section class="<?php echo esc_attr( $classes ); ?>" data-odv-event="<?php echo esc_attr( (string) $event_id ); ?>">
	<ul class="odv-calendar__list">
<?php foreach ( $links as $link ) : ?>
		<li><a class="odv-calendar__link" href="<?php echo esc_url( $link['url'] ); ?>"<?php echo $link['download'] ? ' download' : ''; ?> rel="noopener"><?php echo esc_html( $link['label'] ); ?></a></li>
<?php endforeach; ?>
	</ul>
</section>

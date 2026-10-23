<?php
/**
 * Template: a notice (lifecycle line for visitors, or a message for editors).
 *
 * Override in your theme as odavent/notice.php.
 *
 * @var string $kind    Modifier class suffix: ended, paused, cancelled, draft, unavailable, choose, error.
 * @var string $message Plain text.
 *
 * @package Odavent\Events
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="odv-notice odv-notice--<?php echo esc_attr( $kind ); ?>" role="status"><?php echo esc_html( $message ); ?></div>

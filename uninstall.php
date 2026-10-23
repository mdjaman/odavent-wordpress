<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 *
 * The plugin itself is not loaded at this point, so the cleanup class is required directly.
 *
 * @package Odavent\Events
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Uninstaller.php';

\Odavent\Events\Uninstaller::run();

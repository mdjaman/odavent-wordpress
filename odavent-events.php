<?php
/**
 * Plugin Name:       Odavent Events
 * Plugin URI:        https://github.com/mdjaman/odavent-wordpress
 * Update URI:        https://github.com/mdjaman/odavent-wordpress
 * Description:       Show live speakers, agenda, sponsors and tickets from your Odavent events on your own WordPress site.
 * Version:           0.2.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Odavent
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       odavent-events
 * Domain Path:       /languages
 *
 * @package Odavent\Events
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// A second copy of the plugin (e.g. an `odavent-events-1` folder left by a manual upload) loaded in
// the same request must do nothing: redefining the constants or the function below would be fatal.
if ( defined( 'ODAVENT_EVENTS_VERSION' ) ) {
	return;
}

define( 'ODAVENT_EVENTS_VERSION', '0.2.0' );
define( 'ODAVENT_EVENTS_FILE', __FILE__ );
define( 'ODAVENT_EVENTS_DIR', __DIR__ . '/' );

if ( ! function_exists( 'odavent_events_requirements_met' ) ) {
	/**
	 * Whether the host meets the minimum PHP and WordPress versions.
	 *
	 * Kept free of PHP 7.4-only syntax so the notice below can render on older versions.
	 *
	 * @return bool
	 */
	function odavent_events_requirements_met() {
		global $wp_version;

		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			return false;
		}

		return ! isset( $wp_version ) || version_compare( $wp_version, '6.1', '>=' );
	}
}

if ( ! odavent_events_requirements_met() ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html( 'Odavent Events requires PHP 7.4 or later and WordPress 6.1 or later. The plugin is inactive until the site is upgraded.' );
			echo '</p></div>';
		}
	);
	return;
}

/**
 * PSR-4 autoloader for the Odavent\Events\ namespace (src/). Production code has no Composer dependency.
 */
spl_autoload_register(
	function ( $class ) {
		$prefix = 'Odavent\\Events\\';

		if ( strncmp( $class, $prefix, strlen( $prefix ) ) !== 0 ) {
			return;
		}

		// Only plain class names map to a file: no dots (`..`), no NUL byte, nothing outside the allowed set.
		$name = substr( $class, strlen( $prefix ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\\\\\/]+$/D', $name ) ) {
			return;
		}

		$relative = str_replace( '\\', '/', $name );
		$file     = ODAVENT_EVENTS_DIR . 'src/' . $relative . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		\Odavent\Events\Plugin::instance()->boot();
	}
);

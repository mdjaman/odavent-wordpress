<?php
namespace Odavent\Events;

/**
 * Removes everything the plugin stored. Standalone on purpose: uninstall.php runs without the plugin loaded.
 */
final class Uninstaller {

	public static function run(): void {
		if ( function_exists( 'is_multisite' ) && is_multisite() ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::clean_site();
				restore_current_blog();
			}
			self::clean_network();
			return;
		}

		self::clean_site();
	}

	private static function clean_site(): void {
		global $wpdb;

		delete_option( 'odavent_events_settings' );

		// "Last good" API snapshots and transients (with their timeout rows) share a prefix.
		$patterns = array(
			'odavent_events_lastgood_',
			'_transient_odavent_events_',
			'_transient_timeout_odavent_events_',
			// Single-site installs keep site transients (the update check) in the options table.
			'_site_transient_odavent_events_',
			'_site_transient_timeout_odavent_events_',
		);

		foreach ( $patterns as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}

	/**
	 * Network-wide site transients (the update check) live in the sitemeta table on multisite.
	 */
	private static function clean_network(): void {
		global $wpdb;

		foreach ( array( '_site_transient_odavent_events_', '_site_transient_timeout_odavent_events_' ) as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}
}

<?php
namespace Odavent\Events;

/**
 * Removes everything the plugin stored. Standalone on purpose: uninstall.php runs without the plugin loaded.
 *
 * Rows are found with a prepared, escaped LIKE and removed through the options API (not a raw DELETE), so a
 * persistent object cache that holds a copy of a non-autoloaded option is cleared along with the row.
 */
final class Uninstaller {

	/** Fixed-name transients, cleared through the API even when an object cache holds them outside the table. */
	const TRANSIENTS = array(
		'odavent_events_list',
		'odavent_events_backoff',
		'odavent_events_invalid_key_notice',
		'odavent_events_contract_notice',
		'odavent_events_conn_disabled',
	);

	const SITE_TRANSIENTS = array( 'odavent_events_update' );

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
		foreach ( self::TRANSIENTS as $name ) {
			delete_transient( $name );
		}
		foreach ( self::SITE_TRANSIENTS as $name ) {
			delete_site_transient( $name );
		}

		// "Last good" API snapshots and transients (with their timeout rows) share a prefix.
		$patterns = array(
			'odavent_events_lastgood_',
			'_transient_odavent_events_',
			'_transient_timeout_odavent_events_',
			// Single-site installs keep site transients (the update check) in the options table.
			'_site_transient_odavent_events_',
			'_site_transient_timeout_odavent_events_',
			// The widgets' settings: WordPress saves widget_<id_base> for every registered widget, placed or not.
			'widget_odavent_events_',
		);

		foreach ( $patterns as $prefix ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( ! is_string( $name ) ) {
					continue;
				}
				// delete_option() removes the row and its cached copy; the transient API then also drops a copy an
				// object cache may keep under the transient's own key.
				delete_option( $name );
				if ( 0 === strpos( $name, '_site_transient_' ) && 0 !== strpos( $name, '_site_transient_timeout_' ) ) {
					delete_site_transient( substr( $name, strlen( '_site_transient_' ) ) );
				} elseif ( 0 === strpos( $name, '_transient_' ) && 0 !== strpos( $name, '_transient_timeout_' ) ) {
					delete_transient( substr( $name, strlen( '_transient_' ) ) );
				}
			}
		}
	}

	/**
	 * Network-wide site transients (the update check) live in the sitemeta table on multisite.
	 */
	private static function clean_network(): void {
		global $wpdb;

		foreach ( self::SITE_TRANSIENTS as $name ) {
			delete_site_transient( $name );
		}

		foreach ( array( '_site_transient_odavent_events_', '_site_transient_timeout_odavent_events_' ) as $prefix ) {
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_key FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			foreach ( is_array( $names ) ? $names : array() as $name ) {
				if ( is_string( $name ) ) {
					delete_site_option( $name );
				}
			}
		}
	}
}

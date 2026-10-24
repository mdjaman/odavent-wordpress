<?php
/**
 * `wp eval-file` helper for bin/smoke.sh: drops the tables of the throwaway /pkg WordPress (prefix pkg_), so
 * check 13 always installs the release zip into a fresh site. Never touches the dev site's wp_ tables.
 */
global $wpdb;
foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'pkg\\_%'" ) as $table ) {
	$wpdb->query( 'DROP TABLE `' . str_replace( '`', '', $table ) . '`' );
}

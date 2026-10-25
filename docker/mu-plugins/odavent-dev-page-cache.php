<?php
/**
 * Dev stack only (mounted by docker/docker-compose.yml, never shipped): a minimal full-page cache, so
 * bin/smoke.sh can prove that a refresh ping reaches pages a real page-cache plugin would keep.
 *
 * Off unless the option `odv_dev_page_cache` is '1'. When on, it caches anonymous GET pages that answer 200 and
 * did not define DONOTCACHEPAGE in wp-content/odv-dev-cache/ (one file per request URI), serves them back with
 * `X-Odv-Dev-Cache: HIT` (a stored page) or `MISS` (rendered now), and, like a real cache plugin:
 *  - empties itself on the plugin's whole-site purge (`odavent_events_purge_all`), appending a line to
 *    purge-all.log in the same directory;
 *  - drops one post's page on the per-post purge (`odavent_events_purge_post`), appending its id to purge-post.log.
 */

define( 'ODV_DEV_CACHE_DIR', WP_CONTENT_DIR . '/odv-dev-cache' );

function odv_dev_cache_enabled() {
	return '1' === (string) get_option( 'odv_dev_page_cache', '0' );
}

function odv_dev_cache_file( $uri ) {
	return ODV_DEV_CACHE_DIR . '/' . md5( (string) $uri ) . '.html';
}

function odv_dev_cache_log( $name, $line ) {
	wp_mkdir_p( ODV_DEV_CACHE_DIR );
	file_put_contents( ODV_DEV_CACHE_DIR . '/' . $name, $line . "\n", FILE_APPEND | LOCK_EX );
}

add_action(
	'template_redirect',
	function () {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '';
		if ( ! odv_dev_cache_enabled() || 'GET' !== $method || is_user_logged_in() ) {
			return;
		}

		$file = odv_dev_cache_file( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' );
		if ( is_readable( $file ) ) {
			header( 'X-Odv-Dev-Cache: HIT' );
			readfile( $file );
			exit;
		}

		header( 'X-Odv-Dev-Cache: MISS' );
		ob_start(
			function ( $html ) use ( $file ) {
				if ( 200 === http_response_code() && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) && false !== stripos( $html, '</html>' ) ) {
					wp_mkdir_p( ODV_DEV_CACHE_DIR );
					file_put_contents( $file, $html, LOCK_EX );
				}
				return $html;
			}
		);
	},
	0
);

add_action(
	'odavent_events_purge_all',
	function () {
		if ( ! odv_dev_cache_enabled() ) {
			return;
		}
		foreach ( (array) glob( ODV_DEV_CACHE_DIR . '/*.html' ) as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		odv_dev_cache_log( 'purge-all.log', gmdate( 'c' ) );
	}
);

add_action(
	'odavent_events_purge_post',
	function ( $post_id ) {
		if ( ! odv_dev_cache_enabled() ) {
			return;
		}
		$path = wp_parse_url( (string) get_permalink( $post_id ), PHP_URL_PATH );
		if ( is_string( $path ) && is_file( odv_dev_cache_file( $path ) ) ) {
			unlink( odv_dev_cache_file( $path ) );
		}
		odv_dev_cache_log( 'purge-post.log', (string) $post_id );
	}
);

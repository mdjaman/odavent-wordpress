<?php
/**
 * Dev stack only (mounted by docker/docker-compose.yml, never shipped): makes the plugin's early
 * page-cache protection observable from outside. When DONOTCACHEPAGE is defined by the time the theme
 * is about to render (end of template_redirect, before any block renders), the response carries
 * `X-Odavent-Probe-Donotcachepage: 1`. bin/smoke.sh asserts it for an editor viewing a draft preview.
 */
add_action(
	'template_redirect',
	function () {
		if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE && ! headers_sent() ) {
			header( 'X-Odavent-Probe-Donotcachepage: 1' );
		}
	},
	PHP_INT_MAX
);

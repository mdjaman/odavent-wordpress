<?php
/**
 * Dev stack only (mounted by docker/docker-compose.yml, never shipped): a widget area for the smoke and e2e
 * tests. The default themes of recent WordPress versions are block themes with no widget areas, so this
 * registers one (`dev-widget-area`) and prints it in the footer of every page while it holds a widget.
 * bin/smoke.sh places a widget there with `wp widget add` and removes it afterwards.
 */
add_action(
	'widgets_init',
	function () {
		register_sidebar(
			array(
				'id'            => 'dev-widget-area',
				'name'          => 'Dev widget area (dev stack only)',
				'before_widget' => '<section id="%1$s" class="widget %2$s">',
				'after_widget'  => '</section>',
				'before_title'  => '<h2 class="widget-title">',
				'after_title'   => '</h2>',
			)
		);
	}
);

// Before wp_print_footer_scripts (priority 20), so what a widget enqueues while rendering is still printed.
add_action(
	'wp_footer',
	function () {
		if ( is_active_sidebar( 'dev-widget-area' ) ) {
			echo '<aside class="dev-widget-area">';
			dynamic_sidebar( 'dev-widget-area' );
			echo '</aside>';
		}
	},
	5
);

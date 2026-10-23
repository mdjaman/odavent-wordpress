<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads Composer's autoloader (test tooling only) and defines the constants WordPress would
 * normally provide. The plugin's own bootstrap (odavent-events.php) is NOT loaded here; tests
 * require it after stubbing WordPress functions with Brain Monkey.
 *
 * @package Odavent\Events
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/odavent-events-wp/' );
}

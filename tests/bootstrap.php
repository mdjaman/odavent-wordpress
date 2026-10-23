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

// Minimal stand-in for WordPress's WP_Error so HTTP failure paths can be exercised.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		public $code;
		public $message;

		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message() {
			return $this->message;
		}
	}
}

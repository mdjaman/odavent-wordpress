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
		public $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

// Minimal stand-in for WordPress's block type registry: tests put translated block types in $types.
if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
	class WP_Block_Type_Registry {

		/** @var array<string, object> */
		public static $types = array();

		private static $instance = null;

		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		public function get_registered( $name ) {
			return isset( self::$types[ $name ] ) ? self::$types[ $name ] : null;
		}
	}
}

// Minimal stand-ins for the REST request/response classes. Header names are canonicalised the
// way WordPress does it (lowercase, dashes to underscores).
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		private $method;
		private $route;
		private $body    = '';
		private $headers = array();

		public function __construct( $method = '', $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public static function canonicalize_header_name( $key ) {
			return str_replace( '-', '_', strtolower( $key ) );
		}

		public function get_method() {
			return $this->method;
		}

		public function set_body( $body ) {
			$this->body = $body;
		}

		public function get_body() {
			return $this->body;
		}

		public function set_header( $key, $value ) {
			$this->headers[ self::canonicalize_header_name( $key ) ] = array( $value );
		}

		public function get_header( $key ) {
			$key = self::canonicalize_header_name( $key );

			return isset( $this->headers[ $key ] ) ? implode( ',', $this->headers[ $key ] ) : null;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		public $data;
		public $status;

		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function get_status() {
			return $this->status;
		}
	}
}

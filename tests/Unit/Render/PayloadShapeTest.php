<?php
namespace Odavent\Events\Tests\Unit\Render;

use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Schema;
use Odavent\Events\Tests\Support\RenderTestCase;

/**
 * The payload comes from the network and may drift: any key can be null, a number, an array or ''.
 * Every public Render entry point must cope without a Throwable (TypeError included) or a PHP warning.
 */
class PayloadShapeTest extends RenderTestCase {

	/**
	 * One case per (fixture, key, replacement value). Providers run before setUp(): plain PHP only.
	 *
	 * @return array<string, array{0: string, 1: string, 2: mixed}>
	 */
	public function drifted_payloads(): array {
		$cases = array();
		foreach ( array( 'minimal', 'hostile', 'full' ) as $name ) {
			$payload = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/payload-v1-' . $name . '.json' ), true );

			$paths = array();
			foreach ( array_keys( $payload ) as $key ) {
				$paths[] = array( $key );
			}
			foreach ( array_keys( $payload['event'] ) as $key ) {
				$paths[] = array( 'event', $key );
			}
			if ( isset( $payload['tickets'][0] ) ) {
				foreach ( array_keys( $payload['tickets'][0] ) as $key ) {
					$paths[] = array( 'tickets', 0, $key );
				}
			}
			if ( isset( $payload['event']['venue'] ) && is_array( $payload['event']['venue'] ) ) {
				foreach ( array_keys( $payload['event']['venue'] ) as $key ) {
					$paths[] = array( 'event', 'venue', $key );
				}
			}

			foreach ( $paths as $path ) {
				foreach ( array(
					'null'  => null,
					'int'   => 123,
					'array' => array( 'x' => array( 'y' ) ),
					'empty' => '',
					'true'  => true,
				) as $label => $value ) {
					$cases[ $name . ' ' . implode( '.', $path ) . ' = ' . $label ] = array( $name, implode( '/', $path ), $value );
				}
			}
		}

		return $cases;
	}

	public function test_the_provider_covers_a_lot_of_shapes(): void {
		$this->assertGreaterThan( 300, count( $this->drifted_payloads() ) );
	}

	/**
	 * @dataProvider drifted_payloads
	 * @param string $fixture Fixture name.
	 * @param string $path    Slash separated key path.
	 * @param mixed  $value   Replacement value.
	 */
	public function test_no_entry_point_throws_or_warns( string $fixture, string $path, $value ): void {
		$payload = $this->fixture( 'payload-v1-' . $fixture );
		$keys    = explode( '/', $path );
		$leaf    = array_pop( $keys );
		$node    = &$payload;
		foreach ( $keys as $key ) {
			$node = &$node[ ctype_digit( $key ) ? (int) $key : $key ];
		}
		$node[ $leaf ] = $value;
		unset( $node );

		$problems = array();
		set_error_handler(
			static function ( $severity, $message, $file, $line ) use ( &$problems ) {
				$problems[] = $message . ' @' . basename( $file ) . ':' . $line;
				return true;
			}
		);

		try {
			foreach ( array( false, true ) as $preview ) {
				$view = Event_View::from_payload( $payload, $preview );
				$this->exercise( $view );
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $problems );
	}

	private function exercise( Event_View $view ): void {
		$event = $view->event();

		Schema::event_json_ld( $view, 'https://blog.example.com/summit/' );
		Schema::event_json_ld( $view, '' );
		Register_Url::build( $view );

		foreach ( $view->tickets() as $ticket ) {
			Register_Url::build( $view, $ticket['id'] ?? null );
			Format::price( $ticket['price'] ?? null, $event['currency'] ?? null, $ticket['is_free'] ?? null );
		}

		Format::date_range( $event );
		Format::datetime( $event['starts_at'] ?? null, $event['timezone'] ?? null );
		Format::day_key( $event['starts_at'] ?? null, $event['timezone'] ?? null );

		$values = array();
		array_walk_recursive(
			$event,
			static function ( $leaf ) use ( &$values ) {
				$values[] = $leaf;
			}
		);
		foreach ( array_merge( $values, array_values( $event ) ) as $value ) {
			Sanitizer::text( $value );
			Sanitizer::html( $value );
			Sanitizer::url( $value );
			Format::datetime( $value, $value );
			Format::day_key( $value, $value );
			Register_Url::safe( $value );
		}
	}
}

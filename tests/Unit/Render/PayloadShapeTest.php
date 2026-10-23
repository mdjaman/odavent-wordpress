<?php
namespace Odavent\Events\Tests\Unit\Render;

use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Blocks\Renderer;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Render\Format;
use Odavent\Events\Render\Register_Url;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Render\Schema;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\Support\RenderTestCase;

/**
 * The payload comes from the network and may drift: any key can be null, a number, an array or ''.
 * Every public Render entry point must cope without a Throwable (TypeError included) or a PHP warning.
 */
class PayloadShapeTest extends RenderTestCase {

	/** Every optional part of every block switched on, to reach the branches the defaults skip. */
	const EVERYTHING_ON = array(
		'showBio'          => true,
		'showDescription'  => true,
		'showAvailability' => true,
		'showSpeakers'     => true,
		'speakerId'        => 301,
		'limit'            => 2,
		'columns'          => 2,
		'groupByType'      => false,
		'trackFilter'      => true,
		'showMapLink'      => true,
		'labelLive'        => 'x',
	);

	/** @var Renderer[]|null Renderers are stateless: built once for the whole walk. */
	private static $renderers = null;

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
			if ( isset( $payload['event']['calendar'] ) && is_array( $payload['event']['calendar'] ) ) {
				foreach ( array_keys( $payload['event']['calendar'] ) as $key ) {
					$paths[] = array( 'event', 'calendar', $key );
				}
			}
			// Content lists: the first row, and the nested session (a breakout room with a parent_id).
			foreach ( array( 'sessions' => array( 0, 3 ), 'speakers' => array( 0 ), 'partners' => array( 0 ) ) as $list => $rows ) {
				foreach ( $rows as $index ) {
					if ( isset( $payload[ $list ][ $index ] ) && is_array( $payload[ $list ][ $index ] ) ) {
						foreach ( array_keys( $payload[ $list ][ $index ] ) as $key ) {
							$paths[] = array( $list, $index, $key );
						}
					}
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

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'get_permalink' )->justReturn( 'https://blog.example.com/summit/' );
	}

	public function test_the_provider_covers_a_lot_of_shapes(): void {
		$this->assertGreaterThan( 800, count( $this->drifted_payloads() ) );
	}

	/**
	 * Every renderer the registry knows, so a renderer added later is covered without touching this test.
	 *
	 * @return Renderer[]
	 */
	private function renderers(): array {
		if ( null === self::$renderers ) {
			$registry        = new Block_Registry( Mockery::mock( Event_Repository::class ), Mockery::mock( Options::class ) );
			self::$renderers = array();
			foreach ( $registry->renderers() as $slug => $class ) {
				self::$renderers[ $slug ] = new $class();
			}
		}

		return self::$renderers;
	}

	public function test_the_walk_covers_every_block(): void {
		$this->assertGreaterThanOrEqual( 10, count( $this->renderers() ) );
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
				$this->exercise( $view, $preview );
			}
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $problems );
	}

	private function exercise( Event_View $view, bool $everything_on ): void {
		$event = $view->event();

		Schema::event_json_ld( $view, 'https://blog.example.com/summit/' );
		Schema::event_json_ld( $view, '' );
		Register_Url::build( $view );

		foreach ( $view->tickets() as $ticket ) {
			Register_Url::build( $view, $ticket['id'] ?? null );
			Format::price( $ticket['price'] ?? null, $event['currency'] ?? null, $ticket['is_free'] ?? null );
		}

		// Every block the registry knows (a new renderer is covered automatically). Rendering is the costly
		// part of this walk, so the two views alternate the default attributes and "every option on".
		$atts = $everything_on ? self::EVERYTHING_ON : array();
		foreach ( $this->renderers() as $slug => $renderer ) {
			$this->assertIsString( $renderer->render( $view, $atts ), $slug );
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

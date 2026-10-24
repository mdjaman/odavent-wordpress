<?php
namespace Odavent\Events\Tests\Unit\Data;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Odavent\Events\Data\Clock;
use Odavent\Events\Tests\TestCase;

class ClockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	public function test_without_a_filter_it_is_the_real_time(): void {
		$before = time();
		$now    = Clock::now();

		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( time(), $now );
	}

	public function usable_values(): array {
		return array(
			'int'          => array( 1700000000, 1700000000 ),
			'zero'         => array( 0, 0 ),
			'digit string' => array( '1700000000', 1700000000 ),
		);
	}

	/**
	 * @dataProvider usable_values
	 * @param mixed $filtered Value the filter returns.
	 */
	public function test_an_int_or_a_digit_string_is_used( $filtered, int $expected ): void {
		Filters\expectApplied( 'odavent_events_now' )->once()->andReturn( $filtered );

		$this->assertSame( $expected, Clock::now() );
	}

	public function junk_values(): array {
		return array(
			'word'          => array( 'banana' ),
			'empty'         => array( '' ),
			'negative text' => array( '-5' ),
			'trailing junk' => array( '12abc' ),
			'float text'    => array( '1.5' ),
			'float'         => array( 1700000000.5 ),
			'null'          => array( null ),
			'array'         => array( array( 1700000000 ) ),
			'bool'          => array( true ),
			'huge digits'   => array( '99999999999999999999999' ),
		);
	}

	/**
	 * @dataProvider junk_values
	 * @param mixed $filtered Value the filter returns.
	 */
	public function test_anything_else_falls_back_to_the_real_time( $filtered ): void {
		Filters\expectApplied( 'odavent_events_now' )->once()->andReturn( $filtered );

		$before = time();
		$now    = Clock::now();

		$this->assertGreaterThanOrEqual( $before, $now );
		$this->assertLessThanOrEqual( time(), $now );
	}
}

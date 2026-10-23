<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Attributes;
use Odavent\Events\Tests\Support\RenderTestCase;

class AttributesTest extends RenderTestCase {

	public function bounded(): array {
		return array(
			'in range'        => array( 3, 2, 4, 9, 3 ),
			'below'           => array( 1, 2, 4, 9, 2 ),
			'above'           => array( 99, 2, 4, 9, 4 ),
			'digit string'    => array( ' 4 ', 2, 4, 9, 4 ),
			'whole float'     => array( 3.0, 2, 4, 9, 3 ),
			'fractional'      => array( 3.5, 2, 4, 9, 9 ),
			'text'            => array( 'abc', 2, 4, 9, 9 ),
			'negative string' => array( '-2', 2, 4, 9, 9 ),
			'null'            => array( null, 2, 4, 9, 9 ),
			'array'           => array( array( 3 ), 2, 4, 9, 9 ),
			'bool'            => array( true, 2, 4, 9, 9 ),
			'huge digits'     => array( '99999999999999999999', 2, 4, 9, 9 ),
		);
	}

	/**
	 * @dataProvider bounded
	 * @param mixed $value Attribute value.
	 */
	public function test_bounded_int( $value, int $min, int $max, int $default, int $expected ): void {
		$this->assertSame( $expected, Attributes::bounded_int( array( 'n' => $value ), 'n', $min, $max, $default ) );
	}

	public function test_a_missing_attribute_gives_the_default(): void {
		$this->assertSame( 3, Attributes::bounded_int( array(), 'n', 2, 4, 3 ) );
	}
}

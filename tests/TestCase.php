<?php
/**
 * Base test case: Brain Monkey lifecycle, translation/escape stubs and fixture loading.
 *
 * @package Odavent\Events
 */

namespace Odavent\Events\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;

abstract class TestCase extends PHPUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();
		// Remembered for the request: every test is a new request.
		if ( class_exists( \Odavent\Events\Widgets\Widgets::class ) ) {
			\Odavent\Events\Widgets\Widgets::reset();
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Decodes tests/fixtures/{name}.json.
	 *
	 * @param string $name Fixture name without extension.
	 * @return array
	 */
	protected function fixture( string $name ): array {
		$path = __DIR__ . '/fixtures/' . $name . '.json';
		$this->assertFileExists( $path, "Missing fixture {$name}" );

		$data = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $data, "Fixture {$name} is not valid JSON" );

		return $data;
	}
}

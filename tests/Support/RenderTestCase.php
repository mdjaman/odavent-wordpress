<?php
namespace Odavent\Events\Tests\Support;

use Brain\Monkey\Functions;
use Odavent\Events\Tests\TestCase;

/**
 * Base for render-layer tests: loads the plugin (autoloader and constants) and installs the WordPress doubles.
 */
abstract class RenderTestCase extends TestCase {

	protected function tearDown(): void {
		// Mockery and Brain Monkey expectations are assertions too (failOnRisky would flag a test that only has them).
		if ( class_exists( \Mockery::class ) ) {
			$this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
		}
		parent::tearDown();
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 2 ) . '/odavent-events.php';
		WpStubs::install();
	}
}

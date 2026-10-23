<?php
namespace Odavent\Events\Tests\Unit\Data;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Odavent\Events\Data\Locale;
use Odavent\Events\Tests\TestCase;

class LocaleTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	public function locales(): array {
		return array(
			'fr_FR'  => array( 'fr_FR', 'fr' ),
			'fr_CA'  => array( 'fr_CA', 'fr' ),
			'fr'     => array( 'fr', 'fr' ),
			'en_GB'  => array( 'en_GB', 'en' ),
			'en_US'  => array( 'en_US', 'en' ),
			'de_DE'  => array( 'de_DE', null ),
			'empty'  => array( '', null ),
			'french' => array( 'french', null ),
			'frr'    => array( 'frr', null ),
		);
	}

	/**
	 * @dataProvider locales
	 */
	public function test_maps_wordpress_locale( string $wp_locale, ?string $expected ): void {
		Functions\when( 'determine_locale' )->justReturn( $wp_locale );

		$this->assertSame( $expected, Locale::current() );
	}

	public function test_filter_can_override(): void {
		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		Filters\expectApplied( 'odavent_events_locale' )->once()->with( null, 'de_DE' )->andReturn( 'fr' );

		$this->assertSame( 'fr', Locale::current() );
	}

	public function test_filter_returning_an_unsupported_value_means_no_locale(): void {
		Functions\when( 'determine_locale' )->justReturn( 'fr_FR' );
		Filters\expectApplied( 'odavent_events_locale' )->once()->andReturn( '../../x' );

		$this->assertNull( Locale::current() );
	}

	public function test_cache_key_part(): void {
		Functions\when( 'determine_locale' )->justReturn( 'fr_FR' );
		$this->assertSame( 'fr', Locale::key_part() );

		Functions\when( 'determine_locale' )->justReturn( 'de_DE' );
		$this->assertSame( 'default', Locale::key_part() );
	}

	public function test_all_key_parts_cover_every_supported_locale_and_default(): void {
		$this->assertSame( array( 'en', 'fr', 'default' ), Locale::all_key_parts() );
	}
}

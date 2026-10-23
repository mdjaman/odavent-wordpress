<?php
namespace Odavent\Events\Tests\Unit\Render;

use Odavent\Events\Render\Format;
use Odavent\Events\Tests\Support\RenderTestCase;
use Odavent\Events\Tests\Support\WpStubs;

class FormatTest extends RenderTestCase {

	protected function tearDown(): void {
		Format::disable_intl( false );
		parent::tearDown();
	}

	public function test_datetime_uses_the_event_timezone_not_utc(): void {
		$this->assertSame( '2026-11-10 09:00', Format::datetime( '2026-11-10T08:00:00+00:00', 'Europe/Paris', 'Y-m-d H:i' ) );
		$this->assertSame( '2026-11-10 08:00', Format::datetime( '2026-11-10T08:00:00+00:00', 'UTC', 'Y-m-d H:i' ) );
	}

	public function test_datetime_defaults_to_the_wordpress_date_and_time_formats(): void {
		WpStubs::$options['date_format'] = 'd/m/Y';
		WpStubs::$options['time_format'] = 'H\hi';

		$this->assertSame( '10/11/2026 09h00', Format::datetime( '2026-11-10T08:00:00+00:00', 'Europe/Paris' ) );
	}

	public function test_an_offset_in_the_input_is_honoured(): void {
		$this->assertSame( '2026-11-10 07:00', Format::datetime( '2026-11-10T08:00:00+01:00', 'UTC', 'Y-m-d H:i' ) );
		$this->assertSame( '2026-11-10 08:00', Format::datetime( '2026-11-10T08:00:00Z', 'UTC', 'Y-m-d H:i' ) );
	}

	public function invalid_isos(): array {
		return array(
			'null'               => array( null ),
			'empty'              => array( '' ),
			'garbage'            => array( 'not a date' ),
			'relative keyword'   => array( 'tomorrow' ),
			'now'                => array( 'now' ),
			'script'             => array( '<script>alert(3)</script>' ),
			'impossible day'     => array( '2026-02-31T10:00:00+00:00' ),
			'month 13'           => array( '2026-13-01T10:00:00+00:00' ),
			'date only'          => array( '2026-11-10' ),
			'trailing junk'      => array( '2026-11-10T08:00:00+00:00 +1 day' ),
		);
	}

	/**
	 * @dataProvider invalid_isos
	 */
	public function test_invalid_input_gives_an_empty_string( $iso ): void {
		$this->assertSame( '', Format::datetime( $iso, 'Europe/Paris', 'Y-m-d' ) );
		$this->assertSame( '', Format::day_key( $iso, 'Europe/Paris' ) );
	}

	public function test_values_that_are_not_strings_do_not_fatal(): void {
		$this->assertSame( '', Format::datetime( array( 'x' ), 'UTC', 'Y' ) );
		$this->assertSame( '', Format::day_key( 12345, 'UTC' ) );
		$this->assertSame( '2026-11-12 09:00', Format::datetime( '2026-11-12T09:00:00+00:00', null, 'Y-m-d H:i' ) );
		$this->assertSame( '2026-11-12 09:00', Format::datetime( '2026-11-12T09:00:00+00:00', array(), 'Y-m-d H:i' ) );
	}

	public function test_an_invalid_timezone_falls_back_to_utc(): void {
		$this->assertSame( '2026-11-12 09:00', Format::datetime( '2026-11-12T09:00:00+00:00', '"><script>alert(1)</script>', 'Y-m-d H:i' ) );
		$this->assertSame( '2026-11-12 09:00', Format::datetime( '2026-11-12T09:00:00+00:00', '', 'Y-m-d H:i' ) );
	}

	public function test_day_key_is_the_local_day(): void {
		// 23:30 UTC on the 10th is already the 11th in Paris (UTC+1 in November) but still the 10th in Abidjan.
		$this->assertSame( '2026-11-11', Format::day_key( '2026-11-10T23:30:00+00:00', 'Europe/Paris' ) );
		$this->assertSame( '2026-11-10', Format::day_key( '2026-11-10T23:30:00+00:00', 'Africa/Abidjan' ) );
		// 01:00 UTC on the 11th is still the 10th in New York.
		$this->assertSame( '2026-11-10', Format::day_key( '2026-11-11T01:00:00+00:00', 'America/New_York' ) );
	}

	public function test_the_timezone_really_matters_across_a_dst_boundary(): void {
		// Europe/Paris leaves summer time on 2026-10-25 at 01:00 UTC: 02:30 happens twice, an hour apart.
		$summer = Format::datetime( '2026-10-25T00:30:00+00:00', 'Europe/Paris', 'H:i T' );
		$winter = Format::datetime( '2026-10-25T01:30:00+00:00', 'Europe/Paris', 'H:i T' );

		$this->assertSame( '02:30 CEST', $summer );
		$this->assertSame( '02:30 CET', $winter );

		// The day before, UTC+2; the day after, UTC+1.
		$this->assertSame( '2026-10-24 22:00', Format::datetime( '2026-10-24T20:00:00+00:00', 'Europe/Paris', 'Y-m-d H:i' ) );
		$this->assertSame( '2026-10-26 21:00', Format::datetime( '2026-10-26T20:00:00+00:00', 'Europe/Paris', 'Y-m-d H:i' ) );
	}

	public function test_date_range_on_one_day_shows_the_date_once(): void {
		$range = Format::date_range(
			array(
				'starts_at' => '2026-11-12T09:00:00+00:00',
				'ends_at'   => '2026-11-12T17:30:00+00:00',
				'timezone'  => 'Europe/Paris',
			)
		);

		$this->assertSame( '2026-11-12, 10:00 – 18:30', $range );
	}

	public function test_date_range_over_several_days_shows_both_ends(): void {
		$range = Format::date_range(
			array(
				'starts_at' => '2026-11-12T09:00:00+00:00',
				'ends_at'   => '2026-11-13T18:00:00+00:00',
				'timezone'  => 'Africa/Abidjan',
			)
		);

		$this->assertSame( '2026-11-12 09:00 – 2026-11-13 18:00', $range );
	}

	public function test_date_range_uses_the_local_day_to_decide_same_day(): void {
		// 23:30Z on the 10th and 00:30Z on the 11th are 00:30 and 01:30 on the 11th in Paris: one local day.
		$one_day = Format::date_range(
			array(
				'starts_at' => '2026-11-10T23:30:00+00:00',
				'ends_at'   => '2026-11-11T00:30:00+00:00',
				'timezone'  => 'Europe/Paris',
			)
		);
		$this->assertSame( '2026-11-11, 00:30 – 01:30', $one_day );

		// 22:30Z and 23:30Z are the same UTC day, but 23:30 on the 10th and 00:30 on the 11th in Paris: two local days.
		$two_days = Format::date_range(
			array(
				'starts_at' => '2026-11-10T22:30:00+00:00',
				'ends_at'   => '2026-11-10T23:30:00+00:00',
				'timezone'  => 'Europe/Paris',
			)
		);
		$this->assertSame( '2026-11-10 23:30 – 2026-11-11 00:30', $two_days );
	}

	public function test_date_range_without_an_end_is_the_start(): void {
		$this->assertSame(
			'2026-11-12 09:00',
			Format::date_range(
				array(
					'starts_at' => '2026-11-12T09:00:00+00:00',
					'ends_at'   => null,
					'timezone'  => 'UTC',
				)
			)
		);
	}

	public function test_date_range_without_a_start_is_empty(): void {
		$this->assertSame( '', Format::date_range( array( 'starts_at' => null, 'ends_at' => '2026-11-12T09:00:00+00:00', 'timezone' => 'UTC' ) ) );
		$this->assertSame( '', Format::date_range( array() ) );
		$this->assertSame( '', Format::date_range( array( 'starts_at' => 42, 'timezone' => array() ) ) );
	}

	public function test_free_tickets_say_free(): void {
		$this->assertSame( 'Free', Format::price( 0, 'EUR', true ) );
		$this->assertSame( 'Free', Format::price( 0.0, 'EUR', false ) );
		$this->assertSame( 'Free', Format::price( 12, 'EUR', true ) );
	}

	public function test_a_price_that_is_not_finite_is_not_shown(): void {
		$this->assertSame( '', Format::price( INF, 'EUR', false ) );
		$this->assertSame( '', Format::price( NAN, 'EUR', false ) );
	}

	public function test_fallback_price_has_two_decimals_and_the_currency_code(): void {
		Format::disable_intl( true );

		$this->assertSame( '12.50 EUR', Format::price( 12.5, 'EUR', false ) );
		$this->assertSame( '1,200.00 EUR', Format::price( 1200, 'EUR', false ) );
		$this->assertSame( '19.00 USD', Format::price( 19.0, 'usd', false ) );
	}

	public function test_fallback_price_has_no_decimals_for_zero_decimal_currencies(): void {
		Format::disable_intl( true );

		$this->assertSame( '25,000 XOF', Format::price( 25000, 'XOF', false ) );
		$this->assertSame( '25,000 XOF', Format::price( 25000.0, 'XOF', false ) );
		$this->assertSame( '1,500 XAF', Format::price( 1500, 'XAF', false ) );
		$this->assertSame( '1,500 JPY', Format::price( 1500, 'JPY', false ) );
		$this->assertSame( '9,000 KRW', Format::price( 9000, 'KRW', false ) );
	}

	public function test_a_hostile_currency_code_is_dropped(): void {
		Format::disable_intl( true );

		$out = Format::price( 10, '<b>XOF</b>', false );

		$this->assertStringNotContainsString( '<', $out );
		$this->assertSame( '10.00', $out );
	}

	public function test_intl_price_for_a_zero_decimal_currency_has_no_decimals(): void {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'The intl extension is not available.' );
		}

		$digits = preg_replace( '/[^0-9.,]/u', '', Format::price( 25000, 'XOF', false ) );

		$this->assertSame( '25,000', $digits );
	}

	public function test_intl_price_for_euros_has_two_decimals(): void {
		if ( ! class_exists( 'NumberFormatter' ) ) {
			$this->markTestSkipped( 'The intl extension is not available.' );
		}

		$this->assertSame( '€12.50', Format::price( 12.5, 'EUR', false ) );
		$this->assertSame( '€12.00', Format::price( 12, 'EUR', false ) );
	}
}

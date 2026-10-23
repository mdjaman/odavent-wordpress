<?php
namespace Odavent\Events\Tests\Unit\Refresh;

use Brain\Monkey\Functions;
use Odavent\Events\Refresh\Signature;
use Odavent\Events\Tests\TestCase;

class SignatureTest extends TestCase {

	private const KEY  = 'odv_wp_testkey12345';
	private const BODY = '{"event_id":7}';
	private const T    = 1700000000;

	/**
	 * Computed independently of the plugin: the same scheme as Odavent's WordpressSite::sign()
	 * ('t='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $site_key)).
	 */
	private const VECTOR_HEX = 'e259544247297cdd1f953b1aa82a84db638c9fe4cf620d5f020c070ca629af06';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	public function test_cross_repo_vector_matches_odavent_signing(): void {
		$this->assertSame( self::VECTOR_HEX, hash_hmac( 'sha256', '1700000000.{"event_id":7}', 'odv_wp_testkey12345' ) );
		$this->assertSame( 't=1700000000,v1=' . self::VECTOR_HEX, Signature::sign( self::BODY, self::T, self::KEY ) );
		$this->assertTrue( Signature::verify( 't=1700000000,v1=' . self::VECTOR_HEX, self::BODY, self::KEY, self::T ) );
	}

	public function test_round_trip(): void {
		$header = Signature::sign( self::BODY, self::T, self::KEY );

		$this->assertTrue( Signature::verify( $header, self::BODY, self::KEY, self::T ) );
		$this->assertSame( array( 't' => self::T, 'v1' => self::VECTOR_HEX ), Signature::parse( $header ) );
	}

	public function test_tampered_body_is_rejected(): void {
		$header = Signature::sign( self::BODY, self::T, self::KEY );

		$this->assertFalse( Signature::verify( $header, '{"event_id":8}', self::KEY, self::T ) );
		$this->assertFalse( Signature::verify( $header, self::BODY . ' ', self::KEY, self::T ) );
	}

	public function test_wrong_key_is_rejected(): void {
		$header = Signature::sign( self::BODY, self::T, self::KEY );

		$this->assertFalse( Signature::verify( $header, self::BODY, 'odv_wp_otherkey12345', self::T ) );
		$this->assertFalse( Signature::verify( $header, self::BODY, '', self::T ) );
	}

	public function test_signed_timestamp_is_bound_into_the_hmac(): void {
		$header = Signature::sign( self::BODY, self::T, self::KEY );
		$moved  = str_replace( 't=1700000000', 't=1700000001', $header );

		$this->assertFalse( Signature::verify( $moved, self::BODY, self::KEY, self::T ) );
	}

	public function test_timestamp_tolerance(): void {
		$header = Signature::sign( self::BODY, self::T, self::KEY );

		$this->assertFalse( Signature::verify( $header, self::BODY, self::KEY, self::T + 301 ), '301 s old' );
		$this->assertFalse( Signature::verify( $header, self::BODY, self::KEY, self::T - 301 ), '301 s in the future' );
		$this->assertTrue( Signature::verify( $header, self::BODY, self::KEY, self::T + 299 ) );
		$this->assertTrue( Signature::verify( $header, self::BODY, self::KEY, self::T - 299 ) );
		$this->assertTrue( Signature::verify( $header, self::BODY, self::KEY, self::T + 300 ), 'the boundary is inclusive' );
		$this->assertFalse( Signature::verify( $header, self::BODY, self::KEY, self::T + 61, 60 ), 'custom tolerance' );
	}

	public function test_header_casing_and_spacing_variants_parse(): void {
		$hex      = self::VECTOR_HEX;
		$expected = array( 't' => self::T, 'v1' => $hex );

		$this->assertSame( $expected, Signature::parse( " t=1700000000 , v1={$hex} " ) );
		$this->assertSame( $expected, Signature::parse( "T=1700000000,V1={$hex}" ) );
		$this->assertSame( $expected, Signature::parse( "v1={$hex},t=1700000000" ) );
		$this->assertTrue( Signature::verify( "T=1700000000, V1={$hex}", self::BODY, self::KEY, self::T ) );
	}

	public function test_extra_fields_are_tolerated_only_with_valid_t_and_v1(): void {
		$hex = self::VECTOR_HEX;

		$this->assertSame( array( 't' => self::T, 'v1' => $hex ), Signature::parse( "t=1700000000,v1={$hex},v0=whatever" ) );
		$this->assertNull( Signature::parse( 't=1700000000,v0=' . $hex ) );
		$this->assertNull( Signature::parse( "t=abc,v1={$hex},v0=x" ) );
	}

	/**
	 * @dataProvider malformed_headers
	 */
	public function test_malformed_headers_parse_to_null_and_never_verify( string $header ): void {
		$this->assertNull( Signature::parse( $header ) );
		$this->assertFalse( Signature::verify( $header, self::BODY, self::KEY, self::T ) );
	}

	public function malformed_headers(): array {
		$hex = self::VECTOR_HEX;

		return array(
			'empty'               => array( '' ),
			'garbage'             => array( 'nonsense' ),
			't=abc,v1=x'          => array( 't=abc,v1=x' ),
			'non-numeric t'       => array( "t=abc,v1={$hex}" ),
			'negative t'          => array( "t=-1700000000,v1={$hex}" ),
			'empty t'             => array( "t=,v1={$hex}" ),
			'fractional t'        => array( "t=1700000000.5,v1={$hex}" ),
			'huge t'              => array( "t=99999999999999999999,v1={$hex}" ),
			'missing v1'          => array( 't=1700000000' ),
			'missing t'           => array( "v1={$hex}" ),
			'v1 63 chars'         => array( 't=1700000000,v1=' . substr( $hex, 0, 63 ) ),
			'v1 65 chars'         => array( 't=1700000000,v1=' . $hex . '0' ),
			'uppercase hex'       => array( 't=1700000000,v1=' . strtoupper( $hex ) ),
			'non-hex'             => array( 't=1700000000,v1=' . str_repeat( 'g', 64 ) ),
			'duplicate t'         => array( "t=1700000000,t=1700000000,v1={$hex}" ),
			'duplicate v1'        => array( "t=1700000000,v1={$hex},v1={$hex}" ),
			'no equals'           => array( "t1700000000,v1{$hex}" ),
			'semicolons'          => array( "t=1700000000;v1={$hex}" ),
			'newline in value'    => array( "t=1700000000,v1={$hex}\n" ),
			'oversized'           => array( "t=1700000000,v1={$hex}," . str_repeat( 'x=y,', 200 ) ),
		);
	}
}

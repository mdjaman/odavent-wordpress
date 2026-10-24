<?php
namespace Odavent\Events\Tests\Unit\Api;

use Odavent\Events\Tests\TestCase;

/**
 * Keeps the fixtures in lockstep with Odavent's contract v1 (EventPayloadContractTest on the server).
 * When the contract gains or loses a key, update these lists and every fixture together.
 */
class ContractFixturesTest extends TestCase {

	private const TOP      = array( 'contract', 'locale', 'event', 'tickets', 'sessions', 'speakers', 'partners' );
	private const EVENT    = array(
		'id',
		'slug',
		'name',
		'description',
		'status',
		'starts_at',
		'ends_at',
		'timezone',
		'currency',
		'logo_url',
		'cover_url',
		'registration_open',
		'ended',
		'register_url',
		'find_registration_url',
		'calendar',
		'venue',
	);
	private const CALENDAR = array( 'ics', 'google', 'outlook' );
	private const VENUE    = array( 'name', 'address', 'city', 'country' );
	private const TICKET   = array( 'id', 'name', 'description', 'price', 'is_free', 'on_sale', 'is_full', 'spots_remaining', 'includes_recordings' );
	private const SESSION  = array( 'id', 'parent_id', 'name', 'description', 'starts_at', 'ends_at', 'location', 'track', 'speaker_ids' );
	private const SPEAKER  = array( 'id', 'name', 'company', 'job_title', 'bio', 'photo_url', 'is_featured' );
	private const PARTNER  = array( 'id', 'name', 'type', 'tier', 'description', 'logo_url', 'website', 'is_featured', 'click_url' );
	private const LIST_ROW = array( 'id', 'slug', 'name', 'status', 'starts_at', 'ends_at' );

	public function payload_fixtures(): array {
		return array(
			'full'    => array( 'payload-v1-full' ),
			'minimal' => array( 'payload-v1-minimal' ),
			'hostile' => array( 'payload-v1-hostile' ),
		);
	}

	/**
	 * @dataProvider payload_fixtures
	 */
	public function test_payload_fixture_has_exactly_the_contract_keys( string $name ): void {
		$data = $this->fixture( $name );

		$this->assertSame( self::TOP, array_keys( $data ), "{$name}: top level" );
		$this->assertSame( 1, $data['contract'] );
		$this->assertSame( self::EVENT, array_keys( $data['event'] ), "{$name}: event" );
		$this->assertSame( self::CALENDAR, array_keys( $data['event']['calendar'] ), "{$name}: calendar" );

		if ( null !== $data['event']['venue'] ) {
			$this->assertSame( self::VENUE, array_keys( $data['event']['venue'] ), "{$name}: venue" );
		}

		$lists = array(
			'tickets'  => self::TICKET,
			'sessions' => self::SESSION,
			'speakers' => self::SPEAKER,
			'partners' => self::PARTNER,
		);
		foreach ( $lists as $list => $keys ) {
			$this->assertIsSequential( $data[ $list ], "{$name}: {$list} is a list" );
			foreach ( $data[ $list ] as $i => $row ) {
				$this->assertSame( $keys, array_keys( $row ), "{$name}: {$list}[{$i}]" );
			}
		}
	}

	public function test_full_fixture_covers_the_shapes_the_renderer_needs(): void {
		$data = $this->fixture( 'payload-v1-full' );

		$this->assertCount( 2, $data['tickets'] );
		$this->assertContains( true, array_column( $data['tickets'], 'is_full' ) );
		$this->assertContains( false, array_column( $data['tickets'], 'on_sale' ) );

		$this->assertCount( 6, $data['sessions'] );
		$this->assertCount( 2, array_unique( array_column( $data['sessions'], 'track' ) ) );
		$days = array_unique( array_map( static fn ( $s ) => substr( $s['starts_at'], 0, 10 ), $data['sessions'] ) );
		$this->assertCount( 2, $days );
		$this->assertNotEmpty( array_filter( array_column( $data['sessions'], 'parent_id' ) ) );

		$this->assertCount( 4, $data['speakers'] );
		$this->assertCount( 1, array_filter( array_column( $data['speakers'], 'is_featured' ) ) );

		$this->assertCount( 5, $data['partners'] );
		$featured = array_values( array_filter( $data['partners'], static fn ( $p ) => $p['is_featured'] ) );
		$this->assertCount( 1, $featured );
		$this->assertNotNull( $featured[0]['click_url'] );
		$this->assertNotNull( $data['event']['venue'] );
		$this->assertMatchesRegularExpression( '/[éèôçï]/u', $data['event']['description'] );
	}

	public function test_minimal_fixture_uses_nulls_and_empty_lists(): void {
		$data = $this->fixture( 'payload-v1-minimal' );

		$this->assertNull( $data['event']['venue'] );
		$this->assertNull( $data['event']['description'] );
		$this->assertNull( $data['event']['logo_url'] );
		$this->assertSame( array(), $data['tickets'] );
		$this->assertSame( array(), $data['sessions'] );
		$this->assertSame( array(), $data['speakers'] );
		$this->assertSame( array(), $data['partners'] );
	}

	public function test_hostile_fixture_has_active_content_and_one_large_string(): void {
		$data = $this->fixture( 'payload-v1-hostile' );
		$json = (string) file_get_contents( __DIR__ . '/../../fixtures/payload-v1-hostile.json' );

		$this->assertStringContainsString( '<script>', $json );
		$this->assertStringContainsString( 'javascript:', $json );
		$this->assertStringContainsString( 'data:text/html', $json );
		$this->assertSame( 51200, strlen( $data['event']['description'] ) );
		$this->assertStringStartsWith( 'javascript:', $data['event']['logo_url'] );
		$this->assertStringStartsWith( 'data:', $data['event']['cover_url'] );
	}

	public function test_events_list_fixture_rows_have_the_contract_keys(): void {
		$rows = $this->fixture( 'events-list' );

		$this->assertNotEmpty( $rows );
		$this->assertIsSequential( $rows, 'events-list is a list' );
		foreach ( $rows as $i => $row ) {
			$this->assertSame( self::LIST_ROW, array_keys( $row ), "events-list[{$i}]" );
		}
	}

	private function assertIsSequential( array $value, string $message ): void {
		$this->assertSame( array_values( $value ), $value, $message );
	}
}

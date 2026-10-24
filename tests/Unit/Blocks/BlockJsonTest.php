<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;

/**
 * Static checks of every blocks/{slug}/block.json.
 */
class BlockJsonTest extends TestCase {

	const VALID_TYPES = array( 'string', 'boolean', 'integer', 'number', 'array', 'object', 'null' );

	public function blocks(): array {
		$rows = array();
		foreach ( glob( dirname( __DIR__, 3 ) . '/blocks/*/block.json' ) as $file ) {
			$rows[ basename( dirname( $file ) ) ] = array( $file );
		}

		return $rows;
	}

	public function test_the_four_core_blocks_exist(): void {
		$slugs = array_keys( $this->blocks() );
		foreach ( array( 'event-header', 'register-button', 'tickets', 'find-registration', 'speakers', 'agenda', 'sponsors', 'countdown', 'venue', 'add-to-calendar', 'event-page' ) as $slug ) {
			$this->assertContains( $slug, $slugs );
		}
	}

	/**
	 * @dataProvider blocks
	 */
	public function test_block_json_is_valid( string $file ): void {
		$json = json_decode( (string) file_get_contents( $file ), true );
		$this->assertIsArray( $json, 'valid JSON' );

		$slug = basename( dirname( $file ) );
		$this->assertSame( 3, $json['apiVersion'] );
		$this->assertSame( 'odavent-events/' . $slug, $json['name'], 'name matches the folder' );
		$this->assertSame( 'odavent-events', $json['category'] );
		$this->assertSame( 'odavent-events', $json['textdomain'] );
		$this->assertIsString( $json['title'] );
		$this->assertNotSame( '', $json['title'] );
		$this->assertIsString( $json['description'] );
		$this->assertSame( 'odavent-events', $json['editorStyle'] );
		$this->assertSame( 'odavent-events-editor', $json['editorScript'] );
		$this->assertArrayNotHasKey( 'style', $json, 'the front-end stylesheet is enqueued conditionally' );
		$this->assertArrayNotHasKey( 'render', $json, 'the registry supplies the render callback' );
		$this->assertArrayNotHasKey( 'viewScript', $json );
		$this->assertFalse( $json['supports']['html'] );
		$this->assertTrue( $json['supports']['className'] );

		$this->assertSame( array( 'type' => 'integer', 'default' => 0 ), array_intersect_key( $json['attributes']['eventId'], array_flip( array( 'type', 'default' ) ) ) );
		$this->assertSame( 'string', $json['attributes']['eventSlug']['type'] );
		$this->assertSame( '', $json['attributes']['eventSlug']['default'] );

		foreach ( $json['attributes'] as $name => $schema ) {
			$this->assertMatchesRegularExpression( '/^[a-z][A-Za-z]*$/', $name );
			$this->assertContains( $schema['type'] ?? null, self::VALID_TYPES, $name );
			if ( array_key_exists( 'default', $schema ) ) {
				$this->assertSame( $schema['type'], $this->json_type( $schema['default'] ), $name . ' default matches its type' );
			}
		}
	}

	/**
	 * assets/js/editor.js builds the Inspector from the attribute schema and knows only these four types:
	 * anything else would get no control at all.
	 *
	 * @dataProvider blocks
	 */
	public function test_every_attribute_has_a_type_the_generic_editor_can_edit( string $file ): void {
		$json = json_decode( (string) file_get_contents( $file ), true );

		foreach ( $json['attributes'] as $name => $schema ) {
			$this->assertContains( $schema['type'], array( 'boolean', 'integer', 'string', 'number' ), basename( dirname( $file ) ) . '.' . $name );
			$this->assertArrayHasKey( 'default', $schema, $name . ' needs a default so the block and the editor agree' );
			if ( isset( $schema['minimum'] ) || isset( $schema['maximum'] ) ) {
				$this->assertIsNumeric( $schema['minimum'] ?? 0 );
				$this->assertIsNumeric( $schema['maximum'] ?? 0 );
				$this->assertGreaterThanOrEqual( $schema['minimum'] ?? $schema['default'], $schema['default'], $name );
				$this->assertLessThanOrEqual( $schema['maximum'] ?? $schema['default'], $schema['default'], $name );
			}
		}
	}

	public function attribute_sets(): array {
		return array(
			'event-header'      => array( 'event-header', array( 'showCover' => true, 'showLogo' => true, 'showDescription' => true, 'showVenue' => true, 'showJsonLd' => true ) ),
			'register-button'   => array( 'register-button', array( 'label' => '', 'ticketId' => 0 ) ),
			'tickets'           => array( 'tickets', array( 'showDescription' => true, 'showAvailability' => false, 'buttonLabel' => '' ) ),
			'find-registration' => array( 'find-registration', array( 'label' => '' ) ),
			'speakers'          => array( 'speakers', array( 'columns' => 3, 'showBio' => false, 'showCompany' => true, 'limit' => 0, 'speakerId' => 0 ) ),
			'agenda'            => array( 'agenda', array( 'showSpeakers' => true, 'showDescription' => false, 'trackFilter' => true ) ),
			'sponsors'          => array( 'sponsors', array( 'groupByType' => true, 'showDescription' => false, 'columns' => 4 ) ),
			'countdown'         => array( 'countdown', array( 'labelLive' => '', 'labelEnded' => '' ) ),
			'venue'             => array( 'venue', array( 'showMapLink' => true ) ),
			'add-to-calendar'   => array( 'add-to-calendar', array( 'showGoogle' => true, 'showOutlook' => true, 'showIcs' => true ) ),
			'event-page'        => array( 'event-page', array( 'sections' => '', 'showHeadings' => true, 'speakersColumns' => 3, 'sponsorsColumns' => 4, 'showSpeakerBio' => false, 'showAvailability' => false ) ),
		);
	}

	/**
	 * @dataProvider attribute_sets
	 */
	public function test_block_attributes_and_defaults( string $slug, array $expected ): void {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/blocks/' . $slug . '/block.json' ), true );

		$defaults = array();
		foreach ( $json['attributes'] as $name => $schema ) {
			if ( ! in_array( $name, array( 'eventId', 'eventSlug' ), true ) ) {
				$defaults[ $name ] = $schema['default'] ?? null;
			}
		}
		$this->assertSame( $expected, $defaults );
	}

	private function json_type( $value ): string {
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) ) {
			return 'number';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( null === $value ) {
			return 'null';
		}

		return array_values( $value ) === $value ? 'array' : 'object';
	}
}

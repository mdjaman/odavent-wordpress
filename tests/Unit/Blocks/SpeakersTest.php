<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Speakers;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class SpeakersTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Speakers() )->render( Event_View::from_payload( $payload ), $atts );
	}

	/**
	 * @return string[] The speaker names, in output order.
	 */
	private function names( string $html ): array {
		preg_match_all( '#<h3 class="odv-speakers__name">(.*?)</h3>#s', $html, $m );

		return array_map(
			static function ( $name ) {
				return html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			},
			$m[1]
		);
	}

	public function test_it_lists_the_speakers_in_payload_order(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-speakers" data-odv-event="42">', trim( $html ) );
		$this->assertSame( array( 'Aïcha Koné', 'Marcel Djaman', 'Kofi Mensah', 'Fatoumata Diallo' ), $this->names( $html ) );
		$this->assertSame( 4, substr_count( $html, '<li class="odv-speakers__item' ) );
	}

	public function test_a_featured_speaker_is_marked(): void {
		$html = $this->render( $this->payload() );

		$this->assertSame( 1, substr_count( $html, 'odv-speakers__item--featured' ) );
		$this->assertMatchesRegularExpression( '#odv-speakers__item--featured">(?:(?!</li>).)*Aïcha#s', $html );
	}

	public function test_a_photo_is_lazy_loaded_with_the_name_as_alt(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringContainsString( '<img class="odv-speakers__photo" src="https://odavent.com/storage/media/61/marcel-medium.webp" alt="Marcel Djaman" loading="lazy"', $html );
		$this->assertStringContainsString( 'alt="Aïcha Koné"', $html );
	}

	public function test_a_speaker_without_a_photo_gets_an_initials_placeholder(): void {
		$html = $this->render( $this->payload() );

		// Kofi Mensah has no photo_url.
		$this->assertSame( 1, substr_count( $html, 'odv-speakers__photo--placeholder' ) );
		$this->assertStringContainsString( '<span class="odv-speakers__photo odv-speakers__photo--placeholder" aria-hidden="true">KM</span>', $html );
	}

	public function test_a_photo_carries_the_fallback_markers_and_a_placeholder_does_not(): void {
		$html = $this->render( $this->payload() );

		$this->assertMatchesRegularExpression( '#<img class="odv-speakers__photo" src="https://odavent\.com/storage/media/61/marcel-medium\.webp"[^>]* data-odv-img="photo" data-odv-initials="MD"#', $html );
		$this->assertSame( 3, substr_count( $html, 'data-odv-img="photo"' ), 'one per <img>, none on the placeholder span' );
		$this->assertSame( 3, substr_count( $html, 'data-odv-initials=' ) );
		$this->assertDoesNotMatchRegularExpression( '#<span[^>]*data-odv-img#', $html );
	}

	public function test_the_image_fallback_script_is_enqueued_only_when_a_photo_is_rendered(): void {
		$this->registry( $this->payload() )->render_block( 'odavent-events/speakers', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events-image-fallback' ), $this->enqueued_scripts );

		$this->enqueued_scripts = array();
		$payload                = $this->payload();
		foreach ( $payload['speakers'] as $i => $speaker ) {
			$payload['speakers'][ $i ]['photo_url'] = null;
		}
		$this->registry( $payload )->render_block( 'odavent-events/speakers', array( 'eventId' => 42 ) );
		$this->assertSame( array(), $this->enqueued_scripts );
	}

	public function initials(): array {
		return array(
			'two words'        => array( 'Aïcha Koné', 'AK' ),
			'first and last'   => array( 'Jean Pierre Dupont', 'JD' ),
			'one word'         => array( 'Madonna', 'M' ),
			'lower case'       => array( 'éric de la Fontaine', 'ÉF' ),
			'extra spaces'     => array( "  Ada \t Lovelace  ", 'AL' ),
			'hyphen/apostrophe' => array( "Marie-Claire O'Neil", 'MN' ),
			'nothing to take'  => array( '!!! ???', '?' ),
			'cyrillic'         => array( 'Иван Петров', 'ИП' ),
		);
	}

	/**
	 * @dataProvider initials
	 */
	public function test_initials( string $name, string $expected ): void {
		$payload                         = $this->payload();
		$payload['speakers']             = array( array( 'id' => 1, 'name' => $name, 'photo_url' => null ) );
		$html                            = $this->render( $payload );

		$this->assertStringContainsString( 'aria-hidden="true">' . $expected . '</span>', $html );
	}

	public function test_initials_survive_invalid_utf8(): void {
		$payload             = $this->payload();
		$payload['speakers'] = array( array( 'id' => 1, 'name' => "Bad \xC3\x28 Name", 'photo_url' => null ) );

		$html = $this->render( $payload );

		$this->assertStringContainsString( 'odv-speakers__photo--placeholder', $html );
	}

	public function test_an_invalid_photo_url_becomes_a_placeholder(): void {
		$payload                           = $this->payload();
		$payload['speakers'][1]['photo_url'] = 'javascript:alert(1)';

		$html = $this->render( $payload );

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertSame( 2, substr_count( $html, 'odv-speakers__photo--placeholder' ) );
	}

	public function test_company_and_job_title_show_by_default_and_company_can_be_hidden(): void {
		$html = $this->render( $this->payload() );
		$this->assertStringContainsString( '<p class="odv-speakers__role">Fondateur</p>', $html );
		$this->assertStringContainsString( '<p class="odv-speakers__company">Odavent</p>', $html );

		$html = $this->render( $this->payload(), array( 'showCompany' => false ) );
		$this->assertStringContainsString( 'Fondateur', $html );
		$this->assertStringNotContainsString( 'odv-speakers__company', $html );
		$this->assertStringNotContainsString( 'Odavent<', $html );
	}

	public function test_the_bio_is_off_by_default(): void {
		$this->assertStringNotContainsString( 'odv-speakers__bio', $this->render( $this->payload() ) );

		$html = $this->render( $this->payload(), array( 'showBio' => true ) );
		$this->assertSame( 3, substr_count( $html, '<div class="odv-speakers__bio">' ), 'Kofi has no bio' );
		$this->assertStringContainsString( 'Marcel construit des outils', $html );
	}

	public function test_columns_are_a_class_modifier_clamped_to_two_through_four(): void {
		$cases = array( 'default' => array( null, 3 ), 'two' => array( 2, 2 ), 'four' => array( 4, 4 ), 'too many' => array( 9, 4 ), 'too few' => array( 1, 2 ), 'string' => array( '4', 4 ), 'junk' => array( 'abc', 3 ), 'array' => array( array( 1 ), 3 ) );
		foreach ( $cases as $label => $case ) {
			$atts = null === $case[0] ? array() : array( 'columns' => $case[0] );
			$this->assertStringContainsString( '<ul class="odv-speakers__list odv-speakers__list--cols-' . $case[1] . '">', $this->render( $this->payload(), $atts ), $label );
		}
	}

	public function test_limit_caps_the_number_of_speakers(): void {
		$this->assertCount( 2, $this->names( $this->render( $this->payload(), array( 'limit' => 2 ) ) ) );
		$this->assertSame( array( 'Aïcha Koné', 'Marcel Djaman' ), $this->names( $this->render( $this->payload(), array( 'limit' => '2' ) ) ) );
		$this->assertCount( 4, $this->names( $this->render( $this->payload(), array( 'limit' => 0 ) ) ) );
		$this->assertCount( 4, $this->names( $this->render( $this->payload(), array( 'limit' => -3 ) ) ) );
		$this->assertCount( 4, $this->names( $this->render( $this->payload(), array( 'limit' => 99 ) ) ) );
	}

	public function test_a_speaker_id_renders_that_speaker_as_a_single_card(): void {
		$html = $this->render( $this->payload(), array( 'speakerId' => 303, 'limit' => 1 ) );

		$this->assertSame( array( 'Kofi Mensah' ), $this->names( $html ) );
		$this->assertStringContainsString( 'odv-speakers__list--single', $html );
		$this->assertStringNotContainsString( 'odv-speakers__list--cols-', $html );
	}

	public function test_an_unknown_speaker_id_renders_nothing(): void {
		$this->assertSame( '', $this->render( $this->payload(), array( 'speakerId' => 999999 ) ) );
	}

	public function test_no_speakers_renders_nothing_not_an_empty_wrapper(): void {
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-minimal' ) ) );

		$payload             = $this->payload();
		$payload['speakers'] = array( array( 'id' => 1, 'name' => '   ' ), array( 'id' => 2 ), 'junk', null );
		$this->assertSame( '', $this->render( $payload ) );

		$payload['speakers'] = 'nope';
		$this->assertSame( '', $this->render( $payload ) );
	}

	public function test_a_speaker_without_a_name_is_skipped(): void {
		$payload                         = $this->payload();
		$payload['speakers'][0]['name']  = null;

		$this->assertSame( array( 'Marcel Djaman', 'Kofi Mensah', 'Fatoumata Diallo' ), $this->names( $this->render( $payload ) ) );
	}

	public function test_the_class_name_attribute_reaches_the_root(): void {
		$this->assertStringStartsWith( '<section class="odv-speakers is-style-x" data-odv-event="42">', trim( $this->render( $this->payload(), array( 'className' => 'is-style-x' ) ) ) );
	}

	public function test_hostile_speakers_are_escaped_and_have_no_active_markup(): void {
		$html = $this->render( $this->payload( array(), 'payload-v1-hostile' ) );

		$this->assertNotSame( '', $html );
		$this->assertDomSafe( $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '<script', $html );

		// A biography is organizer HTML through the allow-list: its text may mention "javascript:" but is inert text.
		$with_bio = $this->render( $this->payload( array(), 'payload-v1-hostile' ), array( 'showBio' => true ) );
		$this->assertDomSafe( $with_bio );
		$this->assertStringNotContainsString( '<script', $with_bio );
		$this->assertStringNotContainsString( 'href=', $with_bio );
		$this->assertSame( 4, substr_count( $html, 'odv-speakers__photo--placeholder' ), 'No hostile photo URL is used' );
	}

	public function test_it_works_in_the_registry_for_every_published_state(): void {
		foreach ( array( 'live', 'ended open', 'paused', 'cancelled', 'archived' ) as $state ) {
			$html = $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/speakers', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'Aïcha Koné', $html, $state );
			$this->assertStringNotContainsString( 'odv-notice', $html, $state );
		}
		$this->assertContains( 'odavent-events', $this->enqueued_styles );
	}
}

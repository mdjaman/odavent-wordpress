<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use Odavent\Events\Blocks\Renderers\Sponsors;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Tests\Support\BlockTestCase;

class SponsorsTest extends BlockTestCase {

	private function render( array $payload, array $atts = array() ): string {
		return ( new Sponsors() )->render( Event_View::from_payload( $payload ), $atts );
	}

	/**
	 * The <li> of a partner by the name it shows.
	 */
	private function item( string $html, string $name ): string {
		$this->assertSame( 1, preg_match( '#<li class="odv-sponsors__item[^"]*">(?:(?!</li>).)*' . preg_quote( $name, '#' ) . '.*?</li>#s', $html, $m ), $name );

		return $m[0];
	}

	private function partner( array $overrides = array() ): array {
		return array_merge( array( 'id' => 1, 'name' => 'Acme', 'type' => 'sponsor', 'tier' => null, 'description' => null, 'logo_url' => 'https://odavent.com/l.png', 'website' => 'https://acme.test', 'is_featured' => false, 'click_url' => null ), $overrides );
	}

	private function with_partners( array $partners ): array {
		$payload             = $this->payload();
		$payload['partners'] = $partners;

		return $payload;
	}

	public function test_it_groups_partners_by_type_with_a_heading_each(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringStartsWith( '<section class="odv-sponsors" data-odv-event="42">', trim( $html ) );
		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( array( 'Sponsors', 'Exhibitors', 'Media partners', 'Partners' ), $m[1] );
		$this->assertSame( 4, substr_count( $html, '<div class="odv-sponsors__group">' ) );
		$this->assertSame( 5, substr_count( $html, '<li class="odv-sponsors__item' ) );
	}

	public function test_type_both_gets_a_translated_heading_between_sponsors_and_exhibitors(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'Ex Co', 'type' => 'exhibitor' ) ),
				$this->partner( array( 'id' => 2, 'name' => 'Both Co', 'type' => 'both' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'Spons Co', 'type' => 'sponsor' ) ),
			)
		);
		$html    = $this->render( $payload );

		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( array( 'Sponsors', 'Sponsors and exhibitors', 'Exhibitors' ), $m[1] );
	}

	public function test_an_unknown_type_still_gets_the_humanised_fallback(): void {
		$html = $this->render( $this->with_partners( array( $this->partner( array( 'type' => 'vip-guest' ) ) ) ) );

		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( array( 'Vip guest' ), $m[1] );
	}

	public function test_payload_order_is_kept_inside_a_group_with_featured_first(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'Gold Co', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 2, 'name' => 'Zed Co' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'Alpha Co' ) ),
			)
		);
		$html = $this->render( $payload );

		$this->assertLessThan( strpos( $html, 'Zed Co' ), strpos( $html, 'Gold Co' ) );
		$this->assertLessThan( strpos( $html, 'Alpha Co' ), strpos( $html, 'Zed Co' ) );
		$this->assertSame( 1, substr_count( $html, 'odv-sponsors__item--featured' ) );
	}

	/**
	 * @return array[] Interleaved types, featured rows first as Odavent sends them.
	 */
	private function interleaved(): array {
		return $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'S1 featured sponsor', 'type' => 'sponsor', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 2, 'name' => 'E1 featured exhibitor', 'type' => 'exhibitor', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 3, 'name' => 'S2 sponsor', 'type' => 'sponsor' ) ),
				$this->partner( array( 'id' => 4, 'name' => 'E2 exhibitor', 'type' => 'exhibitor' ) ),
				$this->partner( array( 'id' => 5, 'name' => 'M1 media', 'type' => 'media' ) ),
				$this->partner( array( 'id' => 6, 'name' => 'S3 sponsor', 'type' => 'sponsor' ) ),
			)
		);
	}

	/**
	 * @return string[] Partner names (alt text or name text) in rendered order.
	 */
	private function rendered_names( string $html ): array {
		$names = array();
		foreach ( $this->xpath_items( $html ) as $item ) {
			$names[] = $item;
		}

		return $names;
	}

	/**
	 * @return string[]
	 */
	private function xpath_items( string $html ): array {
		$xpath = new \DOMXPath( $this->dom( $html ) );
		$out   = array();
		foreach ( $xpath->query( '//li[contains(@class,"odv-sponsors__item")]' ) as $li ) {
			$img   = $xpath->query( './/img', $li )->item( 0 );
			$out[] = null !== $img ? $img->getAttribute( 'alt' ) : trim( $xpath->query( './/span[contains(@class,"odv-sponsors__name")]', $li )->item( 0 )->textContent );
		}

		return $out;
	}

	public function test_ungrouped_mode_is_one_flat_list_in_payload_order(): void {
		$html = $this->render( $this->interleaved(), array( 'groupByType' => false ) );

		$this->assertSame( array( 'S1 featured sponsor', 'E1 featured exhibitor', 'S2 sponsor', 'E2 exhibitor', 'M1 media', 'S3 sponsor' ), $this->rendered_names( $html ) );
		$this->assertSame( 1, substr_count( $html, '<ul class=' ), 'one list' );
		$this->assertStringNotContainsString( 'odv-sponsors__group', $html );
	}

	public function test_grouped_mode_orders_the_groups_but_keeps_payload_order_inside_each(): void {
		$html = $this->render( $this->interleaved() );

		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( array( 'Sponsors', 'Exhibitors', 'Media partners' ), $m[1] );
		$this->assertSame( array( 'S1 featured sponsor', 'S2 sponsor', 'S3 sponsor', 'E1 featured exhibitor', 'E2 exhibitor', 'M1 media' ), $this->rendered_names( $html ) );
	}

	public function test_a_group_holding_a_featured_partner_comes_before_the_groups_without_one(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'E1 featured exhibitor', 'type' => 'exhibitor', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 2, 'name' => 'S1 sponsor', 'type' => 'sponsor' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'P1 partner', 'type' => 'partner' ) ),
				$this->partner( array( 'id' => 4, 'name' => 'E2 exhibitor', 'type' => 'exhibitor' ) ),
				$this->partner( array( 'id' => 5, 'name' => 'S2 sponsor', 'type' => 'sponsor' ) ),
			)
		);
		$html = $this->render( $payload );

		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( array( 'Exhibitors', 'Sponsors', 'Partners' ), $m[1] );
		$this->assertSame( array( 'E1 featured exhibitor', 'E2 exhibitor', 'S1 sponsor', 'S2 sponsor', 'P1 partner' ), $this->rendered_names( $html ) );
	}

	public function test_several_featured_groups_keep_the_fixed_order_among_themselves(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'P1 featured partner', 'type' => 'partner', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 2, 'name' => 'M1 featured media', 'type' => 'media', 'is_featured' => true ) ),
				$this->partner( array( 'id' => 3, 'name' => 'S1 sponsor', 'type' => 'sponsor' ) ),
				$this->partner( array( 'id' => 4, 'name' => 'W1 host', 'type' => 'workshop_host' ) ),
				$this->partner( array( 'id' => 5, 'name' => 'E1 exhibitor', 'type' => 'exhibitor' ) ),
			)
		);
		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $this->render( $payload ), $m );

		$this->assertSame( array( 'Media partners', 'Partners', 'Sponsors', 'Exhibitors', 'Workshop host' ), $m[1] );
	}

	public function test_a_non_ascii_type_is_lower_cased_safely(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'A', 'type' => 'SPONSOR' ) ),
				$this->partner( array( 'id' => 2, 'name' => 'B', 'type' => 'Sponsor' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'C', 'type' => 'ÉCOLES' ) ),
				$this->partner( array( 'id' => 4, 'name' => 'D', 'type' => 'écoles' ) ),
			)
		);
		$html = $this->render( $payload );

		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );
		$this->assertSame( 'Sponsors', $m[1][0], 'ASCII types still fold case' );
		$this->assertCount( 3, $m[1], 'SPONSOR and Sponsor share a group; the two accented spellings are never mangled into byte garbage' );
		foreach ( $m[1] as $title ) {
			$this->assertSame( 1, preg_match( '//u', $title ), 'valid UTF-8: ' . $title );
		}
	}

	public function test_known_groups_come_in_a_fixed_order_then_unknown_types_by_appearance(): void {
		$payload = $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'M', 'type' => 'media' ) ),
				$this->partner( array( 'id' => 2, 'name' => 'W', 'type' => 'workshop_host' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'S', 'type' => 'sponsor' ) ),
			)
		);
		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $this->render( $payload ), $m );

		$this->assertSame( array( 'Sponsors', 'Media partners', 'Workshop host' ), $m[1] );
	}

	public function test_an_unknown_type_is_humanised_and_escaped(): void {
		$payload = $this->with_partners( array( $this->partner( array( 'type' => 'tech<b>_"hub' ) ) ) );
		$html    = $this->render( $payload );

		$this->assertMatchesRegularExpression( '#odv-sponsors__group-title">Tech &quot;hub</h3>#', $html );
		$this->assertDomSafe( $html );
	}

	public function test_a_missing_type_falls_in_the_partners_group(): void {
		$payload = $this->with_partners( array( $this->partner( array( 'type' => null ) ), $this->partner( array( 'id' => 2, 'name' => 'B', 'type' => 'partner' ) ) ) );
		$html    = $this->render( $payload );

		$this->assertSame( 1, substr_count( $html, 'odv-sponsors__group-title' ) );
		$this->assertStringContainsString( '>Partners</h3>', $html );
	}

	public function test_grouping_can_be_turned_off(): void {
		$html = $this->render( $this->payload(), array( 'groupByType' => false ) );

		$this->assertStringNotContainsString( 'odv-sponsors__group', $html );
		$this->assertStringNotContainsString( '<h3', $html );
		$this->assertSame( 1, substr_count( $html, '<ul class=' ) );
		$this->assertSame( 5, substr_count( $html, '<li class="odv-sponsors__item' ) );
	}

	public function test_a_featured_partner_links_to_the_tracked_url_others_to_their_website(): void {
		$html = $this->render( $this->payload() );

		$this->assertStringContainsString( 'href="https://odavent.com/go/partner/401?src=directory_featured"', $this->item( $html, 'Orange' ) );
		$this->assertStringNotContainsString( 'orange.ci', $this->item( $html, 'Orange' ) );
		$this->assertStringContainsString( 'href="https://societegenerale.ci"', $this->item( $html, 'Société Générale' ) );
	}

	public function test_every_link_is_sponsored_and_noopener(): void {
		$html = $this->render( $this->payload() );

		preg_match_all( '#<a [^>]*>#', $html, $m );
		$this->assertCount( 4, $m[0], 'Radio Jam has no website' );
		foreach ( $m[0] as $tag ) {
			$this->assertStringContainsString( 'rel="sponsored noopener"', $tag );
		}
	}

	public function test_a_logo_is_lazy_with_the_name_as_alt_inside_the_link(): void {
		$item = $this->item( $this->render( $this->payload() ), 'Société Générale' );

		$this->assertMatchesRegularExpression( '#<a class="odv-sponsors__link" href="https://societegenerale\.ci" rel="sponsored noopener"><img class="odv-sponsors__logo" src="https://odavent\.com/storage/partners/sg\.png" alt="Société Générale" loading="lazy"[^>]*></a>#', $item );
	}

	public function test_a_logo_carries_the_fallback_marker_and_a_name_span_does_not(): void {
		$html = $this->render( $this->payload() );

		$this->assertMatchesRegularExpression( '#<img class="odv-sponsors__logo" src="https://odavent\.com/storage/partners/sg\.png" alt="Société Générale"[^>]* data-odv-img="logo">#', $html );
		$this->assertSame( substr_count( $html, '<img ' ), substr_count( $html, 'data-odv-img="logo"' ) );
		$this->assertDoesNotMatchRegularExpression( '#<span[^>]*data-odv-img#', $html );
	}

	public function test_the_image_fallback_script_is_enqueued_only_when_a_logo_is_rendered(): void {
		$this->registry( $this->payload() )->render_block( 'odavent-events/sponsors', array( 'eventId' => 42 ) );
		$this->assertSame( array( 'odavent-events-image-fallback' ), $this->enqueued_scripts );

		$this->enqueued_scripts = array();
		$payload                = $this->with_partners( array( $this->partner( array( 'logo_url' => null ) ) ) );
		$this->registry( $payload )->render_block( 'odavent-events/sponsors', array( 'eventId' => 42 ) );
		$this->assertSame( array(), $this->enqueued_scripts );
	}

	public function test_a_partner_without_a_logo_shows_its_name_as_text(): void {
		$item = $this->item( $this->render( $this->payload() ), 'Station F Afrique' );

		$this->assertStringNotContainsString( '<img', $item );
		$this->assertStringContainsString( '<span class="odv-sponsors__name">Station F Afrique</span>', $item );
		$this->assertStringContainsString( 'href="https://stationf.co"', $item );
	}

	public function test_a_partner_with_a_logo_and_no_link_is_just_the_logo(): void {
		$item = $this->item( $this->render( $this->payload() ), 'Radio Jam' );

		$this->assertStringContainsString( '<img class="odv-sponsors__logo"', $item );
		$this->assertStringNotContainsString( '<a ', $item );
	}

	public function test_an_invalid_logo_becomes_the_name(): void {
		$payload = $this->with_partners( array( $this->partner( array( 'logo_url' => 'javascript:alert(1)' ) ), $this->partner( array( 'id' => 2, 'name' => 'Two', 'logo_url' => 'data:image/png;base64,AAAA' ) ) ) );
		$html    = $this->render( $payload );

		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'data:', $html );
		$this->assertStringContainsString( '<span class="odv-sponsors__name">Acme</span>', $html );
	}

	public function test_an_invalid_click_url_falls_back_to_the_website(): void {
		$payload = $this->with_partners( array( $this->partner( array( 'is_featured' => true, 'click_url' => 'javascript:alert(1)' ) ) ) );

		$this->assertStringContainsString( 'href="https://acme.test"', $this->render( $payload ) );
	}

	public function test_descriptions_are_optional_and_sanitised(): void {
		$this->assertStringNotContainsString( 'odv-sponsors__description', $this->render( $this->payload() ) );

		$html = $this->render( $this->payload(), array( 'showDescription' => true ) );
		$this->assertStringContainsString( '<div class="odv-sponsors__description">Partenaire principal du sommet.</div>', $html );
		$this->assertSame( 3, substr_count( $html, 'odv-sponsors__description' ), 'three of five have one' );
	}

	public function test_columns_are_a_class_modifier(): void {
		$cases = array( 'default' => array( null, 4 ), 'two' => array( 2, 2 ), 'six' => array( 6, 6 ), 'big' => array( 12, 6 ), 'small' => array( 0, 1 ), 'junk' => array( 'x', 4 ) );
		foreach ( $cases as $label => $case ) {
			$atts = null === $case[0] ? array() : array( 'columns' => $case[0] );
			$this->assertStringContainsString( 'odv-sponsors__list odv-sponsors__list--cols-' . $case[1] . '"', $this->render( $this->payload(), $atts ), $label );
		}
	}

	public function test_no_partners_renders_nothing(): void {
		$this->assertSame( '', $this->render( $this->payload( array(), 'payload-v1-minimal' ) ) );
		$this->assertSame( '', $this->render( $this->with_partners( array( $this->partner( array( 'name' => '' ) ), 'junk', null ) ) ) );
	}

	public function test_hostile_partners_are_escaped_and_never_linked(): void {
		foreach ( array( true, false ) as $group ) {
			$html = $this->render( $this->payload( array(), 'payload-v1-hostile' ), array( 'groupByType' => $group, 'showDescription' => true ) );

			$this->assertNotSame( '', $html );
			$this->assertDomSafe( $html );
			$this->assertStringNotContainsString( '<a ', $html, 'every hostile URL is dropped' );
			$this->assertStringNotContainsString( '<img', $html );
			$this->assertStringNotContainsString( 'javascript:', $html );
			$this->assertStringNotContainsString( 'vbscript:', $html );
		}
	}

	public function test_it_renders_in_every_state(): void {
		foreach ( array( 'live', 'ended open', 'paused', 'cancelled', 'archived' ) as $state ) {
			$html = $this->registry( $this->payload( self::state_overrides()[ $state ] ) )->render_block( 'odavent-events/sponsors', array( 'eventId' => 42 ) );
			$this->assertStringContainsString( 'odv-sponsors__item', $html, $state );
		}
	}

	/**
	 * Group headings in order.
	 *
	 * @return string[]
	 */
	private function titles( string $html ): array {
		preg_match_all( '#<h3 class="odv-sponsors__group-title">(.*?)</h3>#', $html, $m );

		return $m[1];
	}

	private function tiered(): array {
		return $this->with_partners(
			array(
				$this->partner( array( 'id' => 1, 'name' => 'Gold One', 'tier' => 'Gold' ) ),
				$this->partner( array( 'id' => 2, 'name' => 'Silver One', 'tier' => 'Silver' ) ),
				$this->partner( array( 'id' => 3, 'name' => 'Gold Two', 'tier' => 'gold', 'type' => 'exhibitor' ) ),
				$this->partner( array( 'id' => 4, 'name' => 'Plain Co', 'tier' => null ) ),
			)
		);
	}

	public function test_group_by_tier_makes_one_group_per_tier_in_payload_order_with_untiered_last(): void {
		$html = $this->render( $this->tiered(), array( 'groupBy' => 'tier' ) );

		$this->assertSame( array( 'Gold', 'Silver', 'Other partners' ), $this->titles( $html ) );
		$this->assertLessThan( strpos( $html, 'Silver One' ), strpos( $html, 'Gold Two' ), 'the lower-case spelling joins the first Gold group' );
		$this->assertLessThan( strpos( $html, 'Plain Co' ), strpos( $html, 'Silver One' ) );
		$this->assertSame( 4, substr_count( $html, '<li class="odv-sponsors__item' ) );
	}

	public function test_group_by_tier_without_any_tier_stays_a_flat_list(): void {
		$html = $this->render( $this->with_partners( array( $this->partner(), $this->partner( array( 'id' => 2, 'name' => 'B Co' ) ) ) ), array( 'groupBy' => 'tier' ) );

		$this->assertSame( array(), $this->titles( $html ) );
		$this->assertSame( 2, substr_count( $html, '<li class="odv-sponsors__item' ) );
	}

	public function test_the_tier_attribute_shows_only_that_tier_ignoring_case(): void {
		$html = $this->render( $this->tiered(), array( 'groupBy' => 'none', 'tier' => ' GOLD ' ) );

		$this->assertStringContainsString( 'Gold One', $html );
		$this->assertStringContainsString( 'Gold Two', $html );
		$this->assertStringNotContainsString( 'Silver One', $html );
		$this->assertStringNotContainsString( 'Plain Co', $html );
	}

	public function test_a_tier_nobody_has_leaves_nothing_to_show(): void {
		$this->assertSame( '', $this->render( $this->tiered(), array( 'tier' => 'Platinum' ) ) );
	}

	public function test_group_by_none_and_the_older_group_by_type_switch_off_give_a_flat_list(): void {
		$this->assertSame( array(), $this->titles( $this->render( $this->tiered(), array( 'groupBy' => 'none' ) ) ) );
		$this->assertSame( array(), $this->titles( $this->render( $this->tiered(), array( 'groupBy' => '', 'groupByType' => false ) ) ) );
	}

	public function test_an_explicit_tier_grouping_wins_over_a_group_by_type_switched_off(): void {
		$this->assertSame( array( 'Gold', 'Silver', 'Other partners' ), $this->titles( $this->render( $this->tiered(), array( 'groupBy' => 'tier', 'groupByType' => false ) ) ) );
	}

	public function test_an_explicit_group_by_type_wins_over_the_older_switch(): void {
		$this->assertSame( array( 'Sponsors', 'Exhibitors' ), $this->titles( $this->render( $this->tiered(), array( 'groupBy' => 'type', 'groupByType' => false ) ) ) );
	}

	public function test_an_unknown_group_by_value_falls_back_to_type(): void {
		$this->assertSame( array( 'Sponsors', 'Exhibitors' ), $this->titles( $this->render( $this->tiered(), array( 'groupBy' => 'level' ) ) ) );
	}

	public function test_a_hostile_tier_is_escaped_in_its_heading(): void {
		$html = $this->render( $this->with_partners( array( $this->partner( array( 'tier' => '<script>alert(1)</script>Gold' ) ) ) ), array( 'groupBy' => 'tier' ) );

		$this->assertStringNotContainsString( '<script>', $html );
	}
}

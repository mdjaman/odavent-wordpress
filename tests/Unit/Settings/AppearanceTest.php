<?php
namespace Odavent\Events\Tests\Unit\Settings;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Odavent\Events\Settings\Appearance;
use Odavent\Events\Tests\TestCase;

class AppearanceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		// Load the plugin's own autoloader (once per process) so src/ classes resolve.
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';
	}

	public function test_defaults_mean_nothing_changed(): void {
		$this->assertSame(
			array(
				'accent'       => '',
				'contrast'     => '',
				'text'         => '',
				'border'       => '',
				'radius'       => '',
				'spacing'      => '',
				'button_style' => 'filled',
			),
			Appearance::DEFAULTS
		);
		$this->assertSame( Appearance::DEFAULTS, Appearance::sanitize( null ) );
		$this->assertSame( Appearance::DEFAULTS, Appearance::sanitize( 'red' ) );
		$this->assertSame( Appearance::DEFAULTS, Appearance::sanitize( array() ) );
		$this->assertSame( '', Appearance::css( Appearance::DEFAULTS ) );
	}

	public function test_valid_values_are_normalised(): void {
		$out = Appearance::sanitize(
			array(
				'accent'       => ' #3730A3 ',
				'contrast'     => '#FFF',
				'text'         => '#1A1A1a',
				'border'       => '#abc',
				'radius'       => '12',
				'spacing'      => 'roomy',
				'button_style' => 'outline',
				'evil'         => 'x',
			)
		);

		$this->assertSame(
			array(
				'accent'       => '#3730a3',
				'contrast'     => '#ffffff',
				'text'         => '#1a1a1a',
				'border'       => '#aabbcc',
				'radius'       => 12,
				'spacing'      => 'roomy',
				'button_style' => 'outline',
			),
			$out
		);
	}

	public function test_hostile_values_are_rejected_or_clamped(): void {
		$out = Appearance::sanitize(
			array(
				'accent'       => 'red;}body{display:none',
				'contrast'     => '#12',
				'text'         => 'url(x)',
				'border'       => "#fff\n;}",
				'radius'       => '-5',
				'spacing'      => 'huge;}body{',
				'button_style' => 'filled;}a{',
			)
		);

		$this->assertSame( '', $out['accent'] );
		$this->assertSame( '', $out['contrast'] );
		$this->assertSame( '', $out['text'] );
		$this->assertSame( '', $out['border'] );
		$this->assertSame( 0, $out['radius'] );
		$this->assertSame( '', $out['spacing'] );
		$this->assertSame( 'filled', $out['button_style'] );

		$this->assertSame( 24, Appearance::sanitize( array( 'radius' => '9999' ) )['radius'] );
		$this->assertSame( 24, Appearance::sanitize( array( 'radius' => 9999 ) )['radius'] );
		$this->assertSame( '', Appearance::sanitize( array( 'radius' => 'url(x)' ) )['radius'] );
		$this->assertSame( '', Appearance::sanitize( array( 'radius' => array( 5 ) ) )['radius'] );
		$this->assertSame( '', Appearance::sanitize( array( 'accent' => array( '#fff' ) ) )['accent'] );
		$this->assertSame( 'filled', Appearance::sanitize( array( 'button_style' => 'ghost' ) )['button_style'] );

		// Nothing survived, so no CSS at all.
		$this->assertSame( '', Appearance::css( array( 'accent' => 'red;}body{display:none', 'spacing' => 'x' ) ) );
	}

	public function test_css_only_ever_contains_hex_colours_integers_and_fixed_keywords(): void {
		$css = Appearance::css(
			array(
				'accent'       => 'red;}body{display:none',
				'contrast'     => '#12',
				'text'         => '#ABCDEF',
				'border'       => 'url(x)',
				'radius'       => '9999',
				'spacing'      => 'roomy',
				'button_style' => 'filled',
			)
		);

		$this->assertSame( ':root{--odv-text:#abcdef;--odv-radius:24px;--odv-gap:1.5rem;}', $css );
		$this->assertMatchesRegularExpression( '/^[:a-z0-9#;{}.,\s_>-]*$/i', $css );
		$this->assertStringNotContainsString( 'body', $css );
		$this->assertStringNotContainsString( 'url(', $css );
	}

	public function test_css_sanitises_what_it_is_given_even_when_stored_data_is_not_trusted(): void {
		$css = Appearance::css( array( 'accent' => '#12345g', 'radius' => '1;}x{', 'button_style' => '</style>' ) );

		$this->assertSame( '', $css );
	}

	public function test_only_changed_fields_are_emitted(): void {
		$this->assertSame( ':root{--odv-border:#cccccc;}', Appearance::css( array( 'border' => '#CCC' ) ) );
		$this->assertSame( ':root{--odv-radius:0px;}', Appearance::css( array( 'radius' => 0 ) ) );
		$this->assertSame( ':root{--odv-gap:1rem;}', Appearance::css( array( 'spacing' => 'comfortable' ) ) );
		$this->assertSame( '', Appearance::css( array( 'button_style' => 'filled' ) ) );
	}

	public function test_a_light_accent_gets_dark_text_automatically(): void {
		$this->assertSame( '#000000', Appearance::auto_contrast( '#ffeb3b' ) );
		$this->assertSame( '#ffffff', Appearance::auto_contrast( '#3730a3' ) );

		$this->assertSame( ':root{--odv-accent:#ffeb3b;--odv-accent-contrast:#000000;}', Appearance::css( array( 'accent' => '#ffeb3b' ) ) );
		$this->assertSame( ':root{--odv-accent:#3730a3;--odv-accent-contrast:#ffffff;}', Appearance::css( array( 'accent' => '#3730a3' ) ) );
	}

	public function test_manual_contrast_wins(): void {
		$css = Appearance::css( array( 'accent' => '#ffeb3b', 'contrast' => '#ffffff' ) );

		$this->assertSame( ':root{--odv-accent:#ffeb3b;--odv-accent-contrast:#ffffff;}', $css );
		$this->assertSame( 1, substr_count( $css, '--odv-accent-contrast' ) );
	}

	public function test_spacing_and_radius_map_to_variables(): void {
		$this->assertSame( ':root{--odv-gap:0.75rem;}', Appearance::css( array( 'spacing' => 'compact' ) ) );
		$this->assertSame( ':root{--odv-gap:1rem;}', Appearance::css( array( 'spacing' => 'comfortable' ) ) );
		$this->assertSame( ':root{--odv-gap:1.5rem;}', Appearance::css( array( 'spacing' => 'roomy' ) ) );
		$this->assertSame( ':root{--odv-radius:12px;}', Appearance::css( array( 'radius' => 12 ) ) );
	}

	public function test_outline_button_rule(): void {
		$css = Appearance::css( array( 'button_style' => 'outline' ) );

		$this->assertStringStartsWith( '.odv-register .odv-register__button', $css );
		$this->assertStringNotContainsString( ':root', $css );
		$this->assertStringContainsString( 'background:transparent;color:var(--_accent)', $css );
		// Hover fills the button again so the text never ends up on a transparent background in the contrast colour.
		$this->assertStringContainsString( ':hover{background:var(--_accent);color:var(--_contrast)}', $css );
		// The disabled look of a closed registration is left alone.
		foreach ( explode( '}', rtrim( $css, '}' ) ) as $rule ) {
			$this->assertStringContainsString( '.odv-register .odv-register__button:not(.odv-register__button--disabled):not([aria-disabled="true"])', $rule );
		}
		$this->assertStringContainsString( '.odv-tickets .odv-tickets__register', $css );
		$this->assertSame( substr_count( $css, '{' ), substr_count( $css, '}' ) );

		// It comes after any variables, and both can be set together.
		$both = Appearance::css( array( 'button_style' => 'outline', 'radius' => 4 ) );
		$this->assertStringStartsWith( ':root{--odv-radius:4px;}.odv-register', $both );
	}

	public function test_contrast_ratio_is_the_wcag_formula(): void {
		$this->assertSame( 21.0, Appearance::contrast_ratio( '#000000', '#ffffff' ) );
		$this->assertSame( 21.0, Appearance::contrast_ratio( '#fff', '#000' ) );
		$this->assertSame( 1.0, Appearance::contrast_ratio( '#777777', '#777777' ) );
		$this->assertSame( 4.48, Appearance::contrast_ratio( '#777777', '#ffffff' ) );
		$this->assertSame( Appearance::contrast_ratio( '#3730a3', '#ffffff' ), Appearance::contrast_ratio( '#ffffff', '#3730a3' ) );
	}

	public function test_the_final_css_goes_through_the_filter(): void {
		Filters\expectApplied( 'odavent_events_appearance_css' )->once()->with( ':root{--odv-radius:4px;}', \Mockery::type( 'array' ) );

		$this->assertSame( ':root{--odv-radius:4px;}', Appearance::css( array( 'radius' => 4 ) ) );
	}

	public function test_a_filter_can_replace_the_css(): void {
		Filters\expectApplied( 'odavent_events_appearance_css' )->once()->andReturn( '/* mine */' );

		$this->assertSame( '/* mine */', Appearance::css( array( 'radius' => 4 ) ) );
	}

	public function test_a_filter_returning_a_non_string_is_ignored(): void {
		Filters\expectApplied( 'odavent_events_appearance_css' )->once()->andReturn( array( 'nope' ) );

		$this->assertSame( ':root{--odv-radius:4px;}', Appearance::css( array( 'radius' => 4 ) ) );
	}

	public function test_nothing_set_means_the_filter_is_not_even_applied(): void {
		Filters\expectApplied( 'odavent_events_appearance_css' )->never();

		$this->assertSame( '', Appearance::css( Appearance::DEFAULTS ) );
	}
}

<?php
namespace Odavent\Events\Tests\Unit\Render;

use PHPUnit\Framework\TestCase;

/**
 * Static checks of the default stylesheet (no browser here): the contracts a theme author relies on.
 */
class CssTest extends TestCase {

	/** @var string */
	private $css;

	protected function setUp(): void {
		parent::setUp();
		$this->css = (string) file_get_contents( dirname( __DIR__, 3 ) . '/assets/css/odavent.css' );
	}

	public function test_it_stays_small_and_balanced(): void {
		$this->assertLessThanOrEqual( 260, substr_count( $this->css, "\n" ) );
		$this->assertSame( substr_count( $this->css, '{' ), substr_count( $this->css, '}' ) );
	}

	public function test_it_never_uses_important_inline_styles_or_external_assets(): void {
		$this->assertStringNotContainsString( '!important', $this->css );
		$this->assertDoesNotMatchRegularExpression( '#https?://|@import|url\(#i', $this->css );
	}

	public function test_links_and_buttons_beat_common_theme_link_rules(): void {
		foreach ( array(
			'.odv-register .odv-register__button',
			'.odv-tickets .odv-tickets__register',
			'.odv-calendar .odv-calendar__link',
			'.odv-sponsors a',
		) as $selector ) {
			$this->assertStringContainsString( $selector, $this->css );
		}

		// The old low-specificity forms must be gone.
		$this->assertDoesNotMatchRegularExpression( '/^\.odv-register__button[ ,{]/m', $this->css );
		$this->assertDoesNotMatchRegularExpression( '/^\.odv-calendar__link[ ,{]/m', $this->css );
	}

	public function test_the_button_text_colour_is_a_documented_variable(): void {
		$this->assertStringContainsString( 'var(--odv-accent-contrast, #fff)', $this->css );
		$this->assertStringContainsString( '--odv-accent-contrast', substr( $this->css, 0, strpos( $this->css, '*/' ) ) );
		$this->assertSame( 0, preg_match( '/color:\s*#fff\s*[;}]/', $this->css ) );
	}

	public function test_muted_text_has_a_plain_fallback_then_a_color_mix_upgrade(): void {
		$plain = strpos( $this->css, '--_muted: var(--odv-muted, #5b6270)' );
		$mix   = strpos( $this->css, 'color-mix(in srgb, currentColor 70%, transparent)' );

		$this->assertNotFalse( $plain );
		$this->assertNotFalse( $mix );
		$this->assertLessThan( $mix, $plain, 'The fallback must be declared first.' );
		$this->assertMatchesRegularExpression( '/@supports\s*\(color:\s*color-mix\([^)]*\)\)\s*\{[^@]*color-mix/s', $this->css );
	}
}

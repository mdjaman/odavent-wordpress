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
		$this->assertLessThanOrEqual( 330, substr_count( $this->css, "\n" ) );
		$this->assertSame( substr_count( $this->css, '{' ), substr_count( $this->css, '}' ) );
	}

	public function test_hidden_elements_stay_hidden_despite_display_rules(): void {
		// The filter script and the countdown script toggle the hidden attribute; a display rule on the same element would beat it.
		foreach ( array( '.odv-agenda__session[hidden]', '.odv-agenda__day[hidden]', '.odv-countdown [hidden]', '.odv-countdown[hidden]' ) as $selector ) {
			$this->assertStringContainsString( $selector, $this->css );
		}
	}

	public function test_column_modifiers_set_a_variable_for_every_value_the_renderers_allow(): void {
		foreach ( range( 2, 4 ) as $n ) {
			$this->assertStringContainsString( ".odv-speakers__list--cols-{$n} { --_cols: {$n}; }", $this->css );
		}
		foreach ( range( 1, 6 ) as $n ) {
			$this->assertStringContainsString( ".odv-sponsors__list--cols-{$n} { --_cols: {$n}; }", $this->css );
		}
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

	public function test_disabled_buttons_keep_their_look_on_hover(): void {
		// The hover rule must not apply to a disabled button (its text would turn the contrast colour on a transparent background).
		$this->assertMatchesRegularExpression(
			'/\.odv-register \.odv-register__button:not\(\.odv-register__button--disabled\):not\(\[aria-disabled="true"\]\):hover/',
			$this->css
		);
		$this->assertDoesNotMatchRegularExpression( '/\.odv-register \.odv-register__button:hover/', $this->css );
	}

	public function test_every_rule_has_a_selector_and_balanced_braces_in_order(): void {
		$depth = 0;
		$css   = (string) preg_replace( '#/\*.*?\*/#s', '', $this->css );
		foreach ( str_split( $css ) as $char ) {
			if ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				$this->assertGreaterThanOrEqual( 0, $depth, 'A closing brace before its opening brace.' );
			}
		}
		$this->assertSame( 0, $depth );
		$this->assertDoesNotMatchRegularExpression( '/(^|[;}])\s*\{/', $css, 'A rule without a selector.' );
		$this->assertStringNotContainsString( '!important', $css );
	}

	public function test_every_class_used_by_a_template_is_styled_or_rooted_in_the_stylesheet(): void {
		$templates = glob( dirname( __DIR__, 3 ) . '/templates/*.php' );
		$this->assertNotEmpty( $templates );

		foreach ( $templates as $file ) {
			preg_match_all( '/(?<![\w-])odv-[a-z0-9_-]*[a-z0-9](?![\w-])/', (string) file_get_contents( $file ), $m );
			foreach ( array_unique( $m[0] ) as $class ) {
				$this->assertMatchesRegularExpression( '/\.' . preg_quote( $class, '/' ) . '(?![a-z0-9_-])/', $this->css, basename( $file ) . ' uses .' . $class );
			}
		}
	}
}

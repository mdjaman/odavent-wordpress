<?php
namespace Odavent\Events\Tests\Unit\Render;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Odavent\Events\Render\Sanitizer;
use Odavent\Events\Tests\Support\RenderTestCase;
use Odavent\Events\Tests\Support\WpStubs;

class SanitizerTest extends RenderTestCase {

	/**
	 * Every string anywhere in the hostile payload. Data providers run before setUp(): no WordPress doubles here.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function hostile_strings(): array {
		$json = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/payload-v1-hostile.json' ), true );
		$out  = array();
		$walk = function ( $node, string $path ) use ( &$walk, &$out ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $key => $value ) {
					$walk( $value, $path . '.' . $key );
				}
			} elseif ( is_string( $node ) ) {
				$out[ $path . ' (' . strlen( $node ) . ' bytes)' ] = array( $node );
			}
		};
		$walk( $json, '' );

		return $out;
	}

	public function test_hostile_provider_walks_the_whole_payload(): void {
		$this->assertGreaterThan( 100, count( $this->hostile_strings() ) );
	}

	/**
	 * @dataProvider hostile_strings
	 */
	public function test_text_never_leaves_a_tag( string $input ): void {
		$out = Sanitizer::text( $input );

		$this->assertDoesNotMatchRegularExpression( '/<\s*\/?\s*[a-z!?]/i', $out );
		$this->assertSame( trim( $out ), $out );
	}

	/**
	 * @dataProvider hostile_strings
	 */
	public function test_html_output_is_only_allowed_tags_and_safe_attributes( string $input ): void {
		$out = Sanitizer::html( $input );

		$this->assert_safe_html( $out );
	}

	/**
	 * @dataProvider hostile_strings
	 */
	public function test_url_is_empty_or_a_plain_http_url( string $input ): void {
		$out = Sanitizer::url( $input );

		if ( '' !== $out ) {
			$this->assertMatchesRegularExpression( '#^https?://[^\s<>"]+$#i', $out );
		}
		$this->assertDoesNotMatchRegularExpression( '/script:|data:|[\x00-\x1f\x7f<>"]/i', $out );
	}

	/**
	 * Parses sanitised HTML and asserts the structure, not the formatting.
	 */
	private function assert_safe_html( string $html ): void {
		if ( '' === $html ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );

		$allowed = array( 'div', 'p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'a', 'h3', 'h4', 'h5' );
		foreach ( $doc->getElementsByTagName( '*' ) as $element ) {
			$this->assertContains( $element->tagName, $allowed, 'Tag not in allow-list: ' . $element->tagName );

			foreach ( $element->attributes as $attr ) {
				if ( 'div' === $element->tagName ) {
					$this->assertSame( 'id', $attr->name );
					continue;
				}
				$this->assertContains( $attr->name, array( 'href', 'rel' ), 'Attribute not allowed: ' . $attr->name );
			}

			if ( 'a' === $element->tagName ) {
				$this->assertSame( 'nofollow noopener', $element->getAttribute( 'rel' ) );
				if ( $element->hasAttribute( 'href' ) ) {
					$this->assertMatchesRegularExpression( '#^https?://#i', $element->getAttribute( 'href' ) );
				}
			}
		}
	}

	public function test_text_strips_tags_and_trims(): void {
		$this->assertSame( 'Hello world', Sanitizer::text( "  <b>Hello</b> world \n" ) );
		$this->assertSame( '', Sanitizer::text( null ) );
		$this->assertSame( '', Sanitizer::text( '<script>alert(1)</script>' ) );
	}

	public function test_html_removes_script_and_event_handlers(): void {
		$out = Sanitizer::html( '<p onclick="x()">Hi</p><script>alert(1)</script><img src=x onerror=alert(1)>' );

		$this->assertStringContainsString( '<p>Hi</p>', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringNotContainsString( '<img', $out );
		$this->assertStringNotContainsString( 'onerror', $out );
		$this->assertStringNotContainsString( 'onclick', $out );
	}

	public function test_html_keeps_allowed_tags(): void {
		$in  = '<h3>T</h3><h4>T</h4><h5>T</h5><p>a<br>b <strong>s</strong> <em>e</em> <b>b</b> <i>i</i></p><ul><li>x</li></ul><ol><li>y</li></ol>';
		$out = Sanitizer::html( $in );

		foreach ( array( '<h3>T</h3>', '<h4>', '<h5>', '<br>', '<strong>s</strong>', '<em>e</em>', '<b>b</b>', '<i>i</i>', '<ul><li>x</li></ul>', '<ol><li>y</li></ol>' ) as $needle ) {
			$this->assertStringContainsString( $needle, $out );
		}
	}

	public function test_html_strips_style_class_and_id_attributes(): void {
		$out = Sanitizer::html( '<p style="position:fixed" class="x" id="y">Hi</p>' );

		$this->assertSame( '<p>Hi</p>', $out );
	}

	public function test_links_lose_target_and_get_a_forced_rel(): void {
		$out = Sanitizer::html( '<a href="https://example.com/x?a=1&b=2" target="_blank" rel="opener">Go</a>' );

		$this->assertStringNotContainsString( 'target', $out );
		$this->assertStringNotContainsString( 'rel="opener', $out );
		$this->assertSame( 1, substr_count( $out, 'rel=' ) );
		$this->assertStringContainsString( 'rel="nofollow noopener"', $out );
		$this->assertStringContainsString( 'href="https://example.com/x?a=1&#038;b=2"', $out );
	}

	public function anchor_quote_styles(): array {
		return array(
			'double quotes' => array( '<a href="https://x.com/a?b=1&amp;c=2">x</a>' ),
			'single quotes' => array( "<a href='https://x.com/a?b=1&amp;c=2'>x</a>" ),
			'unquoted'      => array( '<a href=https://x.com/a?b=1&amp;c=2>x</a>' ),
			'spaced equals' => array( "<a href = 'https://x.com/a?b=1&amp;c=2'>x</a>" ),
			'upper case'    => array( "<A HREF='https://x.com/a?b=1&amp;c=2'>x</A>" ),
		);
	}

	/**
	 * wp_kses is an identity function here, so the anchor rebuild sees the author's quote style untouched.
	 *
	 * @dataProvider anchor_quote_styles
	 */
	public function test_the_anchor_rebuild_keeps_the_href_whatever_the_quote_style( string $html ): void {
		Functions\when( 'wp_kses' )->returnArg();

		$out = Sanitizer::html( $html );

		$this->assertMatchesRegularExpression( '#^<a href="https://x\.com/a\?b=1&\#038;c=2" rel="nofollow noopener">x</a>$#i', $out );
	}

	public function test_the_anchor_rebuild_alone_strips_unlisted_attributes_and_target(): void {
		Functions\when( 'wp_kses' )->returnArg();

		$out = Sanitizer::html( "<a href='https://x.com' onclick='steal()' style=\"x:y\" target=_blank rel=opener data-x=1>x</a>" );

		$this->assertSame( '<a href="https://x.com" rel="nofollow noopener">x</a>', $out );
	}

	public function test_the_anchor_rebuild_alone_drops_a_bad_single_quoted_or_unquoted_href(): void {
		Functions\when( 'wp_kses' )->returnArg();

		$this->assertSame( '<a rel="nofollow noopener">x</a>', Sanitizer::html( "<a href='javascript:alert(1)'>x</a>" ) );
		$this->assertSame( '<a rel="nofollow noopener">x</a>', Sanitizer::html( '<a href=javascript:alert(1)>x</a>' ) );
		$this->assertSame( '<a rel="nofollow noopener">x</a>', Sanitizer::html( "<a href=''>x</a>" ) );
		$this->assertSame( '<a rel="nofollow noopener">x</a>', Sanitizer::html( '<a href>x</a>' ) );
	}

	public function test_a_greater_than_inside_a_quoted_attribute_does_not_end_the_tag(): void {
		Functions\when( 'wp_kses' )->returnArg();
		Filters\expectApplied( 'odavent_events_allowed_html' )->andReturn( array( 'a' => array( 'href' => true, 'title' => true ) ) );

		$out = Sanitizer::html( "<a title='a > b' href='https://x.com'>x</a>" );

		$this->assertSame( '<a title="a &gt; b" href="https://x.com" rel="nofollow noopener">x</a>', $out );
	}

	public function test_the_kses_double_can_keep_the_authors_single_quotes(): void {
		WpStubs::$kses_keep_quotes = true;

		$this->assertSame( "<a href='https://x.com/a'>x</a>", wp_kses( "<a href='https://x.com/a'>x</a>", array( 'a' => array( 'href' => true ) ), array( 'http', 'https' ) ) );
		$this->assertSame( '<a href="https://x.com/a">x</a>', wp_kses( '<a href="https://x.com/a">x</a>', array( 'a' => array( 'href' => true ) ), array( 'http', 'https' ) ) );

		// Through the real sanitiser: single-quoted in, link kept.
		$out = Sanitizer::html( "<p><a href='https://x.com/a' target='_blank'>x</a></p>" );
		$this->assertSame( '<p><a href="https://x.com/a" rel="nofollow noopener">x</a></p>', $out );
	}

	public function test_links_without_a_safe_href_keep_their_text_but_lose_the_href(): void {
		$bad = array(
			'javascript:alert(1)',
			'JaVaScRiPt:alert(1)',
			"java\tscript:alert(1)",
			'&#106;avascript:alert(1)',
			'jav&#x61;script:alert(1)',
			'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==',
			'vbscript:msgbox(1)',
			'//evil.example',
			'/relative',
			'mailto:a@b.c',
		);

		foreach ( $bad as $href ) {
			$out = Sanitizer::html( '<a href="' . $href . '">Go</a>' );

			$this->assertStringNotContainsString( 'href', $out, $href );
			$this->assertStringContainsString( '>Go</a>', $out, $href );
		}
	}

	public function test_a_50kb_string_survives(): void {
		$big = str_repeat( 'A', 50 * 1024 );

		$this->assertSame( $big, Sanitizer::html( $big ) );
		$this->assertSame( $big, Sanitizer::text( $big ) );
	}

	public function test_html_allow_list_is_filterable(): void {
		Filters\expectApplied( 'odavent_events_allowed_html' )->once();

		$this->assertSame( '<p>x</p>', Sanitizer::html( '<p>x</p>' ) );
	}

	public function test_a_filter_returning_junk_falls_back_to_the_default_list(): void {
		Filters\expectApplied( 'odavent_events_allowed_html' )->andReturn( 'nope' );

		$out = Sanitizer::html( '<p>x</p><script>y</script>' );

		$this->assertStringContainsString( '<p>x</p>', $out );
		$this->assertStringNotContainsString( '<script', $out );
	}

	public function test_a_filter_cannot_bring_target_back(): void {
		Filters\expectApplied( 'odavent_events_allowed_html' )->andReturn(
			array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ) )
		);

		$out = Sanitizer::html( '<a href="https://example.com" target="_blank">x</a>' );

		$this->assertStringNotContainsString( 'target', $out );
		$this->assertStringContainsString( 'rel="nofollow noopener"', $out );
	}

	public function test_html_of_null_or_empty_is_empty(): void {
		$this->assertSame( '', Sanitizer::html( null ) );
		$this->assertSame( '', Sanitizer::html( '' ) );
	}

	public function test_url_accepts_http_and_https(): void {
		$this->assertSame( 'https://example.com/a/b?x=1&y=2#top', Sanitizer::url( 'https://example.com/a/b?x=1&y=2#top' ) );
		$this->assertSame( 'http://example.com/', Sanitizer::url( 'http://example.com/' ) );
		$this->assertSame( 'HTTPS://EXAMPLE.COM/', Sanitizer::url( 'HTTPS://EXAMPLE.COM/' ) );
		$this->assertSame( 'https://example.com/x', Sanitizer::url( "  https://example.com/x\n" ) );
		// Like a browser: tabs and newlines inside are ignored, leading and trailing controls and spaces trimmed.
		$this->assertSame( 'https://example.com/ab', Sanitizer::url( "\x01https://example.com/a\tb\x00" ) );
	}

	public function hostile_urls(): array {
		return array(
			'javascript'               => array( 'javascript:alert(1)' ),
			'mixed case javascript'    => array( 'JaVaScRiPt:alert(1)' ),
			'leading space'            => array( ' javascript:alert(1)' ),
			'tab in scheme'            => array( "java\tscript:alert(1)" ),
			'newline in scheme'        => array( "java\nscript:alert(1)" ),
			'cr in scheme'             => array( "java\rscript:alert(1)" ),
			'nul in scheme'            => array( "java\0script:alert(1)" ),
			'javascript with slashes'  => array( "javascript://example.com/%0aalert(1)" ),
			'data html'                => array( 'data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==' ),
			'data html plain'          => array( 'data:text/html,<script>alert(1)</script>' ),
			'vbscript'                 => array( 'vbscript:msgbox(1)' ),
			'protocol relative'        => array( '//evil.example/x' ),
			'backslash protocol rel.'  => array( '\\\\evil.example\\x' ),
			'no scheme'                => array( 'evil.example/x' ),
			'relative'                 => array( '/wp-admin/' ),
			'ftp'                      => array( 'ftp://example.com/x' ),
			'file'                     => array( 'file:///etc/passwd' ),
			'mailto'                   => array( 'mailto:a@b.c' ),
			'http without host'        => array( 'http://' ),
			'http with only a path'    => array( 'https:///path' ),
			'space inside'             => array( 'https://example.com/a b' ),
			'tag inside'               => array( 'https://example.com/<script>' ),
			'quote inside'             => array( 'https://example.com/"onmouseover="x' ),
			'control char in host'     => array( "https://exa\x01mple.com/" ),
			'nul in path'              => array( "https://example.com/a\0b" ),
			'del in path'              => array( "https://example.com/a\x7fb" ),
			'empty'                    => array( '' ),
			'blank'                    => array( '   ' ),
		);
	}

	/**
	 * @dataProvider hostile_urls
	 */
	public function test_url_rejects( string $input ): void {
		$this->assertSame( '', Sanitizer::url( $input ) );
	}

	public function test_values_that_are_not_text_give_an_empty_string(): void {
		foreach ( array( null, array( 'a' ), true, false, new \stdClass() ) as $value ) {
			$this->assertSame( '', Sanitizer::text( $value ) );
			$this->assertSame( '', Sanitizer::html( $value ) );
			$this->assertSame( '', Sanitizer::url( $value ) );
		}

		$this->assertSame( '42', Sanitizer::text( 42 ) );
		$this->assertSame( '<p>1.5</p>', Sanitizer::html( '<p>' . 1.5 . '</p>' ) );
	}

	public function test_url_of_null_is_empty(): void {
		$this->assertSame( '', Sanitizer::url( null ) );
	}

	public function test_a_very_long_url_is_rejected(): void {
		$this->assertSame( '', Sanitizer::url( 'https://example.com/' . str_repeat( 'a', 5000 ) ) );
	}
}

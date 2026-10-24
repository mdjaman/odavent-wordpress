<?php
namespace Odavent\Events\Tests\Unit\Blocks;

use PHPUnit\Framework\TestCase;

/**
 * What PHPUnit can check of assets/js/editor.js (syntax is checked by bin/lint-js with node --check).
 */
class EditorScriptTest extends TestCase {

	/** @var string */
	private $js;

	protected function setUp(): void {
		parent::setUp();
		$file = dirname( __DIR__, 3 ) . '/assets/js/editor.js';
		$this->assertFileExists( $file );
		$this->js = (string) file_get_contents( $file );
	}

	public function test_it_is_plain_es5_without_a_build_step(): void {
		$code = (string) preg_replace( array( '#/\*.*?\*/#s', '#^\s*//.*$#m' ), '', $this->js );

		$this->assertStringNotContainsString( '=>', $code );
		$this->assertDoesNotMatchRegularExpression( '/\b(const|let|class|import|export|async|await)\s/', $code );
		$this->assertStringNotContainsString( '`', $code );
		$this->assertDoesNotMatchRegularExpression( '/<[A-Z][A-Za-z]*[\s>\/]/', $code, 'No JSX.' );
	}

	public function test_it_registers_the_blocks_it_is_given(): void {
		$this->assertStringContainsString( 'window.odaventEventsBlocks', $this->js );
		$this->assertStringContainsString( 'registerBlockType', $this->js );
		$this->assertStringContainsString( "'/odavent/v1/events'", $this->js );
		$this->assertStringContainsString( 'serverSideRender', $this->js );
		$this->assertMatchesRegularExpression( '/save:\s*function\s*\(\)\s*\{\s*return null;\s*\}/', $this->js );
		foreach ( array( 'ToggleControl', 'TextControl', 'SelectControl', 'RangeControl', 'InspectorControls' ) as $control ) {
			$this->assertStringContainsString( $control, $this->js );
		}
	}

	public function test_known_statuses_are_translated(): void {
		foreach ( array( 'draft' => 'Draft', 'published' => 'Published', 'paused' => 'Paused', 'cancelled' => 'Cancelled', 'archived' => 'Archived' ) as $status => $label ) {
			$this->assertMatchesRegularExpression( '/' . $status . ":\\s*__\\( '" . $label . "', 'odavent-events' \\)/", $this->js, $status );
		}
	}

	public function test_every_translation_call_names_the_text_domain_as_a_literal(): void {
		// `wp i18n make-pot` only extracts strings whose domain is a string literal: a DOMAIN variable
		// silently drops every editor string from the .pot (and so from the .json for the browser).
		$calls   = preg_match_all( '/\b__\(/', $this->js );
		$literal = preg_match_all( "/', 'odavent-events' \\)/", $this->js );
		$this->assertGreaterThan( 10, $calls );
		$this->assertSame( $calls, $literal );
		$this->assertDoesNotMatchRegularExpression( '/\bDOMAIN\b/', $this->js );
	}

	public function test_a_slug_only_block_is_shown_by_its_slug(): void {
		$this->assertStringContainsString( "__( 'Event: %s', 'odavent-events' )", $this->js );
		$this->assertStringContainsString( "'slug:'", $this->js );
	}

	public function test_lint_js_runs_in_ci(): void {
		$root = dirname( __DIR__, 3 );
		$this->assertFileExists( $root . '/bin/lint-js' );
		$this->assertTrue( is_executable( $root . '/bin/lint-js' ) );
		$this->assertStringContainsString( 'node --check', (string) file_get_contents( $root . '/bin/lint-js' ) );
		$this->assertStringContainsString( 'bin/lint-js', (string) file_get_contents( $root . '/.github/workflows/ci.yml' ) );
	}
}

<?php
namespace Odavent\Events\Tests\Unit\Packaging;

use Odavent\Events\Tests\TestCase;

/**
 * The developer docs list what the code actually has: a hook, template or CSS variable added later
 * without documenting it fails here.
 */
class DocsTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 3 );
	}

	private function doc( string $file ): string {
		$this->assertFileExists( $this->root . '/' . $file );

		return (string) file_get_contents( $this->root . '/' . $file );
	}

	/** @return string[] */
	private function php_sources(): array {
		$files    = array( $this->root . '/odavent-events.php', $this->root . '/uninstall.php' );
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( preg_match( '#^' . preg_quote( $this->root, '#' ) . '/(src|templates|blocks)/.*\.php$#', $path ) ) {
				$files[] = $path;
			}
		}

		return $files;
	}

	public function test_every_hook_in_the_code_is_documented_and_every_documented_hook_exists(): void {
		$in_code = array();
		foreach ( $this->php_sources() as $file ) {
			preg_match_all( "/\b(?:apply_filters|do_action)\(\s*'(odavent_events_[a-z_]+)'/", (string) file_get_contents( $file ), $m );
			$in_code = array_merge( $in_code, $m[1] );
		}
		$in_code = array_values( array_unique( $in_code ) );
		sort( $in_code );
		$this->assertGreaterThanOrEqual( 11, count( $in_code ) );

		$docs = $this->doc( 'docs/hooks.md' );
		foreach ( $in_code as $hook ) {
			$this->assertMatchesRegularExpression( '/^### `' . $hook . '`/m', $docs, "docs/hooks.md lacks {$hook}" );
		}

		preg_match_all( '/^### `(odavent_events_[a-z_]+)`/m', $docs, $documented );
		$listed = array_values( array_unique( $documented[1] ) );
		sort( $listed );
		$this->assertSame( $in_code, $listed, 'docs/hooks.md documents a hook that does not exist' );
	}

	public function test_every_css_variable_and_template_is_in_the_theming_guide(): void {
		$css  = (string) file_get_contents( $this->root . '/assets/css/odavent.css' );
		$docs = $this->doc( 'docs/theming.md' );

		preg_match_all( '/var\((--odv-[a-z0-9-]+)/', $css, $m );
		$variables = array_unique( $m[1] );
		$this->assertNotEmpty( $variables );
		foreach ( $variables as $variable ) {
			$this->assertStringContainsString( '`' . $variable . '`', $docs, "docs/theming.md lacks {$variable}" );
		}

		foreach ( glob( $this->root . '/templates/*.php' ) ?: array() as $template ) {
			$this->assertStringContainsString( '`' . basename( $template, '.php' ) . '`', $docs, 'docs/theming.md lacks template ' . basename( $template ) );
		}

		// Every BEM class used by the stylesheet's selectors.
		preg_match_all( '/\.(odv-[a-z0-9_-]+)/', $css, $classes );
		foreach ( array_unique( $classes[1] ) as $class ) {
			$this->assertStringContainsString( $class, $docs, "docs/theming.md lacks .{$class}" );
		}
	}

	public function test_the_update_package_path_rule_in_the_security_doc_matches_the_checker(): void {
		$doc     = $this->doc( 'docs/security.md' );
		$checker = (string) file_get_contents( $this->root . '/src/Updates/Update_Checker.php' );

		// The code accepts [vV]? before the version; the doc must not claim a lower-case "v" is required.
		$this->assertStringContainsString( "'#^[vV]?'", $checker );
		$this->assertMatchesRegularExpression( '/capital `V`, or left out/', $doc );
	}

	public function test_the_clock_filter_doc_matches_the_single_helper_both_call_sites_use(): void {
		$doc = $this->doc( 'docs/hooks.md' );

		$this->assertStringContainsString( 'falls back to `time()`, in the', $doc );
		$this->assertStringContainsString( 'refresh endpoint alike', $doc );
		foreach ( array( 'src/Blocks/Renderers/Countdown.php', 'src/Refresh/Refresh_Controller.php' ) as $file ) {
			$code = (string) file_get_contents( $this->root . '/' . $file );
			$this->assertStringContainsString( 'Clock::now()', $code, $file );
			$this->assertStringNotContainsString( "apply_filters( 'odavent_events_now'", $code, $file . ' must go through Clock' );
		}
	}

	public function test_the_other_docs_exist_and_the_readme_links_them(): void {
		$readme = $this->doc( 'README.md' );
		foreach ( array( 'docs/shortcodes.md', 'docs/theming.md', 'docs/hooks.md', 'docs/security.md', 'docs/operations.md' ) as $file ) {
			$this->doc( $file );
			$this->assertStringContainsString( $file, $readme, "README.md does not link {$file}" );
		}
		$this->assertStringContainsString( 'Download ZIP', $readme );
	}
}

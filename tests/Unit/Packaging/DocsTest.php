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
		foreach ( array( 'docs/shortcodes.md', 'docs/widgets.md', 'docs/theming.md', 'docs/hooks.md', 'docs/security.md', 'docs/operations.md' ) as $file ) {
			$this->doc( $file );
			$this->assertStringContainsString( $file, $readme, "README.md does not link {$file}" );
		}
		$this->assertStringContainsString( 'Download ZIP', $readme );
	}

	/**
	 * The release facts a maintainer must know before publishing: what Odavent's update endpoint reads, what it
	 * caches and how to clear it, and that a pre-release reaches no site.
	 */
	public function test_the_release_docs_say_how_odavent_serves_updates(): void {
		$readme = $this->doc( 'README.md' );
		foreach ( array(
			'releases/latest',
			'Pre-releases are invisible',
			'first `.zip` asset',
			'wordpress_plugin_release`',
			'wordpress_plugin_release_failed',
			'wordpress_plugin_release_last_good',
			'php artisan cache:forget',
			'bin/make-pot.sh',
			'https://odavent.com/api/v1/wordpress/plugin-update',
			'0.0.9',
			'2FA',
		) as $fact ) {
			$this->assertStringContainsString( $fact, $readme, "README.md, Releasing: {$fact}" );
		}

		$operations = $this->doc( 'docs/operations.md' );
		foreach ( array( 'pre-releases are never offered', 'wordpress_plugin_release_last_good', 'ships code to every site' ) as $fact ) {
			$this->assertStringContainsString( $fact, $operations, "docs/operations.md: {$fact}" );
		}

		$security = $this->doc( 'docs/security.md' );
		$this->assertStringContainsString( 'Pre-releases reach no site.', $security );
		$this->assertStringContainsString( '2FA', $security );
		$this->assertStringNotContainsString( 'offered only to sites already running a pre-release.', $security, 'the pre-release branch is dead: Odavent never serves one' );
	}

	/** A draft resolves by ID only (resolve_id() skips drafts): the docs must say so where people pick events. */
	public function test_the_docs_say_a_draft_is_previewed_by_id_not_by_slug(): void {
		$this->assertStringContainsString( 'A draft can only be previewed by its ID.', $this->doc( 'docs/shortcodes.md' ) );
		$this->assertStringContainsString( 'To preview a **draft** event, pick it in the list', $this->doc( 'README.md' ) );
	}

	public function test_the_widgets_doc_lists_every_widget_and_the_things_a_webmaster_must_know(): void {
		$docs = $this->doc( 'docs/widgets.md' );

		// One widget per block: odavent_events_ + the block name with dashes turned into underscores (Widgets::id_base()).
		$bases = array();
		foreach ( glob( $this->root . '/blocks/*/block.json' ) ?: array() as $file ) {
			$bases[] = 'odavent_events_' . str_replace( '-', '_', basename( dirname( $file ) ) );
		}
		$this->assertContains( 'odavent_events_event_page', $bases );
		$this->assertContains( 'odavent_events_add_to_calendar', $bases );
		$this->assertGreaterThanOrEqual( 11, count( $bases ) );
		foreach ( $bases as $base ) {
			$this->assertStringContainsString( '`' . $base . '`', $docs, "docs/widgets.md lacks the widget {$base}" );
		}

		// The Event page widget takes the section names as a comma list: every name is spelled out.
		$this->assertSame( 1, preg_match( "/const DEFAULT_SECTIONS = array\\(([^)]*)\\)/", (string) file_get_contents( $this->root . '/src/Blocks/Renderers/Event_Page.php' ), $m ) );
		preg_match_all( "/'([a-z-]+)'/", $m[1], $names );
		$this->assertCount( 10, $names[1] );
		foreach ( $names[1] as $section ) {
			$this->assertStringContainsString( '`' . $section . '`', $docs, "docs/widgets.md lacks the section {$section}" );
		}

		foreach ( array( 'Legacy Widget', 'block theme', 'odavent_events_purge_all_on_widget', 'whole-site' ) as $fact ) {
			$this->assertStringContainsString( $fact, $docs, "docs/widgets.md: {$fact}" );
		}
	}

	public function test_the_theming_guide_covers_the_event_page_the_appearance_screen_and_the_precedence_rules(): void {
		$docs = $this->doc( 'docs/theming.md' );

		foreach ( array(
			'`event-page`',
			'.odv-event-page__heading',
			'Settings > Odavent Events > Appearance',
			'defaults',
			'specificity',
			'odv-speakers-42-2',
			'above `.odv-event-page`',
			'inside it',
			'data-odv-img',
		) as $fact ) {
			$this->assertStringContainsString( $fact, $docs, "docs/theming.md: {$fact}" );
		}
	}

	public function test_the_appearance_filter_doc_describes_what_the_css_really_contains(): void {
		$docs = $this->doc( 'docs/hooks.md' );

		$this->assertStringContainsString( 'fixed `rem` gaps and a `px` radius', $docs );
		$this->assertStringNotContainsString( 'integers with `px` or `rem`', $docs );
	}

	public function test_the_readme_has_an_organizer_quick_start_and_a_webmaster_part(): void {
		$readme = $this->doc( 'README.md' );

		$this->assertMatchesRegularExpression( '/^## For organizers$/m', $readme );
		$this->assertMatchesRegularExpression( '/^## For webmasters$/m', $readme );
		preg_match( '/^## For organizers\n(.*?)(?=^## )/ms', $readme, $m );
		$this->assertStringNotContainsString( '```', $m[1] ?? '', 'the organizer part has no code' );
		foreach ( array( 'Event page', 'Full event page', 'Appearance', 'widget' ) as $fact ) {
			$this->assertStringContainsString( $fact, $m[1] ?? '', "For organizers: {$fact}" );
		}
		$this->assertStringContainsString( '`[odavent_event_page]`', $readme, 'the blocks table lists the event page' );
	}

	public function test_the_changelog_and_the_wordpress_readme_announce_the_unreleased_work(): void {
		$changelog = $this->doc( 'CHANGELOG.md' );
		$this->assertMatchesRegularExpression( '/^## \[Unreleased\]$/m', $changelog );
		$this->assertLessThan( strpos( $changelog, '## [0.1.0]' ), strpos( $changelog, '## [Unreleased]' ), 'Unreleased comes first' );
		preg_match( '/^## \[Unreleased\]\n(.*?)(?=^## \[)/ms', $changelog, $m );
		$this->assertMatchesRegularExpression( '/^### Added$/m', $m[1] ?? '' );
		$this->assertMatchesRegularExpression( '/^### Fixed$/m', $m[1] ?? '' );
		$this->assertStringContainsString( 'Both', $m[1] ?? '' );

		$readme = $this->doc( 'readme.txt' );
		$this->assertStringContainsString( 'Event page', $readme );
		$this->assertStringContainsString( 'Appearance', $readme );
		$this->assertStringContainsString( 'widgets', $readme );
	}
}

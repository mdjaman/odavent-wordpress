<?php
namespace Odavent\Events\Tests\Unit\Packaging;

use Odavent\Events\Tests\TestCase;

/**
 * The version and the requirements are stated in several places (plugin header, readme.txt,
 * CHANGELOG.md, release body). They must agree, because the Odavent server and the update checker
 * both read them from GitHub.
 */
class ReleaseMetadataTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 3 );
	}

	private function read( string $file ): string {
		$this->assertFileExists( $this->root . '/' . $file );

		return (string) file_get_contents( $this->root . '/' . $file );
	}

	private function header( string $label ): string {
		$this->assertSame( 1, preg_match( '/^\s*\*\s*' . preg_quote( $label, '/' ) . ':\s*(\S+)\s*$/m', $this->read( 'odavent-events.php' ), $m ), "Plugin header {$label}" );

		return $m[1];
	}

	private function readme( string $label ): string {
		$this->assertSame( 1, preg_match( '/^' . preg_quote( $label, '/' ) . ':\s*(\S+)\s*$/m', $this->read( 'readme.txt' ), $m ), "readme.txt {$label}" );

		return $m[1];
	}

	public function test_readme_stable_tag_and_requirements_agree_with_the_plugin_header(): void {
		$this->assertSame( $this->header( 'Version' ), $this->readme( 'Stable tag' ) );
		$this->assertSame( $this->header( 'Requires at least' ), $this->readme( 'Requires at least' ) );
		$this->assertSame( $this->header( 'Requires PHP' ), $this->readme( 'Requires PHP' ) );
		$this->assertMatchesRegularExpression( '/^[0-9]+\.[0-9]+(\.[0-9]+)?$/', $this->readme( 'Tested up to' ) );
		$this->assertSame( 'GPLv2 or later', trim( (string) preg_replace( '/^.*?^License:\s*(.+?)\s*$.*/ms', '$1', $this->read( 'readme.txt' ) ) ) );
		$this->assertSame( 'mdjaman', $this->readme( 'Contributors' ) );
	}

	public function test_the_text_domain_domain_path_and_update_uri_headers(): void {
		$this->assertSame( 'odavent-events', $this->header( 'Text Domain' ) );
		$this->assertSame( '/languages', $this->header( 'Domain Path' ) );
		$this->assertDirectoryExists( $this->root . '/languages' );
	}

	public function test_the_changelog_and_the_readme_changelog_have_the_current_version(): void {
		$version = $this->header( 'Version' );

		$this->assertSame( 1, preg_match( '/^## \[' . preg_quote( $version, '/' ) . '\] - \d{4}-\d{2}-\d{2}$/m', $this->read( 'CHANGELOG.md' ) ), 'CHANGELOG.md section' );
		$this->assertStringContainsString( "\n= {$version} =\n", $this->read( 'readme.txt' ) );
	}

	/** @return array{0: int, 1: string} */
	private function notes( string $version, ?string $source = null ): array {
		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $this->root . '/bin/release-notes.php' ) . ' ' . escapeshellarg( $version );
		if ( null !== $source ) {
			$cmd .= ' --source=' . escapeshellarg( $source );
		}
		exec( $cmd . ' 2>&1', $lines, $code );

		return array( $code, implode( "\n", $lines ) );
	}

	public function test_release_notes_are_the_changelog_section_plus_three_plain_requirement_lines(): void {
		$version = $this->header( 'Version' );
		list( $code, $notes ) = $this->notes( $version );

		$this->assertSame( 0, $code, $notes );

		// The section body: between its heading and the next heading, trimmed.
		$this->assertSame( 1, preg_match( '/^## \[' . preg_quote( $version, '/' ) . '\][^\n]*\n(.*?)(?=^## \[|\z)/ms', $this->read( 'CHANGELOG.md' ), $m ) );
		$section = trim( $m[1] );
		$this->assertNotSame( '', $section );
		$this->assertStringStartsWith( $section, $notes );

		$tail = trim( substr( $notes, strlen( $section ) ) );
		$this->assertSame(
			array(
				'Requires at least: ' . $this->readme( 'Requires at least' ),
				'Requires PHP: ' . $this->readme( 'Requires PHP' ),
				'Tested up to: ' . $this->readme( 'Tested up to' ),
			),
			explode( "\n", $tail )
		);

		// The Odavent server reads them with this regex (PluginRelease::bodyLine): plain Key: value lines.
		foreach ( array( 'Requires at least', 'Requires PHP', 'Tested up to' ) as $label ) {
			$this->assertSame( 1, preg_match( '/^\s*' . preg_quote( $label, '/' ) . '\s*:\s*(\S+)\s*$/mi', $notes, $found ), $label );
			$this->assertSame( 1, preg_match( '/^[0-9]+(\.[0-9]+)*$/', $found[1] ), "{$label} value is plain: {$found[1]}" );
		}
		// No Markdown decoration around them.
		$this->assertDoesNotMatchRegularExpression( '/^[ \t*_`>#-]+(Requires|Tested)/m', $notes );
		$this->assertDoesNotMatchRegularExpression( '/(\*\*|__|`)\s*(Requires|Tested)|(Requires at least|Requires PHP|Tested up to)\s*(\*\*|__|`)/', $notes );
	}

	public function test_release_notes_pick_only_the_requested_section(): void {
		$tmp = sys_get_temp_dir() . '/odv-notes-' . bin2hex( random_bytes( 4 ) );
		mkdir( $tmp );
		file_put_contents(
			$tmp . '/CHANGELOG.md',
			"# Changelog\n\n## [Unreleased]\n\n- Not yet.\n\n## [1.2.0] - 2027-01-02\n\n### Added\n\n- New thing.\n\n### Fixed\n\n- Old bug.\n\n## [1.1.0] - 2026-12-01\n\n- Earlier.\n\n[1.2.0]: https://example.com/1.2.0\n[1.1.0]: https://example.com/1.1.0\n"
		);
		file_put_contents( $tmp . '/readme.txt', "=== X ===\nRequires at least: 6.1\nTested up to: 6.8\nRequires PHP: 7.4\nStable tag: 1.2.0\n" );

		list( $code, $notes ) = $this->notes( '1.2.0', $tmp );
		$this->assertSame( 0, $code, $notes );
		$this->assertSame( "### Added\n\n- New thing.\n\n### Fixed\n\n- Old bug.\n\nRequires at least: 6.1\nRequires PHP: 7.4\nTested up to: 6.8", $notes );

		list( $code ) = $this->notes( '3.0.0', $tmp );
		$this->assertNotSame( 0, $code, 'A version with no changelog section must fail the release.' );

		unlink( $tmp . '/CHANGELOG.md' );
		unlink( $tmp . '/readme.txt' );
		rmdir( $tmp );
	}

	public function test_the_release_workflow_is_tag_only_and_publishes_only_there(): void {
		$yml = $this->read( '.github/workflows/release.yml' );

		$this->assertMatchesRegularExpression( "/^on:\s*\n\s+push:\s*\n\s+tags:\s*\n\s+- 'v\*'/m", $yml );
		$this->assertStringNotContainsString( 'pull_request', $yml );
		$this->assertStringNotContainsString( 'branches', $yml );
		$this->assertMatchesRegularExpression( '/permissions:\s*\n\s+contents: write/', $yml );
		$this->assertStringContainsString( 'actions/checkout@v4', $yml );
		$this->assertStringContainsString( 'shivammathur/setup-php@v2', $yml );
		$this->assertStringContainsString( 'bin/release-notes.php', $yml );
		$this->assertStringContainsString( 'bin/build-zip.php', $yml );
		$this->assertStringContainsString( 'bin/check-translations.php', $yml );
		$this->assertMatchesRegularExpression( '/gh release create/', $yml );

		// Nothing else publishes.
		$ci = $this->read( '.github/workflows/ci.yml' );
		$this->assertStringNotContainsString( 'gh release', $ci );
		$this->assertStringNotContainsString( 'contents: write', $ci );
		$this->assertStringContainsString( 'bin/check-translations.php', $ci );
	}

	public function test_the_release_only_runs_for_a_commit_that_is_on_main(): void {
		$yml = $this->read( '.github/workflows/release.yml' );

		// Full history, or origin/main and the ancestry check would not be there on the runner.
		$this->assertMatchesRegularExpression( '/actions\/checkout@v4\s*\n\s+with:\s*\n(?:\s+#[^\n]*\n)*\s+fetch-depth: 0/', $yml );
		$this->assertStringContainsString( 'git merge-base --is-ancestor "$GITHUB_SHA" origin/main', $yml );

		// The SHA reaches the shell as an environment variable, not pasted into the script text.
		$this->assertDoesNotMatchRegularExpression( '/merge-base[^\n]*\$\{\{/', $yml );

		// And the check runs before anything is tested, built or published.
		$check = strpos( $yml, 'git merge-base --is-ancestor' );
		$this->assertNotFalse( $check );
		foreach ( array( 'composer install', 'vendor/bin/phpunit', 'bin/build-zip.php', 'gh release create' ) as $later ) {
			$this->assertLessThan( (int) strpos( $yml, $later ), $check, "The main-branch check must come before: {$later}" );
		}
	}

	public function test_the_ancestry_check_accepts_main_history_and_rejects_a_side_branch(): void {
		$dir = sys_get_temp_dir() . '/odv-git-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir );
		$git = 'git -C ' . escapeshellarg( $dir ) . ' -c user.name=t -c user.email=t@example.com ';
		$run = function ( string $command ) use ( &$lines ): int {
			$lines = array();
			exec( $command . ' 2>&1', $lines, $code );

			return $code;
		};
		if ( 0 !== $run( 'git --version' ) ) {
			$this->markTestSkipped( 'git is not installed.' );
		}

		// "origin/main" is a remote-tracking ref: create it by hand, as a fetch would.
		$run( $git . 'init -q -b main' );
		$run( $git . 'commit -q --allow-empty -m one' );
		$on_main = trim( (string) shell_exec( $git . 'rev-parse HEAD' ) );
		$run( $git . 'update-ref refs/remotes/origin/main HEAD' );
		$run( $git . 'checkout -q -b side' );
		$run( $git . 'commit -q --allow-empty -m side' );
		$on_side = trim( (string) shell_exec( $git . 'rev-parse HEAD' ) );

		$this->assertSame( 0, $run( $git . 'merge-base --is-ancestor ' . escapeshellarg( $on_main ) . ' origin/main' ) );
		$this->assertNotSame( 0, $run( $git . 'merge-base --is-ancestor ' . escapeshellarg( $on_side ) . ' origin/main' ) );

		self::remove_tree( $dir );
	}

	private static function remove_tree( string $dir ): void {
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * A workflow that is not valid YAML never runs. The PHP image has no YAML extension, so this uses whatever
	 * parser the machine has (the yaml extension, Ruby or Python with PyYAML) and is skipped when there is none:
	 * the checks above still pin the structure. Validated for this change with
	 * `docker run --rm -v "$PWD":/w -w /w ruby:3 ruby -ryaml -e 'YAML.load_file(".github/workflows/release.yml")'`.
	 */
	public function test_the_workflows_parse_as_yaml(): void {
		$files = array( '.github/workflows/release.yml', '.github/workflows/ci.yml' );

		if ( function_exists( 'yaml_parse_file' ) ) {
			foreach ( $files as $file ) {
				$this->assertIsArray( yaml_parse_file( $this->root . '/' . $file ), $file );
			}

			return;
		}

		$parsers = array(
			'ruby -ryaml -e ' . escapeshellarg( 'ARGV.each { |f| YAML.load_file(f) }' ),
			'python3 -c ' . escapeshellarg( 'import sys, yaml; [yaml.safe_load(open(f)) for f in sys.argv[1:]]' ),
		);
		foreach ( $parsers as $parser ) {
			$binary = strtok( $parser, ' ' );
			exec( 'command -v ' . escapeshellarg( (string) $binary ) . ' 2>&1', $found, $code );
			if ( 0 !== $code ) {
				continue;
			}
			$arguments = implode( ' ', array_map( function ( $file ) { return escapeshellarg( $this->root . '/' . $file ); }, $files ) );
			exec( $parser . ' ' . $arguments . ' 2>&1', $lines, $code );
			if ( 0 === $code ) {
				$this->assertSame( 0, $code );

				return;
			}
			if ( false !== stripos( implode( "\n", $lines ), 'No module named' ) ) {
				continue; // Python without PyYAML.
			}
			$this->fail( "A workflow is not valid YAML:\n" . implode( "\n", $lines ) );
		}

		$this->markTestSkipped( 'No YAML parser (yaml extension, Ruby, Python with PyYAML) is available.' );
	}

	public function test_the_pot_version_comes_from_the_plugin_header_and_make_pot_reads_it_from_there(): void {
		$pot    = $this->read( 'languages/odavent-events.pot' );
		$script = $this->read( 'bin/make-pot.sh' );

		// A version bump without bin/make-pot.sh leaves a stale header: fail until the .pot is regenerated.
		$this->assertStringContainsString( '"Project-Id-Version: Odavent Events ' . $this->header( 'Version' ) . '\n"', $pot );

		// The script passes the version it reads from odavent-events.php; no number is typed into it.
		$this->assertStringContainsString( 'Version:', $script );
		$this->assertStringContainsString( 'odavent-events.php', $script );
		$this->assertStringContainsString( 'Project-Id-Version', $script );
		$this->assertStringContainsString( '${VERSION}', $script );
		$this->assertDoesNotMatchRegularExpression( '/Project-Id-Version[^\n]*\d+\.\d+\.\d+/', $script );

		// And the release steps tell the maintainer to run it before tagging.
		$this->assertMatchesRegularExpression( '/bin\/make-pot\.sh` before tagging/', $this->read( 'README.md' ) );
	}

	public function test_export_ignore_covers_the_dev_files(): void {
		$attributes = $this->read( '.gitattributes' );

		foreach ( array( '/docker', '/patchwork.json', '/tests', '/bin', '/docs', '/.github', '/composer.json', '/composer.lock', '/phpunit.xml.dist', '/phpcs.xml.dist', '/CHANGELOG.md' ) as $path ) {
			$this->assertMatchesRegularExpression( '#^' . preg_quote( $path, '#' ) . ' export-ignore$#m', $attributes, $path );
		}
	}
}

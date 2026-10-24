<?php
namespace Odavent\Events\Tests\Unit\Packaging;

use Gettext\Loader\MoLoader;
use Gettext\Loader\PoLoader;
use Odavent\Events\Tests\TestCase;

/**
 * Builds the release zip with bin/build-zip.php from a copy of the repository tree (so the dev
 * files that must NOT ship are really there) and inspects the archive.
 */
class PackagingTest extends TestCase {

	/** @var string */
	private static $root;

	/** @var string Copy of the repository tree, shared by the tests that only read the build. */
	private static $copy;

	/** @var string[] Directories to remove at the end. */
	private static $cleanup = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		self::$root = dirname( __DIR__, 3 );
		self::$copy = self::copy_tree();
	}

	public static function tearDownAfterClass(): void {
		foreach ( self::$cleanup as $dir ) {
			self::remove( $dir );
		}
		parent::tearDownAfterClass();
	}

	/** Copy of the repository without vendor/.git/dist (everything else, dev files included). */
	private static function copy_tree(): string {
		$dir = sys_get_temp_dir() . '/odv-pack-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir, 0777, true );
		self::$cleanup[] = $dir;

		$skip     = array( 'vendor', '.git', 'dist', '.superpowers', '.phpunit.result.cache' );
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( self::$root, \FilesystemIterator::SKIP_DOTS ),
				function ( \SplFileInfo $file ) use ( $skip ) {
					return ! in_array( $file->getFilename(), $skip, true );
				}
			),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $iterator as $file ) {
			$target = $dir . '/' . $iterator->getSubPathname();
			if ( $file->isDir() ) {
				mkdir( $target, 0777, true );
			} else {
				copy( $file->getPathname(), $target );
			}
		}

		return $dir;
	}

	private static function remove( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Runs bin/build-zip.php.
	 *
	 * @return array{0: int, 1: string, 2: string} Exit code, output, output directory.
	 */
	private function build( string $version, ?string $source = null, ?string $out = null ): array {
		if ( null === $out ) {
			$out = sys_get_temp_dir() . '/odv-out-' . bin2hex( random_bytes( 4 ) );
			mkdir( $out, 0777, true );
			self::$cleanup[] = $out;
		}

		$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( self::$root . '/bin/build-zip.php' )
			. ' ' . escapeshellarg( $version )
			. ' --source=' . escapeshellarg( $source ?? self::$copy )
			. ' --out=' . escapeshellarg( $out ) . ' 2>&1';
		exec( $cmd, $lines, $code );

		return array( $code, implode( "\n", $lines ), $out );
	}

	/** @return string[] Entry names of the archive. */
	private function entries( string $zip_path ): array {
		$zip = new \ZipArchive();
		$this->assertTrue( true === $zip->open( $zip_path ), 'The archive does not open.' );
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();

		return $names;
	}

	private function built_zip(): string {
		static $path = null;
		if ( null === $path ) {
			list( $code, $output, $out ) = $this->build( '0.1.0' );
			$this->assertSame( 0, $code, $output );
			$path = $out . '/odavent-events-0.1.0.zip';
		}
		$this->assertFileExists( $path );

		return $path;
	}

	public function test_the_dev_files_really_are_in_the_tree_that_is_packaged(): void {
		// Guards the exclusion tests below: without these the "contains no tests/" checks prove nothing.
		foreach ( array( 'tests/Unit/Packaging/PackagingTest.php', 'bin/build-zip.php', '.github/workflows/ci.yml', 'docker/Dockerfile.test', 'docs/hooks.md', 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'patchwork.json', 'phpcs.xml.dist', 'CHANGELOG.md' ) as $file ) {
			$this->assertFileExists( self::$copy . '/' . $file );
		}
	}

	public function test_the_zip_has_a_single_top_level_odavent_events_folder(): void {
		$names = $this->entries( $this->built_zip() );

		$this->assertNotEmpty( $names );
		foreach ( $names as $name ) {
			$this->assertSame( 0, strpos( $name, 'odavent-events/' ), "Entry outside odavent-events/: {$name}" );
		}
	}

	public function test_the_zip_contains_the_runtime_files(): void {
		$names = $this->entries( $this->built_zip() );

		foreach ( array(
			'odavent-events/odavent-events.php',
			'odavent-events/uninstall.php',
			'odavent-events/readme.txt',
			'odavent-events/LICENSE',
			'odavent-events/src/Plugin.php',
			'odavent-events/templates/speakers.php',
			'odavent-events/blocks/speakers/block.json',
			'odavent-events/assets/js/editor.js',
			'odavent-events/assets/css/odavent.css',
			'odavent-events/languages/odavent-events.pot',
			'odavent-events/languages/odavent-events-fr_FR.mo',
		) as $expected ) {
			$this->assertContains( $expected, $names );
		}
	}

	public function test_the_zip_contains_the_javascript_translation_file(): void {
		$names = $this->entries( $this->built_zip() );

		// WordPress looks the file up by md5 of the script path relative to the plugin root.
		$expected = 'odavent-events/languages/odavent-events-fr_FR-' . md5( 'assets/js/editor.js' ) . '.json';
		$this->assertContains( $expected, $names );

		$zip = new \ZipArchive();
		$zip->open( $this->built_zip() );
		$json = json_decode( (string) $zip->getFromName( $expected ), true );
		$zip->close();

		$this->assertSame( 'assets/js/editor.js', $json['source'] );
		$this->assertSame( 'fr_FR', $json['locale_data']['messages']['']['lang'] );
		$this->assertSame( array( 'Brouillon' ), $json['locale_data']['messages']['Draft'] );
		$this->assertSame( array( 'Événement n° %d' ), $json['locale_data']['messages']['Event #%d'] );
		// Only strings the script uses: PHP-only strings are not shipped to the browser.
		$this->assertArrayNotHasKey( 'Sold out', $json['locale_data']['messages'] );
	}

	public function test_the_zip_contains_no_development_files(): void {
		$names = $this->entries( $this->built_zip() );

		$forbidden_prefixes = array( 'tests/', 'bin/', '.github/', 'docker/', 'docs/', 'vendor/', '.superpowers/', 'node_modules/', 'dist/' );
		$forbidden_exact    = array( 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpcs.xml.dist', 'patchwork.json', '.gitattributes', '.gitignore', 'CHANGELOG.md', 'README.md' );

		foreach ( $names as $name ) {
			$relative = substr( $name, strlen( 'odavent-events/' ) );
			foreach ( $forbidden_prefixes as $prefix ) {
				$this->assertFalse( 0 === strpos( $relative, $prefix ), "Development path shipped: {$name}" );
			}
			$this->assertNotContains( $relative, $forbidden_exact, "Development file shipped: {$name}" );
			$this->assertNotSame( '.po', substr( $relative, -3 ), "Translation source shipped: {$name}" );
			$this->assertSame( 0, preg_match( '#(^|/)\.[^/]#', $relative ), "Dotfile shipped: {$name}" );
		}
	}

	public function test_only_allow_listed_file_types_are_shipped_and_symlinks_and_hidden_paths_are_not(): void {
		$copy = self::copy_tree();
		mkdir( $copy . '/src/.hidden', 0777, true );
		mkdir( $copy . '/assets/images', 0777, true );
		file_put_contents( $copy . '/src/Plugin.php.orig', '<?php // backup' );
		file_put_contents( $copy . '/src/Plugin.php~', '<?php // editor backup' );
		file_put_contents( $copy . '/src/.hidden/x.php', '<?php // hidden folder' );
		file_put_contents( $copy . '/src/notes.txt', 'notes' );
		file_put_contents( $copy . '/assets/images/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>' );
		file_put_contents( $copy . '/assets/images/design.psd', 'psd' );
		file_put_contents( $copy . '/blocks/speakers/README.md', 'x' );
		file_put_contents( $copy . '/templates/venue.php.bak', 'x' );
		file_put_contents( $copy . '/secret.php', '<?php // outside the allow-list' );
		$this->assertTrue( symlink( $copy . '/src/Plugin.php', $copy . '/src/Linked.php' ) );
		$this->assertTrue( symlink( $copy . '/secret.php', $copy . '/assets/js/linked.js' ) );

		list( $code, $output, $out ) = $this->build( '0.1.0', $copy );
		$this->assertSame( 0, $code, $output );
		$names = $this->entries( $out . '/odavent-events-0.1.0.zip' );

		$this->assertContains( 'odavent-events/src/Plugin.php', $names );
		$this->assertContains( 'odavent-events/assets/images/logo.svg', $names, 'An allow-listed type in a new folder ships.' );
		foreach ( array( 'src/Plugin.php.orig', 'src/Plugin.php~', 'src/.hidden/x.php', 'src/notes.txt', 'src/Linked.php', 'assets/js/linked.js', 'assets/images/design.psd', 'blocks/speakers/README.md', 'templates/venue.php.bak', 'secret.php' ) as $leak ) {
			$this->assertNotContains( 'odavent-events/' . $leak, $names, "{$leak} must not ship." );
		}
		$this->assertSame( array(), array_filter( $names, function ( $name ) { return false !== strpos( $name, '.hidden' ); } ) );
	}

	public function test_entries_carry_unix_modes_0644_for_files_and_0755_for_folders(): void {
		$zip = new \ZipArchive();
		$zip->open( $this->built_zip() );

		$files = 0;
		$dirs  = 0;
		$seen  = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = (string) $zip->getNameIndex( $i );
			$this->assertTrue( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ), $name );
			$this->assertSame( \ZipArchive::OPSYS_UNIX, $opsys, $name );
			$mode = ( $attributes >> 16 ) & 0777;
			if ( '/' === substr( $name, -1 ) ) {
				$this->assertSame( 0755, $mode, "Folder mode of {$name}" );
				++$dirs;
			} else {
				$this->assertSame( 0644, $mode, "File mode of {$name}" );
				++$files;
			}
			$seen[] = $name;
		}
		$zip->close();

		$this->assertGreaterThan( 20, $files );
		$this->assertGreaterThan( 3, $dirs );
		// Generated files get the same modes as copied ones.
		$this->assertContains( 'odavent-events/languages/odavent-events-fr_FR.mo', $seen );
		$this->assertContains( 'odavent-events/languages/odavent-events-fr_FR-' . md5( 'assets/js/editor.js' ) . '.json', $seen );
		$this->assertContains( 'odavent-events/src/', $seen );
	}

	public function test_a_failure_after_the_archive_was_opened_leaves_no_partial_file(): void {
		$out = sys_get_temp_dir() . '/odv-out-' . bin2hex( random_bytes( 4 ) );
		mkdir( $out . '/odavent-events-0.1.0.zip', 0777, true ); // The final name is taken by a folder: the last step fails.
		file_put_contents( $out . '/odavent-events-0.1.0.zip/keep', 'x' );
		self::$cleanup[] = $out;

		list( $code, $output ) = $this->build( '0.1.0', null, $out );

		$this->assertNotSame( 0, $code, $output );
		$this->assertStringContainsString( 'Cannot move the archive', $output );
		$this->assertFileDoesNotExist( $out . '/odavent-events-0.1.0.zip.partial' );
		$this->assertSame( array(), glob( $out . '/*.partial' ) ?: array() );
	}

	public function test_the_plugin_header_inside_the_zip_has_the_requested_version(): void {
		$zip = new \ZipArchive();
		$zip->open( $this->built_zip() );
		$main = (string) $zip->getFromName( 'odavent-events/odavent-events.php' );
		$zip->close();

		$this->assertSame( 1, preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $main, $m ) );
		$this->assertSame( '0.1.0', $m[1] );
	}

	public function test_the_compiled_mo_round_trips_the_french_translation(): void {
		$zip = new \ZipArchive();
		$zip->open( $this->built_zip() );
		$mo_bytes = (string) $zip->getFromName( 'odavent-events/languages/odavent-events-fr_FR.mo' );
		$zip->close();

		// Magic number: little or big endian .mo.
		$this->assertContains( bin2hex( substr( $mo_bytes, 0, 4 ) ), array( '950412de', 'de120495' ) );

		$mo = ( new MoLoader() )->loadString( $mo_bytes );
		$this->assertSame( 'fr_FR', $mo->getLanguage() );
		$this->assertSame( 'nplurals=2; plural=(n > 1);', $mo->getHeaders()->get( 'Plural-Forms' ) );

		$this->assertSame( 'S’inscrire', $mo->find( null, 'Register' )->getTranslation() );
		$this->assertSame( 'Complet', $mo->find( null, 'Sold out' )->getTranslation() );
		$this->assertSame( 'Intervenants', $mo->find( 'block title', 'Speakers' )->getTranslation() );

		$plural = $mo->find( null, '%s spot left' );
		$this->assertSame( array( '%s place restante', '%s places restantes' ), array_merge( array( $plural->getTranslation() ), $plural->getPluralTranslations() ) );

		// Every translated entry of the .po is in the .mo (nothing dropped by the compiler).
		$po = ( new PoLoader() )->loadFile( self::$root . '/languages/odavent-events-fr_FR.po' );
		foreach ( $po as $entry ) {
			$compiled = $mo->find( $entry->getContext(), $entry->getOriginal() );
			$this->assertNotNull( $compiled, 'Missing in .mo: ' . $entry->getOriginal() );
			$this->assertSame( $entry->getTranslation(), $compiled->getTranslation(), $entry->getOriginal() );
		}
	}

	public function test_it_refuses_a_version_that_differs_from_the_plugin_header(): void {
		list( $code, $output, $out ) = $this->build( '9.9.9' );

		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( '0.1.0', $output );
		$this->assertStringContainsString( '9.9.9', $output );
		$this->assertFileDoesNotExist( $out . '/odavent-events-9.9.9.zip' );
	}

	public function test_it_refuses_a_malformed_version(): void {
		foreach ( array( '', 'v0.1.0', '0.1', '0.1.0/../x', '0.1.0 ' ) as $bad ) {
			list( $code, , $out ) = $this->build( $bad );
			$this->assertNotSame( 0, $code, "Accepted version '{$bad}'" );
			$this->assertSame( array(), glob( $out . '/*' ) ?: array() );
		}
	}

	public function test_it_refuses_when_the_version_constant_differs_from_the_header(): void {
		$copy = self::copy_tree();
		$file = $copy . '/odavent-events.php';
		file_put_contents( $file, str_replace( "define( 'ODAVENT_EVENTS_VERSION', '0.1.0' )", "define( 'ODAVENT_EVENTS_VERSION', '0.0.9' )", (string) file_get_contents( $file ) ) );

		list( $code, $output ) = $this->build( '0.1.0', $copy );

		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'ODAVENT_EVENTS_VERSION', $output );
	}

	public function test_it_refuses_when_a_translation_is_incomplete(): void {
		$copy = self::copy_tree();
		$file = $copy . '/languages/odavent-events-fr_FR.po';
		file_put_contents( $file, preg_replace( '/msgstr "S’inscrire"/u', 'msgstr ""', (string) file_get_contents( $file ), 1 ) );

		list( $code, $output ) = $this->build( '0.1.0', $copy );

		$this->assertNotSame( 0, $code );
		$this->assertStringContainsString( 'Register', $output );
	}

	public function test_the_wrapper_and_the_workflow_use_the_php_script(): void {
		$this->assertFileExists( self::$root . '/bin/build-zip' );
		$this->assertTrue( is_executable( self::$root . '/bin/build-zip' ) );
		$this->assertStringContainsString( 'bin/build-zip.php', (string) file_get_contents( self::$root . '/bin/build-zip' ) );
		$this->assertStringContainsString( 'bin/build-zip.php', (string) file_get_contents( self::$root . '/.github/workflows/release.yml' ) );
	}
}

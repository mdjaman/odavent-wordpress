<?php
/**
 * Builds dist/odavent-events-X.Y.Z.zip: the installable plugin, with the single top-level folder
 * odavent-events/ and only the files that run on a site (an allow-list, so a new dev file can never
 * leak into a release by accident).
 *
 * Usage: php bin/build-zip.php X.Y.Z [--source=DIR] [--out=DIR]
 *   --source  plugin tree to package (default: this repository)
 *   --out     directory for the zip (default: <source>/dist)
 *
 * Refuses when X.Y.Z differs from the plugin header "Version:" (or from ODAVENT_EVENTS_VERSION), or
 * when the translations are incomplete. Compiles each languages/*.po to .mo and writes the JSON
 * files for the browser scripts, in PHP (gettext/gettext), so no msgfmt or zip binary is needed.
 * Needs the Zip extension and `composer install` (dev dependencies).
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/translations.php';

list( $positional, $options ) = odv_parse_args( $argv );
$version                      = isset( $positional[0] ) ? $positional[0] : '';
$source                       = isset( $options['source'] ) ? rtrim( $options['source'], '/' ) : dirname( __DIR__ );
$out_dir                      = isset( $options['out'] ) ? rtrim( $options['out'], '/' ) : $source . '/dist';

if ( 1 !== preg_match( ODV_VERSION_PATTERN, $version ) ) {
	odv_fail( "Usage: php bin/build-zip.php X.Y.Z [--source=DIR] [--out=DIR] (got '{$version}')." );
}
if ( ! class_exists( 'ZipArchive' ) ) {
	odv_fail( 'The PHP Zip extension is required.' );
}

$header = odv_plugin_header( $source, 'Version' );
if ( $header !== $version ) {
	odv_fail( "Version mismatch: asked for {$version} but the plugin header says '" . (string) $header . "'. Bump the header (and readme.txt, CHANGELOG.md) first." );
}
$constant = odv_version_constant( $source );
if ( $constant !== $version ) {
	odv_fail( "ODAVENT_EVENTS_VERSION is '" . (string) $constant . "' but the plugin header says {$version}." );
}
$stable = odv_readme_header( $source, 'Stable tag' );
if ( $stable !== $version ) {
	odv_fail( "readme.txt Stable tag is '" . (string) $stable . "', expected {$version}." );
}

$problems = odv_check_translations( $source );
if ( array() !== $problems ) {
	odv_fail( "Translations are not release-ready:\n" . implode( "\n", $problems ) );
}

// What ships. Files at the top level, then whole directories, but only the file types that run on a
// site (an allow-list of extensions per directory, so a stray src/Foo.php.orig, an editor backup or a
// screenshot never leaks into a release). Symlinks and any path component starting with a dot are
// skipped. languages/ is handled separately below; its .mo and .json files are generated.
$top_files = array( 'odavent-events.php', 'uninstall.php', 'readme.txt', 'LICENSE' );
$top_dirs  = array(
	'src'       => array( 'php' ),
	'templates' => array( 'php' ),
	'blocks'    => array( 'json' ),
	'assets'    => array( 'css', 'js', 'svg', 'png', 'jpg', 'gif', 'webp' ),
);

$entries = array(); // Zip path => absolute file.
foreach ( $top_files as $file ) {
	if ( ! is_file( "{$source}/{$file}" ) || is_link( "{$source}/{$file}" ) ) {
		odv_fail( "Missing runtime file {$file} (a symlink does not count)." );
	}
	$entries[ $file ] = "{$source}/{$file}";
}
foreach ( $top_dirs as $dir => $extensions ) {
	if ( ! is_dir( "{$source}/{$dir}" ) ) {
		odv_fail( "Missing runtime directory {$dir}/." );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "{$source}/{$dir}", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		/** @var SplFileInfo $file */
		if ( ! $file->isFile() || $file->isLink() ) {
			continue;
		}
		$sub = str_replace( '\\', '/', $iterator->getSubPathname() );
		if ( 1 === preg_match( '#(^|/)\.#', $sub ) || ! in_array( strtolower( $file->getExtension() ), $extensions, true ) ) {
			continue;
		}
		$entries[ $dir . '/' . $sub ] = $file->getPathname();
	}
}
// languages/: the template; the .mo and .json files are generated below, the .po sources stay out.
$entries['languages/' . ODV_SLUG . '.pot'] = "{$source}/languages/" . ODV_SLUG . '.pot';
ksort( $entries );

$generated = array(); // Zip path => bytes.
foreach ( odv_load_po_files( $source ) as $locale => $po ) {
	$generated[ 'languages/' . ODV_SLUG . "-{$locale}.mo" ] = odv_compile_mo( $po );
	foreach ( odv_js_translation_files( $locale, $po ) as $name => $json ) {
		$generated[ "languages/{$name}" ] = $json;
	}
}
ksort( $generated );

if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0777, true ) ) {
	odv_fail( "Cannot create {$out_dir}." );
}
$zip_path = "{$out_dir}/" . ODV_SLUG . "-{$version}.zip";
$tmp_path = $zip_path . '.partial';
if ( is_file( $tmp_path ) ) {
	unlink( $tmp_path );
}

$zip = new ZipArchive();

/**
 * Fails the build and leaves no half-written archive behind.
 */
$abort = static function ( string $message ) use ( $zip, $tmp_path ): void {
	@$zip->unchangeAll(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	@$zip->close(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( is_file( $tmp_path ) ) {
		unlink( $tmp_path );
	}
	odv_fail( $message );
};

if ( true !== $zip->open( $tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	odv_fail( "Cannot write {$tmp_path}." );
}

// Entries carry Unix modes whoever builds the zip: files 0644, folders 0755 (the extracting host's umask
// would otherwise decide, and a build on a restrictive umask or on Windows ships unreadable files).
$file_mode = ( 0100000 | 0644 ) << 16;
$dir_mode  = ( 040000 | 0755 ) << 16;

$names = array( ODV_SLUG . '/' => true );
foreach ( array_merge( array_keys( $entries ), array_keys( $generated ) ) as $path ) {
	for ( $dir = dirname( $path ); '.' !== $dir && '/' !== $dir; $dir = dirname( $dir ) ) {
		$names[ ODV_SLUG . '/' . $dir . '/' ] = true;
	}
}
ksort( $names );
foreach ( array_keys( $names ) as $name ) {
	if ( ! $zip->addEmptyDir( rtrim( $name, '/' ) ) || ! $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $dir_mode ) ) {
		$abort( "Cannot add the folder {$name}." );
	}
}
foreach ( $entries as $path => $absolute ) {
	$name = ODV_SLUG . '/' . $path;
	if ( ! $zip->addFile( $absolute, $name ) || ! $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $file_mode ) ) {
		$abort( "Cannot add {$path}." );
	}
}
foreach ( $generated as $path => $bytes ) {
	$name = ODV_SLUG . '/' . $path;
	if ( ! $zip->addFromString( $name, $bytes ) || ! $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $file_mode ) ) {
		$abort( "Cannot add {$path}." );
	}
}
$count = $zip->numFiles;
if ( ! $zip->close() ) {
	$abort( "Cannot finish {$tmp_path}." );
}
if ( ! rename( $tmp_path, $zip_path ) ) {
	$abort( "Cannot move the archive to {$zip_path}." );
}

echo "Built {$zip_path} ({$count} files).\n";

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

// What ships. Files at the top level, then whole directories (dot files are always skipped).
$top_files = array( 'odavent-events.php', 'uninstall.php', 'readme.txt', 'LICENSE' );
$top_dirs  = array( 'src', 'templates', 'blocks', 'assets' );

$entries = array(); // Zip path => absolute file.
foreach ( $top_files as $file ) {
	if ( ! is_file( "{$source}/{$file}" ) ) {
		odv_fail( "Missing runtime file {$file}." );
	}
	$entries[ $file ] = "{$source}/{$file}";
}
foreach ( $top_dirs as $dir ) {
	if ( ! is_dir( "{$source}/{$dir}" ) ) {
		odv_fail( "Missing runtime directory {$dir}/." );
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "{$source}/{$dir}", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		/** @var SplFileInfo $file */
		if ( ! $file->isFile() || 0 === strpos( $file->getFilename(), '.' ) ) {
			continue;
		}
		$entries[ $dir . '/' . str_replace( '\\', '/', $iterator->getSubPathname() ) ] = $file->getPathname();
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
if ( true !== $zip->open( $tmp_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	odv_fail( "Cannot write {$tmp_path}." );
}
foreach ( $entries as $path => $absolute ) {
	if ( ! $zip->addFile( $absolute, ODV_SLUG . '/' . $path ) ) {
		odv_fail( "Cannot add {$path}." );
	}
}
foreach ( $generated as $path => $bytes ) {
	if ( ! $zip->addFromString( ODV_SLUG . '/' . $path, $bytes ) ) {
		odv_fail( "Cannot add {$path}." );
	}
}
$count = $zip->numFiles;
if ( ! $zip->close() ) {
	odv_fail( "Cannot finish {$tmp_path}." );
}
if ( ! rename( $tmp_path, $zip_path ) ) {
	odv_fail( "Cannot move the archive to {$zip_path}." );
}

echo "Built {$zip_path} ({$count} files).\n";

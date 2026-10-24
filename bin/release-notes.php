<?php
/**
 * Prints the body of the GitHub release for a version: the CHANGELOG.md section of that version
 * followed by the three plain lines the Odavent server reads from the release body
 * (PluginRelease::bodyLine): "Requires at least: ..", "Requires PHP: ..", "Tested up to: ..",
 * taken from readme.txt. No Markdown decoration may touch those lines.
 *
 * Usage: php bin/release-notes.php X.Y.Z [--source=DIR]
 * Fails (exit 1) when the version has no changelog section or readme.txt lacks a value.
 */

require_once __DIR__ . '/lib/release.php';

list( $positional, $options ) = odv_parse_args( $argv );
$version                      = isset( $positional[0] ) ? $positional[0] : '';
$source                       = isset( $options['source'] ) ? rtrim( $options['source'], '/' ) : dirname( __DIR__ );

if ( 1 !== preg_match( ODV_VERSION_PATTERN, $version ) ) {
	odv_fail( 'Usage: php bin/release-notes.php X.Y.Z [--source=DIR]' );
}

$section = odv_changelog_section( $source, $version );
if ( null === $section || '' === $section ) {
	odv_fail( "CHANGELOG.md has no '## [{$version}]' section (or it is empty)." );
}

$lines = array();
foreach ( array( 'Requires at least', 'Requires PHP', 'Tested up to' ) as $label ) {
	$value = odv_readme_header( $source, $label );
	if ( null === $value || 1 !== preg_match( '/^[0-9]+(\.[0-9]+)*$/', $value ) ) {
		odv_fail( "readme.txt has no usable '{$label}:' value." );
	}
	$lines[] = "{$label}: {$value}";
}

echo $section . "\n\n" . implode( "\n", $lines ) . "\n";

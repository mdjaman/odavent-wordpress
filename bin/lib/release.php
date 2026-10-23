<?php
/**
 * Helpers shared by the release scripts (build-zip.php, check-translations.php, release-notes.php).
 *
 * Development tooling only: nothing in bin/ ships in the plugin zip. Runs on PHP 7.4+.
 */

const ODV_SLUG = 'odavent-events';

/** Same pattern the update checker applies to a release version. */
const ODV_VERSION_PATTERN = '/^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$/D';

/**
 * Splits argv into positional arguments and --name=value options.
 *
 * @param array $argv Raw $argv.
 * @return array{0: string[], 1: array<string, string>}
 */
function odv_parse_args( array $argv ): array {
	$positional = array();
	$options    = array();
	foreach ( array_slice( $argv, 1 ) as $arg ) {
		if ( 1 === preg_match( '/^--([a-z-]+)=(.*)$/s', $arg, $m ) ) {
			$options[ $m[1] ] = $m[2];
		} else {
			$positional[] = $arg;
		}
	}

	return array( $positional, $options );
}

/** Prints an error and exits non-zero. */
function odv_fail( string $message ): void {
	fwrite( STDERR, "ERROR: {$message}\n" );
	exit( 1 );
}

/** Reads a file or fails with a clear message. */
function odv_read( string $path ): string {
	$content = is_file( $path ) ? file_get_contents( $path ) : false;
	if ( false === $content ) {
		odv_fail( "Cannot read {$path}" );
	}

	return $content;
}

/** The value of a "Label: value" line of the plugin header comment, or null. */
function odv_plugin_header( string $source, string $label ): ?string {
	$main = odv_read( $source . '/odavent-events.php' );
	$head = substr( $main, 0, 8192 );
	if ( 1 === preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':[ \t]*(\S+)[ \t]*$/mi', $head, $m ) ) {
		return $m[1];
	}

	return null;
}

/** The value of a "Label: value" line of the readme.txt header block, or null. */
function odv_readme_header( string $source, string $label ): ?string {
	$readme = odv_read( $source . '/readme.txt' );
	if ( 1 === preg_match( '/^' . preg_quote( $label, '/' ) . ':[ \t]*(\S+)[ \t]*$/m', $readme, $m ) ) {
		return $m[1];
	}

	return null;
}

/** The value ODAVENT_EVENTS_VERSION is defined as in the main plugin file, or null. */
function odv_version_constant( string $source ): ?string {
	if ( 1 === preg_match( "/define\\(\\s*'ODAVENT_EVENTS_VERSION'\\s*,\\s*'([^']*)'\\s*\\)/", odv_read( $source . '/odavent-events.php' ), $m ) ) {
		return $m[1];
	}

	return null;
}

/**
 * Printf-style placeholders of a string, sorted: the set must survive translation, the order may not.
 *
 * @return string[]
 */
function odv_placeholders( string $text ): array {
	preg_match_all( '/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[sdfFeEgGuxXobcl]/', str_replace( '%%', '', $text ), $m );
	sort( $m[0] );

	return $m[0];
}

/** The changelog section of a version (without its heading), or null when there is none. */
function odv_changelog_section( string $source, string $version ): ?string {
	$changelog = odv_read( $source . '/CHANGELOG.md' );
	$pattern   = '/^## \[' . preg_quote( $version, '/' ) . '\][^\n]*\n(.*?)(?=^## |\z)/ms';
	if ( 1 !== preg_match( $pattern, $changelog, $m ) ) {
		return null;
	}

	// Reference-style link definitions at the end of the file belong to the whole document.
	$body = preg_replace( '/^\[[^\]]+\]:\s+\S+\s*$/m', '', $m[1] );

	return trim( (string) $body );
}

<?php
/**
 * Checks that every languages/*.po is complete and consistent with languages/odavent-events.pot:
 * no empty msgstr, no msgid missing from the .pot (and none of the .pot missing from the .po),
 * the Plural-Forms header, the right number of plural forms and identical placeholders (%s, %d, %1$s).
 *
 * Usage: php bin/check-translations.php [--source=DIR]
 * Exits 0 when everything is fine, 1 with the list of problems otherwise. Needs `composer install`.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once __DIR__ . '/lib/translations.php';

list( , $options ) = odv_parse_args( $argv );
$source            = isset( $options['source'] ) ? rtrim( $options['source'], '/' ) : dirname( __DIR__ );

$errors = odv_check_translations( $source );
if ( array() !== $errors ) {
	fwrite( STDERR, implode( "\n", $errors ) . "\n" );
	fwrite( STDERR, count( $errors ) . " translation problem(s).\n" );
	exit( 1 );
}

echo 'Translations OK (' . implode( ', ', array_keys( odv_load_po_files( $source ) ) ) . ").\n";

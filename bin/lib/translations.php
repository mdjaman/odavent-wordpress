<?php
/**
 * Translation checks and compilation (the gettext/gettext dev dependency; no msgfmt needed).
 *
 * Development tooling only. Requires vendor/autoload.php to be loaded by the caller.
 */

use Gettext\Generator\MoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;

require_once __DIR__ . '/release.php';

/** Unique identity of an entry: context and singular. */
function odv_entry_key( Translation $t ): string {
	return (string) $t->getContext() . "\x04" . $t->getOriginal();
}

function odv_entry_label( Translation $t ): string {
	return ( null !== $t->getContext() ? '[' . $t->getContext() . '] ' : '' ) . '"' . $t->getOriginal() . '"';
}

/** @return Translations[] Locale => parsed .po files of a plugin tree. */
function odv_load_po_files( string $source ): array {
	$files = glob( $source . '/languages/' . ODV_SLUG . '-*.po' );
	$out   = array();
	foreach ( $files ?: array() as $file ) {
		if ( 1 === preg_match( '/-([A-Za-z_]+)\.po$/', basename( $file ), $m ) ) {
			$out[ $m[1] ] = ( new PoLoader() )->loadFile( $file );
		}
	}
	ksort( $out );

	return $out;
}

/**
 * Problems of the translations of a plugin tree: an empty list means they are complete and consistent.
 *
 * @return string[]
 */
function odv_check_translations( string $source ): array {
	$errors   = array();
	$pot_file = $source . '/languages/' . ODV_SLUG . '.pot';
	if ( ! is_file( $pot_file ) ) {
		return array( 'languages/' . ODV_SLUG . '.pot is missing (run bin/make-pot.sh).' );
	}

	$pot = array();
	foreach ( ( new PoLoader() )->loadFile( $pot_file ) as $t ) {
		$pot[ odv_entry_key( $t ) ] = $t;
	}

	$po_files = odv_load_po_files( $source );
	if ( array() === $po_files ) {
		$errors[] = 'No languages/' . ODV_SLUG . '-{locale}.po file found.';
	}

	foreach ( $po_files as $locale => $po ) {
		$name   = ODV_SLUG . "-{$locale}.po";
		$plural = (string) $po->getHeaders()->get( 'Plural-Forms' );
		if ( 1 !== preg_match( '/^nplurals=(\d+);\s*plural=.+;?$/', $plural, $n ) ) {
			$errors[] = "{$name}: the Plural-Forms header is missing or malformed.";
			$n        = array( 1 => '0' );
		}
		if ( $po->getLanguage() !== $locale ) {
			$errors[] = "{$name}: the Language header is '" . (string) $po->getLanguage() . "', expected '{$locale}'.";
		}

		$seen = array();
		foreach ( $po as $t ) {
			$label = odv_entry_label( $t );
			$key   = odv_entry_key( $t );
			$seen[ $key ] = true;

			if ( ! isset( $pot[ $key ] ) ) {
				$errors[] = "{$name}: msgid not in the .pot (stale or misspelt): {$label}";
				continue;
			}

			// The library reads an empty msgstr as null.
			$forms = array_map( 'strval', array_merge( array( $t->getTranslation() ), $t->getPluralTranslations() ) );
			if ( null !== $t->getPlural() && (int) $n[1] > 0 && count( $forms ) !== (int) $n[1] ) {
				$errors[] = "{$name}: {$label} has " . count( $forms ) . " plural forms, the header says {$n[1]}.";
			}

			$source_set = odv_placeholders( $t->getOriginal() );
			if ( null !== $t->getPlural() ) {
				$source_set = array_values( array_unique( array_merge( $source_set, odv_placeholders( $t->getPlural() ) ) ) );
				sort( $source_set );
			}
			foreach ( $forms as $form ) {
				if ( '' === $form ) {
					$errors[] = "{$name}: empty msgstr for {$label}";
					continue;
				}
				$found = odv_placeholders( $form );
				if ( null !== $t->getPlural() ) {
					$found = array_values( array_unique( $found ) );
				}
				if ( $found !== $source_set ) {
					$errors[] = "{$name}: placeholders of {$label} are [" . implode( ' ', $source_set ) . '] but the translation has [' . implode( ' ', $found ) . ']';
				}
			}
		}

		foreach ( $pot as $key => $t ) {
			if ( ! isset( $seen[ $key ] ) ) {
				$errors[] = "{$name}: missing translation for " . odv_entry_label( $t );
			}
		}
	}

	return $errors;
}

/**
 * The compiled .mo bytes of a parsed .po. The headers are written explicitly: without them WordPress
 * has no Plural-Forms rule for the language (French counts 0 as singular, English does not).
 */
function odv_compile_mo( Translations $po ): string {
	return ( new MoGenerator() )->includeHeaders()->generateString( $po );
}

/**
 * The JSON translation files WordPress loads for the plugin's scripts, one per script: the same
 * format and file name `wp i18n make-json` writes ({domain}-{locale}-{md5 of the script path relative
 * to the plugin root}.json). A string goes to a script's file when the .po references that script.
 *
 * @return array<string, string> File name => JSON.
 */
function odv_js_translation_files( string $locale, Translations $po ): array {
	$by_script = array();
	foreach ( $po as $t ) {
		$forms = array_merge( array( (string) $t->getTranslation() ), $t->getPluralTranslations() );
		if ( '' === (string) $t->getTranslation() ) {
			continue;
		}
		$key = ( null !== $t->getContext() && '' !== $t->getContext() ? $t->getContext() . "\x04" : '' ) . $t->getOriginal();
		foreach ( array_keys( $t->getReferences()->toArray() ) as $reference ) {
			if ( 1 === preg_match( '#\.js$#', $reference ) ) {
				$by_script[ $reference ][ $key ] = $forms;
			}
		}
	}

	$files = array();
	ksort( $by_script );
	foreach ( $by_script as $script => $messages ) {
		$json = json_encode(
			array(
				'translation-revision-date' => (string) $po->getHeaders()->get( 'PO-Revision-Date' ),
				'generator'                 => 'odavent-events build-zip',
				'source'                    => $script,
				'domain'                    => 'messages',
				'locale_data'               => array(
					'messages' => array(
						'' => array(
							'domain'       => 'messages',
							'lang'         => $locale,
							'plural-forms' => (string) $po->getHeaders()->get( 'Plural-Forms' ),
						),
					) + $messages,
				),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
		);
		$files[ ODV_SLUG . "-{$locale}-" . md5( $script ) . '.json' ] = $json;
	}

	return $files;
}

<?php
namespace Odavent\Events\Tests\Unit\I18n;

use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Odavent\Events\Tests\TestCase;

/**
 * The shipped translations are complete and consistent with the template (.pot).
 */
class TranslationsTest extends TestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = dirname( __DIR__, 3 );
	}

	/** @return string[] */
	private function po_files(): array {
		$files = glob( $this->root . '/languages/*.po' ) ?: array();
		$this->assertNotEmpty( $files, 'No .po file found.' );

		return $files;
	}

	private function key( Translation $t ): string {
		return (string) $t->getContext() . "\x04" . $t->getOriginal();
	}

	/** Placeholders of a string, sorted, so word order may change but the set may not. */
	private function placeholders( string $text ): array {
		preg_match_all( '/%(?:\d+\$)?[-+ 0\']*\d*(?:\.\d+)?[sdfFeEgGuxXobcl]/', str_replace( '%%', '', $text ), $m );
		sort( $m[0] );

		return $m[0];
	}

	public function test_the_french_translation_exists(): void {
		$this->assertContains( $this->root . '/languages/odavent-events-fr_FR.po', $this->po_files() );
		$this->assertFileExists( $this->root . '/languages/odavent-events.pot' );
	}

	public function test_no_po_entry_has_an_empty_translation(): void {
		foreach ( $this->po_files() as $file ) {
			foreach ( ( new PoLoader() )->loadFile( $file ) as $t ) {
				// The library reads an empty msgstr as null.
				$this->assertNotSame( '', (string) $t->getTranslation(), basename( $file ) . ': empty msgstr for "' . $t->getOriginal() . '"' );
				foreach ( $t->getPluralTranslations() as $i => $form ) {
					$this->assertNotSame( '', (string) $form, basename( $file ) . ': empty msgstr[' . ( $i + 1 ) . '] for "' . $t->getOriginal() . '"' );
				}
			}
		}
	}

	public function test_every_msgid_of_a_po_is_in_the_pot_and_back(): void {
		$pot = array();
		foreach ( ( new PoLoader() )->loadFile( $this->root . '/languages/odavent-events.pot' ) as $t ) {
			$pot[ $this->key( $t ) ] = $t;
		}
		$this->assertGreaterThan( 100, count( $pot ) );

		foreach ( $this->po_files() as $file ) {
			$po = array();
			foreach ( ( new PoLoader() )->loadFile( $file ) as $t ) {
				$po[ $this->key( $t ) ] = $t;
				$this->assertArrayHasKey( $this->key( $t ), $pot, basename( $file ) . ': msgid not in the .pot: "' . $t->getOriginal() . '"' );
			}
			// Complete: nothing in the template is left untranslated.
			foreach ( array_keys( $pot ) as $key ) {
				$this->assertArrayHasKey( $key, $po, basename( $file ) . ': missing entry for ' . str_replace( "\x04", ' / ', $key ) );
			}
		}
	}

	public function test_the_plural_forms_header_is_present_and_plural_entries_have_every_form(): void {
		foreach ( $this->po_files() as $file ) {
			$po = ( new PoLoader() )->loadFile( $file );
			$this->assertMatchesRegularExpression( '/^nplurals=\d+;\s*plural=.+;$/', (string) $po->getHeaders()->get( 'Plural-Forms' ), basename( $file ) );
			$this->assertMatchesRegularExpression( '/^[a-z]{2,3}(_[A-Z]{2})?$/', (string) $po->getLanguage(), basename( $file ) );

			preg_match( '/nplurals=(\d+)/', (string) $po->getHeaders()->get( 'Plural-Forms' ), $m );
			foreach ( $po as $t ) {
				if ( null !== $t->getPlural() ) {
					$this->assertCount( (int) $m[1], array_merge( array( $t->getTranslation() ), $t->getPluralTranslations() ), basename( $file ) . ': ' . $t->getOriginal() );
				}
			}
		}
	}

	public function test_placeholders_are_identical_between_msgid_and_msgstr(): void {
		foreach ( $this->po_files() as $file ) {
			foreach ( ( new PoLoader() )->loadFile( $file ) as $t ) {
				$source = $this->placeholders( $t->getOriginal() );
				if ( null !== $t->getPlural() ) {
					$source = array_values( array_unique( array_merge( $source, $this->placeholders( $t->getPlural() ) ) ) );
					sort( $source );
				}
				$forms = array_map( 'strval', array_merge( array( $t->getTranslation() ), $t->getPluralTranslations() ) );
				foreach ( $forms as $form ) {
					$found = $this->placeholders( $form );
					if ( null !== $t->getPlural() ) {
						$found = array_values( array_unique( $found ) );
					}
					$this->assertSame( $source, $found, basename( $file ) . ': placeholders differ for "' . $t->getOriginal() . '"' );
				}
			}
		}
	}

	public function test_the_check_script_passes_on_the_repository_and_fails_on_a_broken_po(): void {
		$script = $this->root . '/bin/check-translations.php';
		$this->assertFileExists( $script );

		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' 2>&1', $lines, $code );
		$this->assertSame( 0, $code, implode( "\n", $lines ) );

		$tmp = sys_get_temp_dir() . '/odv-i18n-' . bin2hex( random_bytes( 4 ) );
		mkdir( $tmp . '/languages', 0777, true );
		$pot = (string) file_get_contents( $this->root . '/languages/odavent-events.pot' );
		$po  = (string) file_get_contents( $this->root . '/languages/odavent-events-fr_FR.po' );
		file_put_contents( $tmp . '/languages/odavent-events.pot', $pot );

		$cases = array(
			'empty msgstr'     => preg_replace( '/msgstr "Complet"/u', 'msgstr ""', $po, 1 ),
			'unknown msgid'    => $po . "\nmsgid \"Not in the template\"\nmsgstr \"Absent du modèle\"\n",
			'placeholder lost' => str_replace( 'Événement n° %d', 'Événement n° ', $po ),
			// Complete in every other way, but the browser would not get the string.
			'js reference lost' => preg_replace( '/(?:^#: assets\/js\/editor\.js:\d+\n)+/m', '', $po, 1 ),
		);
		$this->assertNotSame( $po, $cases['js reference lost'], 'The case must really change the .po.' );
		foreach ( $cases as $label => $broken ) {
			file_put_contents( $tmp . '/languages/odavent-events-fr_FR.po', $broken );
			exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $script ) . ' --source=' . escapeshellarg( $tmp ) . ' 2>&1', $out, $code );
			$this->assertNotSame( 0, $code, "check-translations accepted: {$label}" );
			$out = array();
		}

		array_map( 'unlink', glob( $tmp . '/languages/*' ) ?: array() );
		rmdir( $tmp . '/languages' );
		rmdir( $tmp );
	}
}

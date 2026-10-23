<?php
namespace Odavent\Events\Tests\Unit\Render;

use Brain\Monkey\Filters;
use Odavent\Events\Render\Template;
use Odavent\Events\Tests\Support\RenderTestCase;
use Odavent\Events\Tests\Support\WpStubs;

class TemplateTest extends RenderTestCase {

	/** @var string */
	private $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/odv-tpl-' . bin2hex( random_bytes( 6 ) );
		foreach ( array( 'child', 'parent', 'plugin' ) as $dir ) {
			mkdir( $this->root . '/' . $dir . '/odavent', 0777, true );
		}
		mkdir( $this->root . '/outside', 0777, true );

		WpStubs::$stylesheet_dir = $this->root . '/child';
		WpStubs::$template_dir   = $this->root . '/parent';
		Template::override_plugin_dir( $this->root . '/plugin' );
	}

	protected function tearDown(): void {
		Template::override_plugin_dir( null );
		$this->remove( $this->root );
		parent::tearDown();
	}

	private function remove( string $path ): void {
		foreach ( (array) glob( $path . '/{,.}*', GLOB_BRACE ) as $entry ) {
			if ( in_array( basename( $entry ), array( '.', '..' ), true ) ) {
				continue;
			}
			is_dir( $entry ) ? $this->remove( $entry ) : unlink( $entry );
		}
		is_dir( $path ) && rmdir( $path );
	}

	private function put( string $where, string $name, string $code ): string {
		$dir  = 'plugin' === $where ? $this->root . '/plugin' : $this->root . '/' . $where . '/odavent';
		$file = $dir . '/' . $name . '.php';
		// Patchwork appends code to every file it loads; end in PHP mode so that code is not printed.
		file_put_contents( $file, $code . '<?php ' );

		return $file;
	}

	public function test_child_theme_beats_parent_beats_plugin(): void {
		$this->put( 'plugin', 'speakers', 'plugin' );
		$this->assertSame( 'plugin', Template::render( 'speakers', array() ) );

		$this->put( 'parent', 'speakers', 'parent' );
		$this->assertSame( 'parent', Template::render( 'speakers', array() ) );

		$this->put( 'child', 'speakers', 'child' );
		$this->assertSame( 'child', Template::render( 'speakers', array() ) );
	}

	public function test_a_missing_template_gives_an_empty_string(): void {
		$this->assertSame( '', Template::render( 'nothing-here', array() ) );
	}

	public function test_the_variables_are_available_to_the_template(): void {
		$this->put( 'plugin', 'greet', 'Hello <?php echo $who; ?> / <?php echo count( $items ); ?>' );

		$this->assertSame( 'Hello Ada / 2', Template::render( 'greet', array( 'who' => 'Ada', 'items' => array( 1, 2 ) ) ) );
	}

	public function bad_names(): array {
		return array(
			'parent directory'     => array( '../outside/evil' ),
			'deep traversal'       => array( '../../../../etc/passwd' ),
			'slash'                => array( 'sub/evil' ),
			'backslash'            => array( 'sub\\evil' ),
			'dot'                  => array( 'evil.php' ),
			'null byte'            => array( "speakers\0../evil" ),
			'upper case'           => array( 'Speakers' ),
			'trailing newline'     => array( "speakers\n" ),
			'leading space'        => array( ' speakers' ),
			'underscore'           => array( 'my_template' ),
			'empty'                => array( '' ),
			'absolute'             => array( '/etc/passwd' ),
		);
	}

	/**
	 * @dataProvider bad_names
	 */
	public function test_names_that_are_not_plain_slugs_are_rejected( string $name ): void {
		file_put_contents( $this->root . '/outside/evil.php', 'LEAK' );
		file_put_contents( $this->root . '/plugin/speakers.php', 'OK' );
		file_put_contents( $this->root . '/plugin/odavent/evil.php', 'LEAK' );

		$this->assertSame( '', Template::render( $name, array() ) );
	}

	public function test_a_trailing_newline_cannot_slip_past_the_name_check(): void {
		// PCRE's "$" matches before a final newline; the check must not. A file really named with a newline exists here.
		file_put_contents( $this->root . "/plugin/speakers\n.php", 'LEAK' );

		Filters\expectApplied( 'odavent_events_template' )->never();

		$this->assertSame( '', Template::render( "speakers\n", array() ) );
	}

	public function test_a_traversal_name_is_not_even_passed_to_the_template_filter(): void {
		Filters\expectApplied( 'odavent_events_template' )->never();

		Template::render( '../x', array() );
	}

	public function test_the_template_cannot_see_this_or_the_callers_scope(): void {
		$this->put( 'plugin', 'probe', '<?php echo isset( $this ) ? "this" : "no-this"; echo "|"; echo isset( $__odv_file, $file, $vars ) ? "leak" : "clean"; echo "|"; echo isset( $name ) ? "name" : "no-name"; ?>' );

		$name = 'secret local';
		$this->assertSame( 'no-this|clean|no-name', Template::render( 'probe', array() ) );
		$this->assertSame( 'secret local', $name );
	}

	public function test_variables_named_file_or_vars_are_usable(): void {
		$this->put( 'plugin', 'names', '<?php echo $file, "|", $vars; ?>' );

		$this->assertSame( 'f|v', Template::render( 'names', array( 'file' => 'f', 'vars' => 'v' ) ) );
	}

	public function test_variables_cannot_overwrite_the_renderers_own_locals_or_this(): void {
		$this->put( 'plugin', 'clash', '<?php echo $view; ?>|<?php echo isset( $this ) ? "this" : "no-this"; ?>' );

		$out = Template::render( 'clash', array( 'view' => 'V', 'this' => 'evil', '__odv_file' => '/etc/passwd', '__odv_vars' => 'x' ) );

		$this->assertSame( 'V|no-this', $out );
	}

	public function test_template_variables_do_not_leak_into_the_class_or_the_next_render(): void {
		$this->put( 'plugin', 'one', '<?php $leak = "from-one"; echo $a; ?>' );
		$this->put( 'plugin', 'two', '<?php echo isset( $leak ) ? $leak : "none"; echo isset( $a ) ? "a" : "-"; ?>' );

		$this->assertSame( 'A', Template::render( 'one', array( 'a' => 'A' ) ) );
		$this->assertSame( 'none-', Template::render( 'two', array() ) );
	}

	public function test_the_template_and_view_data_filters_are_applied(): void {
		$other = $this->put( 'child', 'other', 'OTHER <?php echo $extra; ?>' );
		$this->put( 'plugin', 'speakers', 'ORIGINAL' );

		Filters\expectApplied( 'odavent_events_template' )->once()->with( $this->root . '/plugin/speakers.php', 'speakers' )->andReturn( $other );
		Filters\expectApplied( 'odavent_events_view_data' )->once()->with( array( 'a' => 1 ), 'speakers' )->andReturn( array( 'extra' => 'X' ) );

		$this->assertSame( 'OTHER X', Template::render( 'speakers', array( 'a' => 1 ) ) );
	}

	public function test_a_filter_returning_nothing_usable_gives_an_empty_string(): void {
		$this->put( 'plugin', 'speakers', 'ORIGINAL' );

		Filters\expectApplied( 'odavent_events_template' )->andReturn( false );

		$this->assertSame( '', Template::render( 'speakers', array() ) );
	}

	public function test_a_filter_returning_a_missing_file_gives_an_empty_string(): void {
		$this->put( 'plugin', 'speakers', 'ORIGINAL' );

		Filters\expectApplied( 'odavent_events_template' )->andReturn( $this->root . '/does-not-exist.php' );

		$this->assertSame( '', Template::render( 'speakers', array() ) );
	}

	public function test_a_view_data_filter_returning_junk_keeps_the_original_vars(): void {
		$this->put( 'plugin', 'speakers', '<?php echo $a; ?>' );

		Filters\expectApplied( 'odavent_events_view_data' )->andReturn( 'junk' );

		$this->assertSame( '1', Template::render( 'speakers', array( 'a' => 1 ) ) );
	}

	public function test_a_throwing_template_does_not_leave_a_buffer_open(): void {
		$this->put( 'plugin', 'boom', 'partial output <?php throw new RuntimeException( "boom" ); ?>' );

		$level = ob_get_level();
		try {
			Template::render( 'boom', array() );
			$this->fail( 'Expected the exception to propagate.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}

		$this->assertSame( $level, ob_get_level() );
	}
}

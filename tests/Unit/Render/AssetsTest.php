<?php
namespace Odavent\Events\Tests\Unit\Render;

use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Render\Assets;
use Odavent\Events\Settings\Options;
use Odavent\Events\Tests\Support\RenderTestCase;

class AssetsTest extends RenderTestCase {

	private function options( bool $load_css ): Options {
		$options = Mockery::mock( Options::class );
		$options->shouldReceive( 'load_css' )->andReturn( $load_css );

		return $options;
	}

	public function test_the_stylesheet_is_registered_and_enqueued_when_enabled(): void {
		Functions\expect( 'wp_register_style' )->once()->with(
			'odavent-events',
			'https://blog.example.com/wp-content/plugins/odavent-events/assets/css/odavent.css',
			array(),
			ODAVENT_EVENTS_VERSION
		);
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'odavent-events' );
		Functions\when( 'wp_style_is' )->justReturn( false );

		( new Assets( $this->options( true ) ) )->enqueue_styles();
	}

	public function test_the_stylesheet_is_not_loaded_when_the_setting_is_off(): void {
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->never();

		( new Assets( $this->options( false ) ) )->enqueue_styles();
	}

	public function test_an_already_registered_stylesheet_is_not_registered_twice(): void {
		Functions\when( 'wp_style_is' )->justReturn( true );
		Functions\expect( 'wp_register_style' )->never();
		Functions\expect( 'wp_enqueue_style' )->once()->with( 'odavent-events' );

		( new Assets( $this->options( true ) ) )->enqueue_styles();
	}

	public function script_handles(): array {
		return array(
			'countdown'     => array( 'countdown' ),
			'agenda filter' => array( 'agenda-filter' ),
		);
	}

	/**
	 * @dataProvider script_handles
	 */
	public function test_scripts_load_in_the_footer_deferred_without_dependencies( string $handle ): void {
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\expect( 'wp_register_script' )->once()->with(
			'odavent-events-' . $handle,
			'https://blog.example.com/wp-content/plugins/odavent-events/assets/js/' . $handle . '.js',
			array(),
			ODAVENT_EVENTS_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		Functions\expect( 'wp_enqueue_script' )->once()->with( 'odavent-events-' . $handle );

		( new Assets( $this->options( true ) ) )->enqueue_script( $handle );
	}

	public function test_scripts_load_even_when_the_stylesheet_is_disabled(): void {
		Functions\when( 'wp_script_is' )->justReturn( false );
		Functions\expect( 'wp_register_script' )->once();
		Functions\expect( 'wp_enqueue_script' )->once();

		( new Assets( $this->options( false ) ) )->enqueue_script( 'countdown' );
	}

	public function test_an_unknown_script_handle_is_ignored(): void {
		Functions\expect( 'wp_register_script' )->never();
		Functions\expect( 'wp_enqueue_script' )->never();

		( new Assets( $this->options( true ) ) )->enqueue_script( '../../evil' );
	}

	public function test_the_script_files_exist(): void {
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/assets/js/countdown.js' );
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/assets/js/agenda-filter.js' );
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/assets/css/odavent.css' );
	}
}

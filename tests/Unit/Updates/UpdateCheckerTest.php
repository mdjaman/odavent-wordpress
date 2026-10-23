<?php
namespace Odavent\Events\Tests\Unit\Updates;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Tests\TestCase;
use Odavent\Events\Updates\Update_Checker;

/**
 * The updater installs code, so most of these tests are about what it refuses to offer.
 */
class UpdateCheckerTest extends TestCase {

	const PLUGIN = 'odavent-events/odavent-events.php';
	const PREFIX = 'https://github.com/mdjaman/odavent-wordpress/releases/download/';

	/** @var array<string, mixed> Transient store. */
	private $transients = array();

	/** @var array<int, array> set_transient calls: [key, value, ttl]. */
	private $set_calls = array();

	/** @var Mockery\MockInterface */
	private $client;

	/** @var string Version given to the last checker; the default `checked` list reports the same. */
	private $installed = '1.0.0';

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_version'] = '6.5';
		Functions\when( 'add_action' )->justReturn( true );
		require_once dirname( __DIR__, 3 ) . '/odavent-events.php';

		$this->transients = array();
		$this->set_calls  = array();
		$_GET             = array();

		Functions\when( 'get_site_transient' )->alias(
			function ( $key ) {
				return array_key_exists( $key, $this->transients ) ? $this->transients[ $key ] : false;
			}
		);
		Functions\when( 'set_site_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				$this->set_calls[]        = array( $key, $value, $ttl );
				return true;
			}
		);
		Functions\when( 'current_user_can' )->justReturn( false );

		$this->client = Mockery::mock( Client::class );
	}

	protected function tearDown(): void {
		$_GET = array();
		parent::tearDown();
	}

	private function release( array $over = array() ): array {
		// The package path carries the version (v1.2.0/...); keep them in step unless a test sets the URL.
		if ( isset( $over['version'] ) && is_string( $over['version'] ) && ! array_key_exists( 'download_url', $over ) ) {
			$over['download_url'] = self::PREFIX . 'v' . $over['version'] . '/odavent-events.zip';
		}

		return array_merge(
			array(
				'version'       => '1.2.0',
				'download_url'  => self::PREFIX . 'v1.2.0/odavent-events.zip',
				'requires'      => '6.1',
				'requires_php'  => '7.4',
				'tested'        => '6.6',
				'changelog_url' => 'https://github.com/mdjaman/odavent-wordpress/releases/tag/v1.2.0',
			),
			$over
		);
	}

	/**
	 * @param array|null $data  What the endpoint answers; null makes the call fail.
	 * @param int        $times Expected number of HTTP calls.
	 */
	private function checker( ?array $data, string $installed = '1.0.0', $times = 1 ): Update_Checker {
		$result       = null === $data ? Result::fail( Result::UNAVAILABLE, 503, 'down' ) : Result::ok( $data );
		$this->installed = $installed;
		$this->client    = Mockery::mock( Client::class );
		$this->client->shouldReceive( 'plugin_update' )->times( $times )->andReturn( $result );

		return new Update_Checker( $this->client, $installed );
	}

	private function transient( array $extra = array() ): \stdClass {
		$t = (object) array_merge(
			array(
				'last_checked' => 1,
				'checked'      => array( self::PLUGIN => $this->installed ),
				'response'     => array(),
				'no_update'    => array(),
			),
			$extra
		);

		return $t;
	}

	// ----- Offering an update ------------------------------------------------------------------

	public function test_a_newer_release_is_injected_as_an_object_with_every_field(): void {
		$out = $this->checker( $this->release() )->inject( $this->transient() );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
		$entry = $out->response[ self::PLUGIN ];
		$this->assertInstanceOf( \stdClass::class, $entry );
		$this->assertSame( 'odavent-events', $entry->slug );
		$this->assertSame( self::PLUGIN, $entry->plugin );
		$this->assertSame( '1.2.0', $entry->new_version );
		$this->assertSame( self::PREFIX . 'v1.2.0/odavent-events.zip', $entry->package );
		$this->assertSame( 'https://github.com/mdjaman/odavent-wordpress/releases/tag/v1.2.0', $entry->url );
		$this->assertSame( '6.1', $entry->requires );
		$this->assertSame( '7.4', $entry->requires_php );
		$this->assertSame( '6.6', $entry->tested );
		$this->assertArrayNotHasKey( self::PLUGIN, $out->no_update );
	}

	public function test_an_equal_version_is_not_injected_and_goes_to_no_update(): void {
		$out = $this->checker( $this->release( array( 'version' => '1.0.0' ) ) )->inject( $this->transient() );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
		$this->assertInstanceOf( \stdClass::class, $out->no_update[ self::PLUGIN ] );
		$this->assertSame( 'odavent-events', $out->no_update[ self::PLUGIN ]->slug );
	}

	public function test_an_older_version_is_never_offered_no_downgrade(): void {
		$out = $this->checker( $this->release( array( 'version' => '0.9.9' ) ) )->inject( $this->transient() );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
	}

	public function test_versions_compare_numerically_not_as_strings(): void {
		$newer = $this->checker( $this->release( array( 'version' => '1.10.0' ) ), '1.9.0' )->inject( $this->transient() );
		$this->assertArrayHasKey( self::PLUGIN, $newer->response, '1.10.0 is newer than 1.9.0' );

		$this->transients = array();
		$older = $this->checker( $this->release( array( 'version' => '1.9.0' ) ), '1.10.0' )->inject( $this->transient() );
		$this->assertArrayNotHasKey( self::PLUGIN, $older->response, '1.9.0 is older than 1.10.0' );
	}

	public function test_the_installed_version_defaults_to_the_plugin_constant(): void {
		$data = $this->release( array( 'version' => ODAVENT_EVENTS_VERSION ) );
		$this->client->shouldReceive( 'plugin_update' )->once()->andReturn( Result::ok( $data ) );

		$out = ( new Update_Checker( $this->client ) )->inject( $this->transient( array( 'checked' => array() ) ) );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response, 'same as the installed constant: nothing to offer' );
	}

	// ----- Installed version is read live -------------------------------------------------------

	public function test_the_version_just_installed_is_not_offered_again_in_the_same_process(): void {
		// The process still holds the OLD constant (1.0.0); core rebuilt `checked` from disk after the upgrade.
		$t   = $this->transient( array( 'checked' => array( self::PLUGIN => '1.2.0' ) ) );
		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
		$this->assertSame( '1.2.0', $out->no_update[ self::PLUGIN ]->new_version );
	}

	public function test_a_newer_release_is_still_offered_when_checked_is_older(): void {
		$t   = $this->transient( array( 'checked' => array( self::PLUGIN => '1.1.0' ) ) );
		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
	}

	/**
	 * @dataProvider unusable_checked_lists
	 */
	public function test_a_missing_or_garbage_checked_entry_falls_back_to_the_loaded_version( $checked ): void {
		$t = $this->transient();
		if ( '__absent__' === $checked ) {
			unset( $t->checked );
		} else {
			$t->checked = $checked;
		}

		// Loaded version 1.2.0 equals the release: not offered. A garbage entry must not make it offerable.
		$out = $this->checker( $this->release(), '1.2.0' )->inject( $t );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertSame( '1.2.0', $out->no_update[ self::PLUGIN ]->new_version );
	}

	public function unusable_checked_lists(): array {
		return array(
			'absent'            => array( '__absent__' ),
			'not an array'      => array( 'x' ),
			'no entry for us'   => array( array( 'other/other.php' => '0.0.1' ) ),
			'garbage version'   => array( array( self::PLUGIN => 'not-a-version' ) ),
			'markup version'    => array( array( self::PLUGIN => '0.0.1"><script>' ) ),
			'array version'     => array( array( self::PLUGIN => array( '0.0.1' ) ) ),
			'empty version'     => array( array( self::PLUGIN => '' ) ),
			'trailing newline'  => array( array( self::PLUGIN => "0.0.1\n" ) ),
		);
	}

	// ----- Pre-releases ------------------------------------------------------------------------

	public function test_a_pre_release_is_not_offered_to_a_stable_install(): void {
		$out = $this->checker( $this->release( array( 'version' => '1.2.0-beta.1' ) ) )->inject( $this->transient() );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
	}

	public function test_a_pre_release_is_offered_to_a_pre_release_install(): void {
		$out = $this->checker( $this->release( array( 'version' => '1.2.0-beta.2' ) ), '1.2.0-beta.1' )->inject( $this->transient() );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
		$this->assertSame( '1.2.0-beta.2', $out->response[ self::PLUGIN ]->new_version );
	}

	public function test_the_stable_release_replaces_a_pre_release_install(): void {
		$out = $this->checker( $this->release( array( 'version' => '1.2.0' ) ), '1.2.0-rc.1' )->inject( $this->transient() );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
	}

	public function test_a_pre_release_of_the_installed_stable_version_is_not_a_newer_version(): void {
		$out = $this->checker( $this->release( array( 'version' => '1.0.0-beta' ) ), '1.0.0-rc.1' )->inject( $this->transient() );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	// ----- P13: version validation -------------------------------------------------------------

	/**
	 * @dataProvider bad_versions
	 */
	public function test_a_garbage_version_is_a_failure_and_is_never_injected( $version ): void {
		$out = $this->checker( $this->release( array( 'version' => $version ) ) )->inject( $this->transient() );

		$this->assertSame( array(), $out->response );
		$this->assertSame( array(), $out->no_update );
		$this->assertFalse( $this->cached_release(), 'a rejected release is cached as a failure' );
		$this->assertSame( 3600, $this->set_calls[0][2] );
	}

	public function bad_versions(): array {
		return array(
			'empty'        => array( '' ),
			'word'         => array( 'latest' ),
			'v prefix'     => array( 'v1.2.0' ),
			'two parts'    => array( '1.2' ),
			'four parts'   => array( '1.2.3.4' ),
			'trailing nl'  => array( "1.2.0\n" ),
			'injection'    => array( '1.2.0"><script>alert(1)</script>' ),
			'space suffix' => array( '1.2.0 beta' ),
			'empty suffix' => array( '1.2.0-' ),
			'array'        => array( array( '1.2.0' ) ),
			'int'          => array( 120 ),
			'null'         => array( null ),
			'very long'    => array( '1.2.0-' . str_repeat( 'a', 200 ) ),
		);
	}

	// ----- P13: download_url validation --------------------------------------------------------

	/**
	 * @dataProvider bad_download_urls
	 */
	public function test_a_download_url_outside_the_pinned_source_is_refused( $url ): void {
		$out = $this->checker( $this->release( array( 'download_url' => $url ) ) )->inject( $this->transient() );

		$this->assertSame( array(), $out->response, 'never injected' );
		$this->assertSame( array(), $out->no_update );
		$this->assertFalse( $this->cached_release(), 'cached as a failure' );
		$this->assertSame( 3600, $this->set_calls[0][2] );
	}

	public function bad_download_urls(): array {
		$p = self::PREFIX;

		return array(
			'other host'               => array( 'https://evil.example.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'other repo'               => array( 'https://github.com/someone/else/releases/download/v1.2.0/x.zip' ),
			'other repo same owner'    => array( 'https://github.com/mdjaman/other/releases/download/v1.2.0/x.zip' ),
			'same repo, not a release' => array( 'https://github.com/mdjaman/odavent-wordpress/archive/main.zip' ),
			'http'                     => array( 'http://github.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'uppercase scheme'         => array( 'HTTPS://github.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'uppercase host'           => array( 'https://GITHUB.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'uppercase owner'          => array( 'https://github.com/MDJAMAN/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'dot dot'                  => array( $p . '../../../../evil/repo/x.zip' ),
			'dot dot mid'              => array( $p . 'v1/../../x.zip' ),
			'encoded dot dot'          => array( $p . '%2e%2e/%2E%2e/x.zip' ),
			'userinfo before host'     => array( 'https://github.com@evil.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'userinfo after prefix'    => array( $p . 'v1@evil.com/x.zip' ),
			'space'                    => array( $p . 'v1.2.0/odavent events.zip' ),
			'newline'                  => array( $p . "v1.2.0/x.zip\nLocation: https://evil.example.com/" ),
			'tab'                      => array( $p . "v1.2.0/\tx.zip" ),
			'nul byte'                 => array( $p . "v1.2.0/x.zip\0.png" ),
			'trailing space'           => array( $p . 'v1.2.0/x.zip ' ),
			'leading space'            => array( ' ' . $p . 'v1.2.0/x.zip' ),
			'lookalike prefix host'    => array( 'https://github.com.evil.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
			'lookalike prefix path'    => array( 'https://github.com/mdjaman/odavent-wordpress-evil/releases/download/v1.2.0/x.zip' ),
			'backslash'                => array( $p . 'v1\\..\\x.zip' ),
			'query'                    => array( $p . 'v1.2.0/x.zip?redirect=https://evil.example.com' ),
			'fragment'                 => array( $p . 'v1.2.0/x.zip#frag' ),
			'quote'                    => array( $p . 'v1.2.0/x".zip' ),
			'just the prefix'          => array( $p ),
			'empty'                    => array( '' ),
			'null'                     => array( null ),
			'array'                    => array( array( $p . 'x.zip' ) ),
			'dot only'                 => array( $p . '.' ),
			'tag dir only'             => array( $p . 'v1.2.0/' ),
			'double-encoded dot dot'   => array( $p . '%252e%252e/x.zip' ),
			'port after file'          => array( $p . 'v1/x.zip:80' ),
			'port after tag'           => array( $p . 'v1.2.0:80/x.zip' ),
			'nested path'              => array( $p . 'v1.2.0/a/x.zip' ),
			'not a zip'                => array( $p . 'v1.2.0/x.tar.gz' ),
			'trailing junk'            => array( $p . 'v1.2.0/x.zip.evil' ),
			'trailing slash'           => array( $p . 'v1.2.0/x.zip/' ),
			'percent in file'          => array( $p . 'v1.2.0/x%20y.zip' ),
			'older tag, higher claim'  => array( $p . 'v1.1.0/odavent-events.zip' ),
			'prefix-sharing tag'       => array( $p . 'v1.2.01/odavent-events.zip' ),
			'tag with suffix'          => array( $p . 'v1.2.0-beta/odavent-events.zip' ),
			'double v'                 => array( $p . 'vv1.2.0/odavent-events.zip' ),
			'other letter'             => array( $p . 'x1.2.0/odavent-events.zip' ),
			'no version at all'        => array( $p . 'odavent-events.zip' ),
			'protocol relative'        => array( '//github.com/mdjaman/odavent-wordpress/releases/download/v1.2.0/x.zip' ),
		);
	}

	public function test_a_missing_download_url_is_refused(): void {
		$data = $this->release();
		unset( $data['download_url'] );
		$out = $this->checker( $data )->inject( $this->transient() );

		$this->assertSame( array(), $out->response );
		$this->assertFalse( $this->cached_release() );
	}

	/**
	 * @dataProvider matching_tag_paths
	 */
	public function test_a_package_path_matching_the_claimed_version_is_accepted( string $tag_dir ): void {
		$url = self::PREFIX . $tag_dir . '/odavent-events-1.2.0.zip';
		$out = $this->checker( $this->release( array( 'download_url' => $url ) ) )->inject( $this->transient() );

		$this->assertSame( $url, $out->response[ self::PLUGIN ]->package );
	}

	public function matching_tag_paths(): array {
		return array(
			'lowercase v' => array( 'v1.2.0' ),
			'uppercase V' => array( 'V1.2.0' ),
			'bare'        => array( '1.2.0' ),
		);
	}

	public function test_a_pre_release_tag_path_must_match_the_pre_release_version(): void {
		$url = self::PREFIX . 'v1.3.0-beta.1/odavent-events.zip';
		$out = $this->checker( $this->release( array( 'version' => '1.3.0-beta.1', 'download_url' => $url ) ), '1.2.0-rc.1' )->inject( $this->transient() );
		$this->assertSame( $url, $out->response[ self::PLUGIN ]->package );

		// The same zip claimed under a different pre-release is refused.
		$this->transients = array();
		$out = $this->checker( $this->release( array( 'version' => '1.3.0-beta.2', 'download_url' => $url ) ), '1.2.0-rc.1' )->inject( $this->transient() );
		$this->assertSame( array(), $out->response );
	}

	public function test_a_download_url_inside_the_pinned_source_is_accepted(): void {
		$url = self::PREFIX . 'v1.2.0/odavent-events-1.2.0.zip';
		$out = $this->checker( $this->release( array( 'download_url' => $url ) ) )->inject( $this->transient() );

		$this->assertSame( $url, $out->response[ self::PLUGIN ]->package );
	}

	// ----- P13: the prefix filter --------------------------------------------------------------

	public function test_the_prefix_filter_can_point_at_another_https_source(): void {
		Filters\expectApplied( 'odavent_events_update_package_prefix' )
			->with( self::PREFIX )
			->andReturn( 'https://downloads.example.com/odavent/' );

		$url = 'https://downloads.example.com/odavent/1.2.0/odavent-events.zip';
		$out = $this->checker( $this->release( array( 'download_url' => $url ) ) )->inject( $this->transient() );

		$this->assertSame( $url, $out->response[ self::PLUGIN ]->package );
	}

	public function test_with_a_custom_prefix_the_github_source_is_no_longer_trusted(): void {
		Filters\expectApplied( 'odavent_events_update_package_prefix' )->andReturn( 'https://downloads.example.com/odavent/' );

		$out = $this->checker( $this->release() )->inject( $this->transient() );

		$this->assertSame( array(), $out->response );
		$this->assertFalse( $this->cached_release() );
	}

	/**
	 * @dataProvider junk_prefixes
	 */
	public function test_a_junk_prefix_from_the_filter_is_not_honoured( $junk ): void {
		Filters\expectApplied( 'odavent_events_update_package_prefix' )->andReturn( $junk );

		// The junk prefix would accept this URL (when it is a string); the constant must not.
		$evil = is_string( $junk ) ? $junk . '1.2.0/x.zip' : 'https://evil.example.com/x.zip';
		$out  = $this->checker( $this->release( array( 'download_url' => $evil ) ) )->inject( $this->transient() );

		$this->assertSame( array(), $out->response );
	}

	/**
	 * @dataProvider junk_prefixes
	 */
	public function test_a_junk_prefix_from_the_filter_falls_back_to_the_constant( $junk ): void {
		Filters\expectApplied( 'odavent_events_update_package_prefix' )->andReturn( $junk );

		$out = $this->checker( $this->release() )->inject( $this->transient() );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
	}

	public function junk_prefixes(): array {
		return array(
			'array'             => array( array( 'https://evil.example.com/' ) ),
			'null'              => array( null ),
			'false'             => array( false ),
			'int'               => array( 7 ),
			'http'              => array( 'http://evil.example.com/' ),
			'empty'             => array( '' ),
			'ftp'               => array( 'ftp://evil.example.com/' ),
			'bare scheme'       => array( 'https://' ),
			'no trailing slash' => array( 'https://github.com' ),
			'whitespace'        => array( "https://evil.example.com/ x/" ),
			'newline'           => array( "https://evil.example.com/\n" ),
			'host only slash'   => array( 'https://github.com/' ),
			'ip only'           => array( 'https://127.0.0.1/' ),
			'ip with port only' => array( 'https://127.0.0.1:8443/' ),
		);
	}

	// ----- P13: optional fields ----------------------------------------------------------------

	/**
	 * @dataProvider bad_changelog_urls
	 */
	public function test_a_bad_changelog_url_is_dropped_but_the_release_is_still_offered( $url ): void {
		$out = $this->checker( $this->release( array( 'changelog_url' => $url ) ) )->inject( $this->transient() );

		$entry = $out->response[ self::PLUGIN ];
		$this->assertSame( '1.2.0', $entry->new_version );
		$this->assertStringStartsWith( 'https://github.com/', $entry->url, 'falls back to the repository page' );
		$this->assertStringNotContainsString( 'evil', $entry->url );
	}

	public function bad_changelog_urls(): array {
		return array(
			'other host'     => array( 'https://evil.example.com/changes' ),
			'http'           => array( 'http://github.com/mdjaman/odavent-wordpress/releases' ),
			'lookalike'      => array( 'https://github.com.evil.com/x' ),
			'userinfo'       => array( 'https://github.com@evil.com/x' ),
			'javascript'     => array( 'javascript:alert(1)' ),
			'newline'        => array( "https://github.com/x\nSet-Cookie: a=b" ),
			'quote'          => array( 'https://github.com/x"onmouseover="alert(1)' ),
			'angle bracket'  => array( 'https://github.com/x<script>' ),
			'uppercase'      => array( 'HTTPS://github.com/x' ),
			'array'          => array( array( 'https://github.com/x' ) ),
			'dot dot'        => array( 'https://github.com/../evil' ),
		);
	}

	public function test_an_absent_changelog_url_is_fine(): void {
		$data = $this->release();
		unset( $data['changelog_url'] );
		$out = $this->checker( $data )->inject( $this->transient() );

		$this->assertSame( '1.2.0', $out->response[ self::PLUGIN ]->new_version );
	}

	/**
	 * @dataProvider bad_requirement_values
	 */
	public function test_bad_requirement_fields_are_dropped_but_the_release_is_still_offered( $value ): void {
		$data = $this->release(
			array(
				'requires'     => $value,
				'requires_php' => $value,
				'tested'       => $value,
			)
		);
		$out = $this->checker( $data )->inject( $this->transient() );

		$entry = $out->response[ self::PLUGIN ];
		$this->assertSame( '1.2.0', $entry->new_version );
		$this->assertFalse( property_exists( $entry, 'requires' ) );
		$this->assertFalse( property_exists( $entry, 'requires_php' ) );
		$this->assertFalse( property_exists( $entry, 'tested' ) );
	}

	public function bad_requirement_values(): array {
		return array(
			'markup'     => array( '<script>alert(1)</script>' ),
			'text'       => array( 'latest' ),
			'four parts' => array( '1.2.3.4' ),
			'suffix'     => array( '6.1-beta' ),
			'newline'    => array( "6.1\n" ),
			'array'      => array( array( '6.1' ) ),
			'empty'      => array( '' ),
		);
	}

	public function test_valid_requirement_values_are_kept(): void {
		$out = $this->checker( $this->release( array( 'requires' => '6', 'requires_php' => '8.1', 'tested' => '6.6.2' ) ) )->inject( $this->transient() );

		$entry = $out->response[ self::PLUGIN ];
		$this->assertSame( '6', $entry->requires );
		$this->assertSame( '8.1', $entry->requires_php );
		$this->assertSame( '6.6.2', $entry->tested );
	}

	public function test_null_optional_fields_are_simply_absent(): void {
		$out = $this->checker( $this->release( array( 'requires' => null, 'requires_php' => null, 'tested' => null, 'changelog_url' => null ) ) )->inject( $this->transient() );

		$entry = $out->response[ self::PLUGIN ];
		$this->assertFalse( property_exists( $entry, 'requires' ) );
		$this->assertStringStartsWith( 'https://github.com/mdjaman/odavent-wordpress', $entry->url );
	}

	// ----- Fetch and caching -------------------------------------------------------------------

	public function test_fetch_returns_the_validated_release_and_caches_it_for_twelve_hours(): void {
		$release = $this->checker( $this->release() )->fetch();

		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( self::PREFIX . 'v1.2.0/odavent-events.zip', $release['download_url'] );
		$this->assertCount( 1, $this->set_calls );
		$this->assertSame( 'odavent_events_update', $this->set_calls[0][0] );
		$this->assertSame( 12 * 3600, $this->set_calls[0][2] );
	}

	public function test_a_second_call_is_served_from_the_cache_without_http(): void {
		$checker = $this->checker( $this->release(), '1.0.0', 1 );

		$first  = $checker->fetch();
		$second = $checker->fetch();
		$third  = ( new Update_Checker( $this->client, '1.0.0' ) )->fetch();

		$this->assertSame( $first, $second );
		$this->assertSame( $first, $third );
		$this->assertCount( 1, $this->set_calls );
	}

	public function test_a_failure_is_cached_for_an_hour_and_the_second_call_makes_no_http(): void {
		$checker = $this->checker( null, '1.0.0', 1 );

		$this->assertNull( $checker->fetch() );
		$this->assertNull( $checker->fetch() );
		$this->assertNull( ( new Update_Checker( $this->client, '1.0.0' ) )->fetch() );

		$this->assertCount( 1, $this->set_calls );
		$this->assertSame( 'odavent_events_update', $this->set_calls[0][0] );
		$this->assertSame( 3600, $this->set_calls[0][2] );
		$this->assertFalse( $this->cached_release() );
	}

	public function test_a_failure_leaves_the_transient_untouched_except_for_our_own_entry(): void {
		$other = (object) array( 'slug' => 'other' );
		$t     = $this->transient( array( 'response' => array( 'other/other.php' => $other ) ) );

		$out = $this->checker( null )->inject( $t );

		$this->assertSame( $other, $out->response['other/other.php'] );
		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	public function test_a_cached_release_that_no_longer_validates_is_refetched(): void {
		$this->transients['odavent_events_update'] = $this->release( array( 'download_url' => 'https://evil.example.com/x.zip' ) );

		$release = $this->checker( $this->release() )->fetch();

		$this->assertSame( '1.2.0', $release['version'] );
	}

	public function test_a_corrupt_cache_value_is_refetched(): void {
		$this->transients['odavent_events_update'] = 'garbage';

		$release = $this->checker( $this->release() )->fetch();

		$this->assertSame( '1.2.0', $release['version'] );
	}

	public function test_force_check_bypasses_the_cache_for_a_user_who_can_update_plugins(): void {
		$this->transients['odavent_events_update'] = $this->release( array( 'version' => '1.1.0' ) );
		$_GET['force-check']                       = '1';
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return 'update_plugins' === $cap;
			}
		);

		$checker = $this->checker( $this->release(), '1.0.0', 1 );
		$release = $checker->fetch();
		$again   = $checker->fetch();

		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( '1.2.0', $again['version'], 'the forced fetch refreshed the cache; no second request' );
	}

	public function test_force_check_is_ignored_for_a_user_who_cannot_update_plugins(): void {
		$this->transients['odavent_events_update'] = $this->release( array( 'version' => '1.1.0' ) );
		$_GET['force-check']                       = '1';

		$release = $this->checker( $this->release(), '1.0.0', 0 )->fetch();

		$this->assertSame( '1.1.0', $release['version'] );
	}

	public function test_force_check_also_retries_a_cached_failure_for_a_permitted_user(): void {
		$this->transients['odavent_events_update'] = array( 'failed' => true );
		$_GET['force-check']                       = '1';
		Functions\when( 'current_user_can' )->justReturn( true );

		$release = $this->checker( $this->release() )->fetch();

		$this->assertSame( '1.2.0', $release['version'] );
	}

	// ----- Transient shapes --------------------------------------------------------------------

	public function test_a_transient_without_response_and_no_update_arrays_is_handled(): void {
		$t   = (object) array( 'last_checked' => 1 );
		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertIsArray( $out->response );
		$this->assertArrayHasKey( self::PLUGIN, $out->response );
	}

	public function test_a_transient_without_a_checked_list_is_handled(): void {
		$t   = (object) array( 'response' => array(), 'no_update' => array() );
		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertArrayHasKey( self::PLUGIN, $out->response );
	}

	public function test_no_update_is_created_when_missing(): void {
		$t   = (object) array( 'response' => array() );
		$out = $this->checker( $this->release( array( 'version' => '1.0.0' ) ) )->inject( $t );

		$this->assertIsArray( $out->no_update );
		$this->assertArrayHasKey( self::PLUGIN, $out->no_update );
	}

	public function test_non_array_response_and_no_update_are_replaced_by_arrays(): void {
		$t   = (object) array( 'response' => 'oops', 'no_update' => null );
		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertIsArray( $out->response );
		$this->assertIsArray( $out->no_update );
	}

	/**
	 * @dataProvider non_objects
	 */
	public function test_anything_that_is_not_an_object_is_returned_unchanged_without_http( $value ): void {
		$this->client->shouldReceive( 'plugin_update' )->never();

		$this->assertSame( $value, ( new Update_Checker( $this->client, '1.0.0' ) )->inject( $value ) );
	}

	public function non_objects(): array {
		return array(
			'false'  => array( false ),
			'null'   => array( null ),
			'array'  => array( array( 'response' => array() ) ),
			'string' => array( 'x' ),
		);
	}

	public function test_other_plugins_entries_are_preserved(): void {
		$other_response  = (object) array( 'slug' => 'akismet', 'new_version' => '9.9' );
		$other_no_update = (object) array( 'slug' => 'hello' );
		$t               = $this->transient(
			array(
				'response'  => array( 'akismet/akismet.php' => $other_response ),
				'no_update' => array( 'hello/hello.php' => $other_no_update ),
			)
		);

		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertSame( $other_response, $out->response['akismet/akismet.php'] );
		$this->assertSame( $other_no_update, $out->no_update['hello/hello.php'] );
		$this->assertSame( array( self::PLUGIN => '1.0.0' ), $out->checked );
		$this->assertSame( 1, $out->last_checked );
	}

	public function test_a_foreign_entry_for_our_own_plugin_is_replaced_by_ours(): void {
		$foreign = (object) array( 'slug' => 'odavent-events', 'package' => 'https://evil.example.com/x.zip', 'new_version' => '9.9.9' );
		$t       = $this->transient( array( 'response' => array( self::PLUGIN => $foreign ) ) );

		$out = $this->checker( $this->release() )->inject( $t );

		$this->assertSame( self::PREFIX . 'v1.2.0/odavent-events.zip', $out->response[ self::PLUGIN ]->package );
	}

	public function test_a_foreign_entry_for_our_own_plugin_is_removed_when_we_have_nothing_to_offer(): void {
		$foreign = (object) array( 'slug' => 'odavent-events', 'package' => 'https://evil.example.com/x.zip', 'new_version' => '9.9.9' );
		$t       = $this->transient( array( 'response' => array( self::PLUGIN => $foreign ) ) );

		$out = $this->checker( $this->release( array( 'version' => '1.0.0' ) ) )->inject( $t );

		$this->assertArrayNotHasKey( self::PLUGIN, $out->response );
	}

	public function test_the_release_is_cached_in_a_site_transient_not_a_per_site_one(): void {
		Functions\expect( 'get_transient' )->never();
		Functions\expect( 'set_transient' )->never();

		$this->checker( $this->release() )->fetch();

		$this->assertSame( 'odavent_events_update', $this->set_calls[0][0] );
	}

	// ----- Update URI ----------------------------------------------------------------------------

	public function test_the_update_entry_id_is_the_update_uri_header_value(): void {
		$out = $this->checker( $this->release() )->inject( $this->transient() );

		$this->assertSame( $this->header_update_uri(), $out->response[ self::PLUGIN ]->id );
	}

	public function test_the_plugin_header_update_uri_is_the_repo_base_of_the_package_prefix(): void {
		$base = substr( Update_Checker::PACKAGE_PREFIX, 0, -strlen( 'releases/download/' ) - 1 );

		$this->assertSame( 'https://github.com/mdjaman/odavent-wordpress', $base );
		$this->assertSame( $base, $this->header_update_uri() );
		$this->assertSame( $base, Update_Checker::REPO_URL );
	}

	private function header_update_uri(): string {
		$header = (string) file_get_contents( dirname( __DIR__, 3 ) . '/odavent-events.php', false, null, 0, 2048 );
		$this->assertSame( 1, preg_match( '/^\s*\*\s*Update URI:\s*(\S+)\s*$/m', $header, $m ), 'Update URI header present' );

		return $m[1];
	}

	// ----- Registration ------------------------------------------------------------------------

	public function test_register_hooks_the_transient_filter_and_plugins_api(): void {
		$checker = new Update_Checker( $this->client, '1.0.0' );
		Filters\expectAdded( 'pre_set_site_transient_update_plugins' )->once()->with( array( $checker, 'inject' ) );
		Filters\expectAdded( 'plugins_api' )->once()->with( array( $checker, 'plugin_info' ), 10, 3 );

		$checker->register();
		$this->addToAssertionCount( 1 );
	}

	public function test_nothing_is_fetched_just_by_registering(): void {
		$this->client->shouldReceive( 'plugin_update' )->never();

		( new Update_Checker( $this->client, '1.0.0' ) )->register();

		$this->addToAssertionCount( 1 );
	}

	// ----- plugins_api -------------------------------------------------------------------------

	private function info_args( string $slug = 'odavent-events' ): \stdClass {
		return (object) array( 'slug' => $slug );
	}

	public function test_plugin_info_answers_for_this_plugin(): void {
		$info = $this->checker( $this->release() )->plugin_info( false, 'plugin_information', $this->info_args() );

		$this->assertInstanceOf( \stdClass::class, $info );
		$this->assertSame( 'Odavent Events', $info->name );
		$this->assertSame( 'odavent-events', $info->slug );
		$this->assertSame( '1.2.0', $info->version );
		$this->assertSame( self::PREFIX . 'v1.2.0/odavent-events.zip', $info->download_link );
		$this->assertSame( 'https://github.com/mdjaman/odavent-wordpress', $info->homepage );
		$this->assertSame( '6.1', $info->requires );
		$this->assertSame( '7.4', $info->requires_php );
		$this->assertSame( '6.6', $info->tested );
		$this->assertArrayHasKey( 'description', $info->sections );
		$this->assertArrayHasKey( 'changelog', $info->sections );
	}

	public function test_plugin_info_changelog_is_an_escaped_link(): void {
		Functions\when( 'esc_url' )->alias(
			function ( $url ) {
				return 'ESC(' . $url . ')';
			}
		);

		$info = $this->checker( $this->release() )->plugin_info( false, 'plugin_information', $this->info_args() );

		$this->assertStringContainsString( '<a href="ESC(https://github.com/mdjaman/odavent-wordpress/releases/tag/v1.2.0)"', $info->sections['changelog'] );
	}

	public function test_plugin_info_without_a_changelog_url_has_no_changelog_section(): void {
		$info = $this->checker( $this->release( array( 'changelog_url' => null ) ) )->plugin_info( false, 'plugin_information', $this->info_args() );

		$this->assertArrayNotHasKey( 'changelog', $info->sections );
	}

	public function test_hostile_release_strings_never_reach_the_output(): void {
		$data = $this->release(
			array(
				'changelog_url' => 'https://github.com/x"><script>alert(1)</script>',
				'requires'      => '<img src=x onerror=alert(1)>',
				'tested'        => '"><script>',
			)
		);
		$info = $this->checker( $data )->plugin_info( false, 'plugin_information', $this->info_args() );

		$this->assertStringNotContainsString( '<script', serialize( $info ) );
		$this->assertStringNotContainsString( 'onerror', serialize( $info ) );
	}

	public function test_plugin_info_does_not_hand_out_a_download_link_for_a_release_that_is_not_an_update(): void {
		$info = $this->checker( $this->release( array( 'version' => '1.0.0' ) ) )->plugin_info( false, 'plugin_information', $this->info_args() );

		$this->assertSame( '1.0.0', $info->version );
		$this->assertFalse( property_exists( $info, 'download_link' ) );
	}

	public function test_plugin_info_passes_other_slugs_through_untouched_without_http(): void {
		$this->client->shouldReceive( 'plugin_update' )->never();
		$checker  = new Update_Checker( $this->client, '1.0.0' );
		$previous = (object) array( 'slug' => 'akismet' );

		$this->assertSame( $previous, $checker->plugin_info( $previous, 'plugin_information', $this->info_args( 'akismet' ) ) );
		$this->assertFalse( $checker->plugin_info( false, 'plugin_information', $this->info_args( 'odavent-events-pro' ) ) );
	}

	public function test_plugin_info_passes_other_actions_through(): void {
		$this->client->shouldReceive( 'plugin_update' )->never();
		$checker = new Update_Checker( $this->client, '1.0.0' );

		$this->assertFalse( $checker->plugin_info( false, 'query_plugins', $this->info_args() ) );
		$this->assertSame( 'x', $checker->plugin_info( 'x', 'hot_tags', $this->info_args() ) );
	}

	public function test_plugin_info_survives_odd_arguments(): void {
		$this->client->shouldReceive( 'plugin_update' )->never();
		$checker = new Update_Checker( $this->client, '1.0.0' );

		$this->assertFalse( $checker->plugin_info( false, 'plugin_information', null ) );
		$this->assertFalse( $checker->plugin_info( false, 'plugin_information', array( 'slug' => 'odavent-events' ) ) );
		$this->assertFalse( $checker->plugin_info( false, 'plugin_information', (object) array() ) );
		$this->assertFalse( $checker->plugin_info( false, 'plugin_information', (object) array( 'slug' => array( 'odavent-events' ) ) ) );
	}

	public function test_plugin_info_returns_the_original_result_when_there_is_no_valid_release(): void {
		$previous = (object) array( 'slug' => 'something' );

		$out = $this->checker( null )->plugin_info( $previous, 'plugin_information', $this->info_args() );

		$this->assertSame( $previous, $out );
	}

	public function test_plugin_info_refuses_a_release_with_a_foreign_package(): void {
		$data = $this->release( array( 'download_url' => 'https://evil.example.com/x.zip' ) );

		$this->assertFalse( $this->checker( $data )->plugin_info( false, 'plugin_information', $this->info_args() ) );
	}

	/**
	 * What is cached under the update transient: false for a cached failure, the release array otherwise.
	 * The failure sentinel is an array; this reports it as false for readability.
	 *
	 * @return array|false
	 */
	private function cached_release() {
		$value = $this->transients['odavent_events_update'] ?? null;
		if ( ! is_array( $value ) || ! empty( $value['failed'] ) ) {
			return false;
		}

		return $value;
	}
}

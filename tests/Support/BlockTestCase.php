<?php
namespace Odavent\Events\Tests\Support;

use Brain\Monkey\Functions;
use Mockery;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Data\Event_View;
use Odavent\Events\Settings\Options;

/**
 * Base for block tests: payloads per lifecycle state, a registry over a mocked repository, and the
 * WordPress functions blocks call (capabilities, permalink, style enqueueing).
 */
abstract class BlockTestCase extends RenderTestCase {

	const PERMALINK = 'https://blog.example.com/event-page/';

	/** @var bool What current_user_can( 'edit_posts' ) answers. */
	protected $editor = false;

	/** @var string[] Handles passed to wp_enqueue_style. */
	protected $enqueued_styles = array();

	/** @var array[] Every Event_Repository::get() call: [id, slug, preview_allowed]. */
	protected $repo_calls = array();

	protected function setUp(): void {
		parent::setUp();
		$this->editor          = false;
		$this->enqueued_styles = array();
		$this->repo_calls      = array();

		Functions\when( 'current_user_can' )->alias(
			function ( $capability ) {
				return 'edit_posts' === $capability && $this->editor;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( self::PERMALINK );
		Functions\when( 'wp_style_is' )->justReturn( true );
		Functions\when( 'wp_register_style' )->justReturn( true );
		Functions\when( 'wp_enqueue_style' )->alias(
			function ( $handle ) {
				$this->enqueued_styles[] = $handle;
			}
		);
	}

	/**
	 * A fixture payload with event fields overridden.
	 *
	 * @param array  $event   Fields merged over `event`.
	 * @param string $fixture Fixture name.
	 */
	protected function payload( array $event = array(), string $fixture = 'payload-v1-full' ): array {
		$payload          = $this->fixture( $fixture );
		$payload['event'] = array_merge( $payload['event'], $event );

		return $payload;
	}

	/**
	 * Event fields producing a lifecycle state. Every non-live state keeps registration_open TRUE,
	 * so the tests prove the gate is the state, not the flag.
	 *
	 * @return array<string, array> state label => event overrides
	 */
	protected static function state_overrides(): array {
		return array(
			'live'               => array( 'status' => 'published', 'ended' => false, 'registration_open' => true ),
			'live closed'        => array( 'status' => 'published', 'ended' => false, 'registration_open' => false ),
			'ended open'         => array( 'status' => 'published', 'ended' => true, 'registration_open' => true ),
			'ended closed'       => array( 'status' => 'published', 'ended' => true, 'registration_open' => false ),
			'paused'             => array( 'status' => 'paused', 'registration_open' => true ),
			'cancelled'          => array( 'status' => 'cancelled', 'registration_open' => true ),
			'archived'           => array( 'status' => 'archived', 'ended' => true, 'registration_open' => true ),
			'draft'              => array( 'status' => 'draft', 'registration_open' => true ),
		);
	}

	/**
	 * A repository double: get() answers like the real one for a payload (a draft only for a preview).
	 *
	 * @param array|null  $payload    Payload, or null for an empty view of $empty_state.
	 * @param string      $empty_state State of the empty view.
	 * @param array       $slugs      slug => id for resolve_id().
	 */
	protected function repository( ?array $payload, string $empty_state = Event_View::GONE, array $slugs = array() ): Event_Repository {
		$repo = Mockery::mock( Event_Repository::class );
		$repo->shouldReceive( 'get' )->andReturnUsing(
			function ( $id, $slug = '', $preview = false ) use ( $payload, $empty_state ) {
				$this->repo_calls[] = array( $id, $slug, $preview );
				if ( null === $payload ) {
					return Event_View::empty( $empty_state );
				}

				return Event_View::from_payload( $payload, $preview );
			}
		);
		$repo->shouldReceive( 'resolve_id' )->andReturnUsing(
			function ( $slug ) use ( $slugs ) {
				return $slugs[ $slug ] ?? null;
			}
		);

		return $repo;
	}

	protected function options( int $default_event = 0, bool $load_css = true ): Options {
		$options = Mockery::mock( Options::class );
		$options->shouldReceive( 'default_event_id' )->andReturn( $default_event );
		$options->shouldReceive( 'load_css' )->andReturn( $load_css );

		return $options;
	}

	protected function registry( ?array $payload, string $empty_state = Event_View::GONE, array $slugs = array(), int $default_event = 0 ): Block_Registry {
		return new Block_Registry( $this->repository( $payload, $empty_state, $slugs ), $this->options( $default_event ) );
	}

	/**
	 * Every href and src value in some HTML.
	 *
	 * @return string[]
	 */
	protected function urls_in( string $html ): array {
		preg_match_all( '/\s(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $html, $m, PREG_SET_ORDER );

		$urls = array();
		foreach ( $m as $match ) {
			$urls[] = html_entity_decode( $match[1] . ( $match[2] ?? '' ) . ( $match[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		return $urls;
	}

	/**
	 * Asserts the HTML carries no script other than JSON-LD, no event handler, and only http(s) URLs.
	 */
	protected function assertSafeHtml( string $html ): void {
		$this->assertSame( substr_count( $html, '<script type="application/ld+json">' ), substr_count( strtolower( $html ), '<script' ), 'Only JSON-LD script elements may appear.' );
		$this->assertDoesNotMatchRegularExpression( '/<[^>]*\son[a-z]+\s*=/i', $html, 'No event handler attribute.' );
		$this->assertStringNotContainsString( '<svg', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<iframe', $html );
		foreach ( $this->urls_in( $html ) as $url ) {
			$this->assertMatchesRegularExpression( '#^https?://#i', $url, 'Only http(s) URLs in href/src.' );
		}
	}
}

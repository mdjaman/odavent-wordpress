<?php
namespace Odavent\Events;

use Odavent\Events\Api\Client;
use Odavent\Events\Blocks\Block_Registry;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Refresh\Page_Cache_Purger;
use Odavent\Events\Refresh\Refresh_Controller;
use Odavent\Events\Render\Assets;
use Odavent\Events\Rest\Editor_Controller;
use Odavent\Events\Settings\Options;
use Odavent\Events\Settings\Settings_Page;
use Odavent\Events\Shortcodes\Shortcodes;
use Odavent\Events\Updates\Update_Checker;

/**
 * Plugin entry point. Later tasks register their components in boot().
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Options|null */
	private $options = null;

	/** @var Client|null */
	private $client = null;

	/** @var Event_Repository|null */
	private $repository = null;

	/** @var Page_Cache_Purger|null */
	private $purger = null;

	/** @var Assets|null */
	private $assets = null;

	/** @var Block_Registry|null */
	private $blocks = null;

	/** @var Shortcodes|null */
	private $shortcodes = null;

	/** @var Update_Checker|null */
	private $updates = null;

	private function __construct() {
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( is_admin() ) {
			( new Settings_Page( $this->options(), $this->client(), $this->repository() ) )->register();
		}

		// Before the blocks register (priority 10): block.json titles and descriptions are translated
		// while the block types are registered, so the text domain must be loaded by then.
		add_action(
			'init',
			function () {
				load_plugin_textdomain( 'odavent-events', false, dirname( plugin_basename( ODAVENT_EVENTS_FILE ) ) . '/languages' );
			},
			1
		);

		add_action(
			'init',
			function () {
				// The stylesheet handle first: block.json names it as editorStyle.
				$this->assets()->register_styles();
				$this->blocks()->register();
				$this->blocks()->register_patterns();
			}
		);

		// After the blocks (priority 10), so the block.json files are the source for both.
		add_action(
			'init',
			function () {
				$this->shortcodes()->register();
			},
			11
		);

		add_action(
			'enqueue_block_editor_assets',
			function () {
				$this->blocks()->add_editor_metadata();
			}
		);

		add_filter(
			'block_categories_all',
			function ( $categories ) {
				return $this->blocks()->add_category( $categories );
			}
		);

		// Update checks only where WordPress runs them: wp-admin, cron and WP-CLI, never a front-end
		// request. Registering is free; nothing is fetched until the update transient is built.
		if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$this->updates()->register();
		}

		add_action(
			'rest_api_init',
			function () {
				( new Refresh_Controller( $this->options(), $this->repository(), $this->purger() ) )->register_routes();
				( new Editor_Controller( $this->repository() ) )->register_routes();
			}
		);

		add_action(
			'template_redirect',
			function () {
				$this->blocks()->prevent_preview_caching();
			}
		);

		add_action(
			'wp_enqueue_scripts',
			function () {
				$this->blocks()->enqueue_early_styles();
			}
		);
	}

	public function options(): Options {
		if ( null === $this->options ) {
			$this->options = new Options();
		}

		return $this->options;
	}

	public function client(): Client {
		if ( null === $this->client ) {
			$this->client = new Client( $this->options() );
		}

		return $this->client;
	}

	public function repository(): Event_Repository {
		if ( null === $this->repository ) {
			$this->repository = new Event_Repository( $this->client(), $this->options() );
		}

		return $this->repository;
	}

	public function purger(): Page_Cache_Purger {
		if ( null === $this->purger ) {
			$this->purger = new Page_Cache_Purger();
		}

		return $this->purger;
	}

	public function assets(): Assets {
		if ( null === $this->assets ) {
			$this->assets = new Assets( $this->options() );
		}

		return $this->assets;
	}

	public function blocks(): Block_Registry {
		if ( null === $this->blocks ) {
			$this->blocks = new Block_Registry( $this->repository(), $this->options(), $this->assets() );
		}

		return $this->blocks;
	}

	public function shortcodes(): Shortcodes {
		if ( null === $this->shortcodes ) {
			$this->shortcodes = new Shortcodes( $this->blocks() );
		}

		return $this->shortcodes;
	}

	public function updates(): Update_Checker {
		if ( null === $this->updates ) {
			$this->updates = new Update_Checker( $this->client() );
		}

		return $this->updates;
	}

	public function version(): string {
		return ODAVENT_EVENTS_VERSION;
	}
}

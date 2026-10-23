<?php
namespace Odavent\Events;

use Odavent\Events\Api\Client;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Settings\Options;
use Odavent\Events\Settings\Settings_Page;

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

	public function version(): string {
		return ODAVENT_EVENTS_VERSION;
	}
}

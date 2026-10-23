<?php
namespace Odavent\Events;

use Odavent\Events\Settings\Options;

/**
 * Plugin entry point. Later tasks register their components in boot().
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Options|null */
	private $options = null;

	private function __construct() {
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
	}

	public function options(): Options {
		if ( null === $this->options ) {
			$this->options = new Options();
		}

		return $this->options;
	}

	public function version(): string {
		return ODAVENT_EVENTS_VERSION;
	}
}

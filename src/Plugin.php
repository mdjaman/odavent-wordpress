<?php
namespace Odavent\Events;

/**
 * Plugin entry point. Later tasks register their components in boot().
 */
final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

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

	public function version(): string {
		return ODAVENT_EVENTS_VERSION;
	}
}

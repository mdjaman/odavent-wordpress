<?php
namespace Odavent\Events\Settings;

/**
 * Plugin settings stored in a single non-autoloaded option, plus the connection-disabled flag.
 *
 * The site key is never printed back: only masked_key() is meant for display.
 */
class Options {

	const OPTION              = 'odavent_events_settings';
	const KEY_PREFIX          = 'odv_wp_';
	const CONN_DISABLED_FLAG  = 'odavent_events_conn_disabled';
	const CONN_DISABLED_TTL   = 3600;
	const MIN_CACHE_TTL       = 60;
	const MAX_CACHE_TTL       = 86400;
	const SETTINGS_ERROR_NAME = 'odavent_events_settings';

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults(): array {
		return array(
			'base_url'         => 'https://odavent.com',
			'site_key'         => '',
			'cache_ttl'        => 900,
			'default_event_id' => 0,
			'load_css'         => true,
		);
	}

	/**
	 * Stored settings merged over the defaults (unknown keys dropped).
	 *
	 * @return array
	 */
	public function get(): array {
		$stored   = get_option( self::OPTION, array() );
		$defaults = self::defaults();

		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		return array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
	}

	/**
	 * Persists the full settings array without autoload.
	 *
	 * @param array $settings Sanitized settings.
	 */
	public function save( array $settings ): void {
		update_option( self::OPTION, $settings, false );
	}

	/**
	 * The active site key: the ODAVENT_SITE_KEY constant wins over the stored key.
	 */
	public function site_key(): string {
		$constant = $this->constant_key();
		if ( '' !== $constant ) {
			return $constant;
		}

		return (string) $this->get()['site_key'];
	}

	public function has_key(): bool {
		return '' !== $this->site_key();
	}

	/**
	 * Display-safe form of the key: prefix, bullets and the last four characters.
	 */
	public function masked_key(): string {
		$key = $this->site_key();
		if ( '' === $key ) {
			return '';
		}

		$tail = function_exists( 'mb_substr' ) ? mb_substr( $key, -4 ) : substr( $key, -4 );

		return self::KEY_PREFIX . '••••' . $tail;
	}

	public function base_url(): string {
		return rtrim( (string) $this->get()['base_url'], '/' );
	}

	public function cache_ttl(): int {
		return (int) $this->get()['cache_ttl'];
	}

	public function default_event_id(): int {
		return (int) $this->get()['default_event_id'];
	}

	public function load_css(): bool {
		return (bool) $this->get()['load_css'];
	}

	/**
	 * Validates submitted settings and returns the full sanitized array.
	 *
	 * Invalid base URLs and keys are rejected (the current value is kept) with a settings error.
	 *
	 * @param array $input   Raw submitted values.
	 * @param array $current Currently stored settings.
	 * @return array
	 */
	public function sanitize( array $input, array $current ): array {
		$current = array_merge( self::defaults(), array_intersect_key( $current, self::defaults() ) );
		$out     = $current;

		if ( array_key_exists( 'base_url', $input ) ) {
			$url = $this->normalize_base_url( $input['base_url'] );
			if ( null === $url ) {
				$this->add_error( 'odavent_events_base_url', __( 'The Odavent address must start with https:// (http://localhost is accepted on local development sites only).', 'odavent-events' ) );
			} else {
				$out['base_url'] = $url;
			}
		}

		if ( array_key_exists( 'site_key', $input ) ) {
			$key = is_string( $input['site_key'] ) ? trim( $input['site_key'] ) : '';
			if ( '' !== $key ) {
				if ( $this->is_valid_key( $key ) ) {
					$out['site_key'] = $key;
				} else {
					$this->add_error( 'odavent_events_site_key', __( 'That does not look like an Odavent site key. It starts with odv_wp_ and contains no spaces.', 'odavent-events' ) );
				}
			}
		}

		if ( array_key_exists( 'cache_ttl', $input ) && is_numeric( $input['cache_ttl'] ) ) {
			$out['cache_ttl'] = max( self::MIN_CACHE_TTL, min( self::MAX_CACHE_TTL, (int) $input['cache_ttl'] ) );
		}

		if ( array_key_exists( 'default_event_id', $input ) && is_numeric( $input['default_event_id'] ) ) {
			$out['default_event_id'] = max( 0, (int) $input['default_event_id'] );
		}

		// Checkbox: absent means unchecked.
		$out['load_css'] = ! empty( $input['load_css'] ) && '0' !== $input['load_css'] && 'false' !== $input['load_css'];

		return $out;
	}

	/**
	 * Whether a previous request found the site key rejected, so calls are paused for a while.
	 */
	public function is_connection_disabled(): bool {
		return (bool) get_transient( self::CONN_DISABLED_FLAG );
	}

	public function set_connection_disabled( bool $disabled ): void {
		if ( $disabled ) {
			set_transient( self::CONN_DISABLED_FLAG, 1, self::CONN_DISABLED_TTL );
			return;
		}

		delete_transient( self::CONN_DISABLED_FLAG );
	}

	/**
	 * Seam for tests: the value of the ODAVENT_SITE_KEY constant, or '' when it is not defined.
	 */
	protected function constant_key(): string {
		if ( ! defined( 'ODAVENT_SITE_KEY' ) ) {
			return '';
		}

		$value = constant( 'ODAVENT_SITE_KEY' );

		return is_string( $value ) ? trim( $value ) : '';
	}

	private function is_valid_key( string $key ): bool {
		return 0 === strpos( $key, self::KEY_PREFIX )
			&& strlen( $key ) > strlen( self::KEY_PREFIX )
			&& 1 !== preg_match( '/\s/', $key );
	}

	/**
	 * Returns the normalized base URL (no trailing slash), or null when it is not acceptable.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string|null
	 */
	private function normalize_base_url( $raw ): ?string {
		if ( ! is_string( $raw ) ) {
			return null;
		}

		$raw = trim( $raw );
		if ( '' === $raw || 1 === preg_match( '/\s/', $raw ) ) {
			return null;
		}

		$parts = parse_url( $raw );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		$path   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';

		if ( 1 !== preg_match( '/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/', $host ) || 1 !== preg_match( '/^[A-Za-z0-9\/._~%-]*$/', $path ) ) {
			return null;
		}

		if ( 'https' !== $scheme && ! ( 'http' === $scheme && $this->is_local_http_allowed( $host ) ) ) {
			return null;
		}

		return $scheme . '://' . $host . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' ) . $path;
	}

	private function is_local_http_allowed( string $host ): bool {
		return in_array( $host, array( 'localhost', '127.0.0.1' ), true )
			&& function_exists( 'wp_get_environment_type' )
			&& 'local' === wp_get_environment_type();
	}

	private function add_error( string $code, string $message ): void {
		if ( function_exists( 'add_settings_error' ) ) {
			add_settings_error( self::SETTINGS_ERROR_NAME, $code, $message, 'error' );
		}
	}
}

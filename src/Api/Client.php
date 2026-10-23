<?php
namespace Odavent\Events\Api;

use Odavent\Events\Settings\Options;

/**
 * Read-only client for the Odavent WordPress API (/api/v1/wordpress).
 *
 * Every method returns a Result and never throws, so a page can never fatal because Odavent is
 * slow or down. The site key travels only in the Authorization header: it is never put in a URL,
 * a log line or a Result message, and redirects are not followed so a redirect cannot forward it.
 */
class Client {

	/** Highest payload contract this plugin knows how to render. A newer one is returned intact. */
	const SUPPORTED_CONTRACT = 1;

	const TIMEOUT        = 8;
	const MAX_BODY_BYTES = 2097152;
	const JSON_DEPTH     = 16;
	const API_PATH       = '/api/v1/wordpress';

	/** @var Options */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * Every event of the key's organization, any status (key route).
	 */
	public function list_events(): Result {
		return $this->key_request( '/site/events' );
	}

	/**
	 * One event of the key's organization, any status (key route).
	 *
	 * @param int         $id     Event id.
	 * @param string|null $locale Content locale such as fr or fr_FR; dropped when malformed.
	 */
	public function site_event( int $id, ?string $locale = null ): Result {
		return $this->key_request( '/site/events/' . (int) $id . $this->locale_query( $locale ) );
	}

	/**
	 * A published event by slug (public route, no key sent).
	 *
	 * @param string      $slug   Event slug.
	 * @param string|null $locale Content locale such as fr or fr_FR; dropped when malformed.
	 */
	public function public_event( string $slug, ?string $locale = null ): Result {
		return $this->request( '/events/' . rawurlencode( $slug ) . $this->locale_query( $locale ), '' );
	}

	/**
	 * Latest plugin release for the self-update check (public route).
	 */
	public function plugin_update(): Result {
		return $this->request( '/plugin-update', '' );
	}

	private function key_request( string $path ): Result {
		if ( ! $this->options->has_key() ) {
			return Result::fail( Result::INVALID_KEY, 0, $this->message( Result::INVALID_KEY ) );
		}

		return $this->request( $path, $this->options->site_key() );
	}

	/**
	 * @param string $path Path below /api/v1/wordpress, with any query string.
	 * @param string $key  Site key for the Authorization header, '' for a public route.
	 */
	private function request( string $path, string $key ): Result {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => 'Odavent-WP/' . ( defined( 'ODAVENT_EVENTS_VERSION' ) ? ODAVENT_EVENTS_VERSION : '0' ),
		);
		if ( '' !== $key ) {
			$headers['Authorization'] = 'Bearer ' . $key;
		}

		$response = wp_remote_get(
			$this->options->base_url() . self::API_PATH . $path,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => self::MAX_BODY_BYTES + 1,
				'headers'             => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->unavailable( 0 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );

		if ( ! is_string( $body ) || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return $this->unavailable( $status );
		}

		$json = json_decode( $body, true, self::JSON_DEPTH );
		if ( ! is_array( $json ) ) {
			return $this->unavailable( $status );
		}

		if ( $status >= 200 && $status < 300 ) {
			if ( true === ( $json['success'] ?? null ) && isset( $json['data'] ) && is_array( $json['data'] ) ) {
				return Result::ok( $json['data'], $status );
			}

			return $this->unavailable( $status );
		}

		// An error is only believed when HTTP status and the body's own code agree. A proxy, WAF or
		// another app answering with a bare 401/403/404 must not be mistaken for Odavent's answer.
		$code     = $json['errors']['code'] ?? null;
		$expected = array(
			401 => Result::INVALID_KEY,
			403 => Result::PLAN_REQUIRED,
			404 => Result::NOT_FOUND,
		);
		if ( isset( $expected[ $status ] ) && is_string( $code ) && $code === $expected[ $status ] ) {
			return Result::fail( $code, $status, $this->message( $code ) );
		}

		return $this->unavailable( $status );
	}

	private function unavailable( int $status ): Result {
		return Result::fail( Result::UNAVAILABLE, $status, $this->message( Result::UNAVAILABLE ) );
	}

	/**
	 * `?locale=xx` for a valid code (fr, fr_FR), otherwise nothing.
	 *
	 * @param string|null $locale Requested locale.
	 */
	private function locale_query( ?string $locale ): string {
		if ( null === $locale || 1 !== preg_match( '/^[a-z]{2}(_[A-Z]{2})?$/D', $locale ) ) {
			return '';
		}

		return '?locale=' . $locale;
	}

	private function message( string $code ): string {
		switch ( $code ) {
			case Result::INVALID_KEY:
				return __( 'Odavent did not accept the site key.', 'odavent-events' );
			case Result::PLAN_REQUIRED:
				return __( 'The Odavent plan for this site does not include the WordPress plugin.', 'odavent-events' );
			case Result::NOT_FOUND:
				return __( 'That event was not found on Odavent.', 'odavent-events' );
			default:
				return __( 'Odavent is temporarily unavailable.', 'odavent-events' );
		}
	}
}

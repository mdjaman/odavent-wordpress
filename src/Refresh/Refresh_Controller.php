<?php
namespace Odavent\Events\Refresh;

use Odavent\Events\Data\Clock;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Settings\Options;

/**
 * POST /wp-json/odavent/v1/refresh: Odavent tells the site that one event changed.
 *
 * The route is public; its only authentication is the X-Odavent-Signature HMAC (see Signature).
 * Verification order, cheapest first, and nothing is written before a valid HMAC:
 *  1. body size (and a declared Content-Length) <= 8192 bytes, else 413 — before any hashing;
 *  2. header present and well formed (Signature::parse);
 *  3. a site key is configured;
 *  4. timestamp within tolerance, HMAC compared with hash_equals (Signature::verify);
 *  5. not a replay: the v1 hash is remembered for 2 x Signature::TOLERANCE + 60 s (11 minutes), longer
 *     than the 2 x 300 s window in which a timestamp is accepted, so a captured ping cannot be replayed
 *     while its timestamp still passes.
 *
 * The event's own cache is forgotten before answering (the next page view is fresh). Purging the page
 * caches of the posts that show Odavent content can be slow on a big site, and Odavent gives a ping 10 s
 * (then retries), so that part runs on `shutdown`, after the 200 has been sent when the server allows it.
 * Every authentication failure gets the same 401 response, so a caller cannot tell a missing key
 * from a wrong, stale or replayed signature. Rejections are not logged.
 */
class Refresh_Controller {

	const NAMESPACE_V1   = 'odavent/v1';
	const ROUTE          = '/refresh';
	const MAX_BODY_BYTES = 8192;
	const SEEN_PREFIX    = 'odavent_events_seen_';
	const SEEN_TTL       = 2 * Signature::TOLERANCE + 60;
	const HEADER         = 'x_odavent_signature';

	/** @var Options */
	private $options;

	/** @var Event_Repository */
	private $repository;

	/** @var Page_Cache_Purger */
	private $purger;

	public function __construct( Options $options, Event_Repository $repository, Page_Cache_Purger $purger ) {
		$this->options    = $options;
		$this->repository = $repository;
		$this->purger     = $purger;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				// Public on purpose: authentication is the HMAC signature checked in handle().
				'permission_callback' => '__return_true',
				// A machine-to-machine endpoint: no reason to list it in the REST index.
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request The ping.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			return $this->process( $request );
		} catch ( \Throwable $e ) {
			return $this->error( 'odavent_refresh_failed', __( 'The refresh could not be completed.', 'odavent-events' ), 500 );
		}
	}

	private function process( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (string) $request->get_body();

		// 1. Size, before anything is hashed.
		if ( strlen( $body ) > self::MAX_BODY_BYTES || $this->declared_length_too_large( $request ) ) {
			return $this->error( 'odavent_payload_too_large', __( 'The request body is too large.', 'odavent-events' ), 413 );
		}

		// 2. Header shape (no hashing).
		$header = $this->signature_header( $request );
		$parsed = Signature::parse( $header );
		if ( null === $parsed ) {
			return $this->unauthorized();
		}

		// 3. A configured key.
		$key = $this->options->site_key();
		if ( '' === $key ) {
			return $this->unauthorized();
		}

		// 4. Freshness and HMAC.
		$now = Clock::now();
		if ( ! Signature::verify( $header, $body, $key, $now ) ) {
			return $this->unauthorized();
		}

		// 5. Replay, only once the signature is known to be Odavent's.
		$seen = self::SEEN_PREFIX . substr( sha1( $parsed['v1'] ), 0, 32 );
		if ( false !== get_transient( $seen ) ) {
			return $this->unauthorized();
		}
		set_transient( $seen, 1, self::SEEN_TTL );

		$data = json_decode( $body, true, 16 );
		if ( ! is_array( $data ) || ! isset( $data['event_id'] ) || ! is_int( $data['event_id'] ) || $data['event_id'] <= 0 ) {
			return $this->error( 'odavent_bad_request', __( 'The request is not a valid refresh.', 'odavent-events' ), 400 );
		}

		$event_id = $data['event_id'];
		$slug     = isset( $data['event_slug'] ) && is_string( $data['event_slug'] ) ? sanitize_title( $data['event_slug'] ) : '';

		$this->repository->purge( $event_id );

		add_action(
			'shutdown',
			function () use ( $event_id, $slug ) {
				$this->finish_refresh( $event_id, $slug );
			}
		);

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * The slow part of a refresh, run on `shutdown`: close the connection when the server can, purge the
	 * page caches, then announce it. Nothing here may change the answer already sent, so nothing escapes.
	 *
	 * @param int    $event_id Odavent event id.
	 * @param string $slug     Event slug (sanitised), or ''.
	 */
	private function finish_refresh( int $event_id, string $slug ): void {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}

		try {
			$this->purger->purge_event( $event_id );

			/**
			 * Fires after a signed refresh ping purged an event's cached data and the pages using it. It runs
			 * on `shutdown`, after the response was sent when the server allows it.
			 *
			 * @param int    $event_id Odavent event id.
			 * @param string $slug     Event slug (sanitised), or '' when not sent.
			 */
			do_action( 'odavent_events_refreshed', $event_id, $slug );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	private function declared_length_too_large( \WP_REST_Request $request ): bool {
		$declared = $request->get_header( 'content_length' );

		// preg_match, not ctype_digit(): ext-ctype is optional. The D modifier refuses a trailing newline.
		return is_string( $declared ) && 1 === preg_match( '/^[0-9]+$/D', $declared ) && ( strlen( $declared ) > 9 || (int) $declared > self::MAX_BODY_BYTES );
	}

	private function signature_header( \WP_REST_Request $request ): string {
		// WP_REST_Request canonicalises header names (lowercase, dashes to underscores), so one lookup is enough.
		$value = $request->get_header( self::HEADER );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The one response for every authentication failure.
	 */
	private function unauthorized(): \WP_REST_Response {
		return $this->error( 'odavent_invalid_signature', __( 'Invalid or missing signature.', 'odavent-events' ), 401 );
	}

	private function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}
}

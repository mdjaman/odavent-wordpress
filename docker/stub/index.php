<?php
/**
 * Stub of the Odavent WordPress API for the dev environment and the smoke/e2e tests.
 *
 * Served by `php -S 127.0.0.1:8081 /stub/index.php` inside the WordPress container's network namespace,
 * so the plugin reaches it at http://127.0.0.1:8081 (an http base URL is legal there because the site's
 * environment type is `local`). It mirrors the real controller (event-app
 * app/Http/Controllers/Api/V1/WordpressController.php and VerifyWordpressSiteKey):
 *
 *   GET /api/v1/wordpress/site/events            Bearer key: every event, any status
 *   GET /api/v1/wordpress/site/events/{id}       Bearer key: one event, any status
 *   GET /api/v1/wordpress/events/{slug}          public: published events only
 *   GET /api/v1/wordpress/plugin-update          public: latest plugin release
 *
 * Envelope {success, message, data}; errors {success:false, message, errors:{code}}.
 *
 * Events (built from tests/fixtures, mounted at /fixtures):
 *   1 published, the full fixture        2 cancelled        3 draft
 *   4 not found                          5 the hostile fixture, published
 *
 * Test controls (never part of the real API):
 *   POST /__control  JSON, merged into the state:
 *     {"reset": true}                                   back to the initial state (log kept)
 *     {"mode": "ok|500|timeout|garbage|plan_required"}  how every API route answers
 *     {"rename": {"1": "New name"}}                     event name (list + payload)
 *     {"status": {"1": "cancelled"}}                    event status
 *     {"starts_in": {"1": 3600}}                        start = now + N seconds (end keeps its duration)
 *     {"event": {"1": {"registration_open": false}}}    any other event field
 *     {"update": {"download_url": "https://..."}}       plugin-update fields
 *     {"reset_log": true}                               empty the request log
 *   GET /__state  the state;  GET /__log  the request log (one JSON object per line)
 *
 * State lives in a JSON file under /tmp so it survives requests (and php -S workers share it).
 */

const STUB_KEY   = 'odv_wp_stubkey_for_dev_0123456789';
const STATE_FILE = '/tmp/odavent-stub-state.json';
const LOG_FILE   = '/tmp/odavent-stub-log.jsonl';
const FIXTURES   = '/fixtures';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = $_SERVER['REQUEST_URI'] ?? '/';
$path   = (string) parse_url( $uri, PHP_URL_PATH );
$query  = array();
parse_str( (string) parse_url( $uri, PHP_URL_QUERY ), $query );

// ---- state ------------------------------------------------------------------------------------

function initial_state(): array {
	return array(
		'mode'      => 'ok',
		'rename'    => array(),
		'status'    => array(),
		'starts_at' => array(),
		'event'     => array(),
		'update'    => array(),
	);
}

function with_state( callable $fn ) {
	$fh = fopen( STATE_FILE, 'c+' );
	flock( $fh, LOCK_EX );
	$raw   = stream_get_contents( $fh );
	$state = json_decode( (string) $raw, true );
	if ( ! is_array( $state ) ) {
		$state = initial_state();
	}
	$result = $fn( $state );
	if ( is_array( $result ) && isset( $result['__write'] ) ) {
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, json_encode( $result['__write'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		fflush( $fh );
		$result = $result['__write'];
	}
	flock( $fh, LOCK_UN );
	fclose( $fh );

	return $result;
}

function state(): array {
	return with_state(
		function ( array $s ) {
			return $s;
		}
	);
}

function log_request( string $method, string $uri, bool $auth ): void {
	$line = json_encode(
		array(
			't'      => microtime( true ),
			'method' => $method,
			'uri'    => $uri,
			'auth'   => $auth,
		),
		JSON_UNESCAPED_SLASHES
	);
	file_put_contents( LOG_FILE, $line . "\n", FILE_APPEND | LOCK_EX );
}

// ---- responses ----------------------------------------------------------------------------------

function send( int $status, $body, array $headers = array() ): void {
	http_response_code( $status );
	header( 'Content-Type: application/json' );
	foreach ( $headers as $name => $value ) {
		header( $name . ': ' . $value );
	}
	echo is_string( $body ) ? $body : json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	exit;
}

function success( $data, array $headers = array() ): void {
	send( 200, array( 'success' => true, 'message' => 'OK', 'data' => $data ), $headers );
}

function error_response( string $message, string $code, int $status, array $headers = array() ): void {
	send( $status, array( 'success' => false, 'message' => $message, 'errors' => array( 'code' => $code ) ), $headers );
}

// ---- events -------------------------------------------------------------------------------------

function fixture( string $name ): array {
	$data = json_decode( (string) file_get_contents( FIXTURES . '/' . $name ), true );

	return is_array( $data ) ? $data : array();
}

/**
 * Every event by id, with the state's overrides applied.
 *
 * @return array<int, array>
 */
function events( array $state ): array {
	$full    = fixture( 'payload-v1-full.json' );
	$hostile = fixture( 'payload-v1-hostile.json' );

	$one                    = $full;
	$one['event']['id']     = 1;
	$one['event']['status'] = 'published';

	$two                             = $full;
	$two['event']['id']              = 2;
	$two['event']['slug']            = 'cancelled-conference-2';
	$two['event']['name']            = 'Cancelled Conference 2026';
	$two['event']['status']          = 'cancelled';
	$two['event']['register_url']    = 'https://odavent.com/events/cancelled-conference-2/register';
	$two['event']['registration_open'] = true; // The plugin must not offer Register for a cancelled event anyway.

	$three                          = $full;
	$three['event']['id']           = 3;
	$three['event']['slug']         = 'secret-draft-workshop-3';
	$three['event']['name']         = 'Secret Draft Workshop 3';
	$three['event']['description']  = 'Unannounced draft description: do not leak.';
	$three['event']['status']       = 'draft';
	$three['event']['register_url'] = 'https://odavent.com/events/secret-draft-workshop-3/register';

	$five                    = $hostile;
	$five['event']['id']     = 5;
	$five['event']['status'] = 'published';

	$events = array( 1 => $one, 2 => $two, 3 => $three, 5 => $five );

	foreach ( $events as $id => &$payload ) {
		$key = (string) $id;
		if ( isset( $state['rename'][ $key ] ) ) {
			$payload['event']['name'] = (string) $state['rename'][ $key ];
		}
		if ( isset( $state['status'][ $key ] ) ) {
			$payload['event']['status'] = (string) $state['status'][ $key ];
		}
		if ( isset( $state['starts_at'][ $key ] ) && is_array( $state['starts_at'][ $key ] ) ) {
			$payload['event']['starts_at'] = $state['starts_at'][ $key ]['starts_at'];
			$payload['event']['ends_at']   = $state['starts_at'][ $key ]['ends_at'];
		}
		if ( isset( $state['event'][ $key ] ) && is_array( $state['event'][ $key ] ) ) {
			$payload['event'] = array_merge( $payload['event'], $state['event'][ $key ] );
		}
	}
	unset( $payload );

	return $events;
}

/**
 * The payload in the requested locale: `fr` appends " (fr)" to the event, session, speaker and ticket names.
 */
function localized( array $payload, ?string $locale ): array {
	$payload['locale'] = 'fr' === $locale ? 'fr' : 'en';
	if ( 'fr' !== $locale ) {
		return $payload;
	}

	$payload['event']['name'] .= ' (fr)';
	foreach ( array( 'sessions', 'speakers', 'tickets' ) as $list ) {
		foreach ( $payload[ $list ] as &$row ) {
			if ( isset( $row['name'] ) && is_string( $row['name'] ) ) {
				$row['name'] .= ' (fr)';
			}
		}
		unset( $row );
	}

	return $payload;
}

function requested_locale( array $query ): ?string {
	$locale = isset( $query['locale'] ) && is_string( $query['locale'] ) ? $query['locale'] : '';

	return 0 === strpos( $locale, 'fr' ) ? 'fr' : ( 0 === strpos( $locale, 'en' ) ? 'en' : null );
}

function list_row( array $payload, ?string $locale ): array {
	$event = $payload['event'];

	return array(
		'id'        => $event['id'],
		'slug'      => $event['slug'],
		'name'      => 'fr' === $locale ? $event['name'] . ' (fr)' : $event['name'],
		'status'    => $event['status'],
		'starts_at' => $event['starts_at'],
		'ends_at'   => $event['ends_at'],
	);
}

// ---- controls -----------------------------------------------------------------------------------

if ( '/__control' === $path && 'POST' === $method ) {
	$input = json_decode( (string) file_get_contents( 'php://input' ), true );
	if ( ! is_array( $input ) ) {
		send( 400, array( 'error' => 'JSON body expected' ) );
	}

	if ( ! empty( $input['reset_log'] ) ) {
		file_put_contents( LOG_FILE, '' );
	}

	$state = with_state(
		function ( array $s ) use ( $input ) {
			if ( ! empty( $input['reset'] ) ) {
				$s = initial_state();
			}
			if ( isset( $input['mode'] ) ) {
				$s['mode'] = (string) $input['mode'];
			}
			foreach ( array( 'rename', 'status', 'event' ) as $field ) {
				if ( isset( $input[ $field ] ) && is_array( $input[ $field ] ) ) {
					foreach ( $input[ $field ] as $id => $value ) {
						if ( null === $value ) {
							unset( $s[ $field ][ (string) $id ] );
						} else {
							$s[ $field ][ (string) $id ] = $value;
						}
					}
				}
			}
			if ( isset( $input['starts_in'] ) && is_array( $input['starts_in'] ) ) {
				foreach ( $input['starts_in'] as $id => $seconds ) {
					if ( null === $seconds ) {
						unset( $s['starts_at'][ (string) $id ] );
						continue;
					}
					$start                             = time() + (int) $seconds;
					$s['starts_at'][ (string) $id ] = array(
						'starts_at' => gmdate( 'Y-m-d\TH:i:s+00:00', $start ),
						'ends_at'   => gmdate( 'Y-m-d\TH:i:s+00:00', $start + 2 * 3600 ),
					);
				}
			}
			if ( isset( $input['update'] ) && is_array( $input['update'] ) ) {
				$s['update'] = array_merge( $s['update'], $input['update'] );
			}

			return array( '__write' => $s );
		}
	);

	send( 200, $state );
}

if ( '/__state' === $path ) {
	send( 200, state() );
}

if ( '/__log' === $path ) {
	header( 'Content-Type: text/plain' );
	echo is_readable( LOG_FILE ) ? file_get_contents( LOG_FILE ) : '';
	exit;
}

// ---- API ----------------------------------------------------------------------------------------

$prefix = '/api/v1/wordpress';
if ( 0 !== strpos( $path, $prefix . '/' ) ) {
	send( 404, array( 'message' => 'Not Found' ) ); // Like a Laravel route miss: no contract code.
}

$auth_header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
log_request( $method, $uri, '' !== $auth_header );

$state  = state();
$mode   = $state['mode'];
$route  = substr( $path, strlen( $prefix ) );
$locale = requested_locale( $query );

if ( 'timeout' === $mode ) {
	sleep( 12 );
}
if ( '500' === $mode ) {
	send( 500, array( 'message' => 'Server Error' ) );
}
if ( 'garbage' === $mode ) {
	header( 'Content-Type: text/html' );
	http_response_code( 200 );
	echo '<html><body>Bad gateway, but with a 200</body></html>';
	exit;
}

if ( 'GET' !== $method ) {
	send( 405, array( 'message' => 'Method Not Allowed' ) );
}

$no_store = array( 'Cache-Control' => 'private, no-store' );
$events   = events( $state );

if ( '/plugin-update' === $route ) {
	success(
		array_merge(
			array(
				'version'       => '9.9.9',
				'download_url'  => 'https://github.com/mdjaman/odavent-wordpress/releases/download/v9.9.9/odavent-events-9.9.9.zip',
				'requires'      => '6.1',
				'requires_php'  => '7.4',
				'tested'        => '7.1',
				'changelog_url' => 'https://github.com/mdjaman/odavent-wordpress/releases/tag/v9.9.9',
			),
			$state['update']
		)
	);
}

if ( preg_match( '#^/events/([^/]+)$#', $route, $m ) ) {
	$slug = rawurldecode( $m[1] );
	foreach ( $events as $payload ) {
		if ( $payload['event']['slug'] === $slug && 'published' === $payload['event']['status'] ) {
			success( localized( $payload, $locale ) );
		}
	}
	error_response( 'Event not found.', 'not_found', 404 );
}

if ( 0 === strpos( $route, '/site/' ) ) {
	$bearer = 0 === strpos( $auth_header, 'Bearer ' ) ? substr( $auth_header, 7 ) : '';
	if ( ! hash_equals( STUB_KEY, $bearer ) ) {
		error_response( 'Invalid or missing site key.', 'invalid_key', 401, $no_store );
	}
	if ( 'plan_required' === $mode ) {
		error_response( 'Your plan does not include the WordPress plugin.', 'plan_required', 403, $no_store );
	}

	if ( '/site/events' === $route ) {
		$rows = array();
		foreach ( $events as $payload ) {
			$rows[] = list_row( $payload, $locale );
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( (string) $b['starts_at'], (string) $a['starts_at'] ) ?: $b['id'] <=> $a['id'];
			}
		);
		success( $rows, $no_store );
	}

	if ( preg_match( '#^/site/events/([^/]+)$#', $route, $m ) ) {
		$id = $m[1];
		if ( ctype_digit( $id ) && strlen( $id ) <= 18 && isset( $events[ (int) $id ] ) ) {
			success( localized( $events[ (int) $id ], $locale ), $no_store );
		}
		error_response( 'Event not found.', 'not_found', 404, $no_store );
	}
}

send( 404, array( 'message' => 'Not Found' ) );

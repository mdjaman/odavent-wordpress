<?php
namespace Odavent\Events\Data;

use Odavent\Events\Api\Client;
use Odavent\Events\Api\Result;
use Odavent\Events\Settings\Options;

/**
 * Reads events from Odavent with caching, failure handling and draft privacy.
 *
 * Storage (the locale part {loc} is Locale::current() or `default`):
 * - `odavent_events_ev_{id}_{loc}` transient, TTL = cache_ttl: a published/paused/cancelled/archived
 *   payload, or a content-free marker (`['__state' => 'draft'|'gone'|'unavailable']`: a single key, so a
 *   payload that happens to carry `__state` is never mistaken for one; 'unavailable' = a status this
 *   plugin does not know, kept for at most 5 minutes so every page view does not call Odavent again).
 * - `odavent_events_ev_{id}_{loc}_preview` transient, 60 s: a draft payload, read only by editors.
 * - `odavent_events_lastgood_{id}_{loc}` option, not autoloaded: the last good non-draft payload,
 *   served as stale when Odavent fails.
 * - `odavent_events_list` transient, 5 min: the key's events list (editor dropdown, slug -> id).
 * - `odavent_events_lastgood_slugs` / `odavent_events_lastgood_index` options, not autoloaded:
 *   last known slug -> id of non-draft events, and the ids cached so purge_all() needs no LIKE
 *   query. The `lastgood_` prefix keeps them inside the uninstaller's cleanup pattern.
 * - `odavent_events_backoff` transient, 60 s: circuit breaker. After an Odavent failure, page
 *   views skip HTTP (stale or UNAVAILABLE) instead of each waiting for the 8 s timeout.
 * - `odavent_events_invalid_key_notice` transient, 12 h: the settings page shows a notice.
 * - `odavent_events_contract_notice` transient, 12 h: a payload declared a newer contract than
 *   Client::SUPPORTED_CONTRACT; the payload is still used and the settings page asks for an update.
 *
 * Nothing about a draft is written outside the preview transient, and only for an editor.
 */
class Event_Repository {

	const PREFIX             = 'odavent_events_ev_';
	const LASTGOOD_PREFIX    = 'odavent_events_lastgood_';
	const PREVIEW_SUFFIX     = '_preview';
	const LIST_TRANSIENT     = 'odavent_events_list';
	const SLUGS_OPTION       = 'odavent_events_lastgood_slugs';
	const INDEX_OPTION       = 'odavent_events_lastgood_index';
	const BACKOFF            = 'odavent_events_backoff';
	const INVALID_KEY_NOTICE = 'odavent_events_invalid_key_notice';
	const CONTRACT_NOTICE    = 'odavent_events_contract_notice';

	const PREVIEW_TTL     = 60;
	const LIST_TTL        = 300;
	const GONE_MAX_TTL    = 300;
	const BACKOFF_TTL     = 60;
	const NOTICE_TTL      = 43200;
	const MARKER          = '__state';
	const MARKER_DRAFT    = 'draft';
	const MARKER_GONE     = 'gone';
	const MARKER_UNKNOWN  = 'unavailable';
	const MARKERS         = array( self::MARKER_DRAFT, self::MARKER_GONE, self::MARKER_UNKNOWN );

	/** @var Client */
	private $client;

	/** @var Options */
	private $options;

	/** @var array<string, Event_View> Per-request memo keyed "{id}|{loc}|{preview}". */
	private $memo = array();

	/**
	 * @var array<string, array> Per-request memo of the last DRAFT payload fetched, keyed "{id}|{loc}", so a
	 *      visitor read followed by an editor read of the same draft makes one HTTP call. Never persisted and
	 *      never turned into a visitor view (only an editor read may use it).
	 */
	private $raw_drafts = array();

	public function __construct( Client $client, Options $options ) {
		$this->client  = $client;
		$this->options = $options;
	}

	/**
	 * The view of one event for this request. Never throws.
	 *
	 * @param int    $event_id        Odavent event id.
	 * @param string $slug_hint       Event slug, used for the public read when there is no working key.
	 * @param bool   $preview_allowed Whether the reader may see a draft (an editor).
	 */
	public function get( int $event_id, string $slug_hint = '', bool $preview_allowed = false ): Event_View {
		try {
			$locale   = Locale::current();
			$loc      = null === $locale ? Locale::DEFAULT_KEY : $locale;
			$memo_key = $event_id . '|' . $loc . '|' . ( $preview_allowed ? '1' : '0' );

			if ( ! isset( $this->memo[ $memo_key ] ) ) {
				$this->memo[ $memo_key ] = $this->load( $event_id, $slug_hint, $preview_allowed, $locale, $loc );
			}
			$view = $this->memo[ $memo_key ];

			if ( $view->is_preview() ) {
				// On every return, memoised or not: each caller may be a separate render.
				$this->mark_uncacheable();
			}

			return $view;
		} catch ( \Throwable $e ) {
			// Still prefer the last good copy to nothing, but never let this fallback throw either.
			try {
				return $this->fallback( $event_id, Locale::key_part(), Event_View::UNAVAILABLE );
			} catch ( \Throwable $inner ) {
				return Event_View::empty( Event_View::UNAVAILABLE );
			}
		}
	}

	/**
	 * Whether Odavent failed recently, so page views skip HTTP (the circuit breaker is set).
	 */
	public function is_backing_off(): bool {
		return $this->breaker_active();
	}

	/**
	 * Event id for a slug, or null. Uses the key's cached events list, or a public read by slug
	 * (which also caches the event, so the following get() makes no second call).
	 *
	 * @param string $slug Event slug.
	 */
	public function resolve_id( string $slug ): ?int {
		try {
			$slug = trim( $slug );
			if ( '' === $slug ) {
				return null;
			}

			if ( $this->key_routes_usable() ) {
				return $this->resolve_from_list( $slug );
			}

			return $this->resolve_public( $slug );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * The key's events list for the block editor, cached for 5 minutes. Not subject to the
	 * breaker, and a failure is returned as is so the editor sees the real error.
	 */
	public function list_for_editors(): Result {
		$cached = get_transient( self::LIST_TRANSIENT );
		if ( is_array( $cached ) ) {
			return Result::ok( $cached );
		}

		$result = $this->client->list_events();
		if ( $result->ok ) {
			$this->store_list( $result->data );
			// A live answer proves the key and plan work again (an upgraded plan re-enables the key routes).
			$this->options->set_connection_disabled( false );
			delete_transient( self::BACKOFF );
		} elseif ( Result::PLAN_REQUIRED === $result->code ) {
			$this->options->set_connection_disabled( true );
		} elseif ( Result::INVALID_KEY === $result->code && $this->options->has_key() ) {
			set_transient( self::INVALID_KEY_NOTICE, 1, self::NOTICE_TTL );
		}

		return $result;
	}

	/**
	 * A signed refresh ping said the event changed: the next page view must ask Odavent again, but if that
	 * fetch fails the last good copy is still served (as stale). So this drops the public and preview
	 * transients (including markers) in every locale, the events list and this request's memo, and keeps the
	 * last-good copies and the slug map. Odavent just proved it is reachable, so the breaker is lifted too.
	 *
	 * @param int $event_id Odavent event id.
	 */
	public function purge_for_refresh( int $event_id ): void {
		$this->purge_event_caches( $event_id, false );
		delete_transient( self::LIST_TRANSIENT );
		delete_transient( self::BACKOFF );
	}

	/**
	 * Forgets one event entirely: public and preview transients (including markers) and last-good copies
	 * in every locale, its slug mapping, the events list and this request's memo. For an event Odavent says
	 * no longer exists for this key; a plain change is purge_for_refresh().
	 *
	 * @param int $event_id Odavent event id.
	 */
	public function forget( int $event_id ): void {
		$this->purge_event_caches( $event_id, true );
		delete_transient( self::LIST_TRANSIENT );

		$slugs = $this->slug_map();
		$kept  = array_filter(
			$slugs,
			static function ( $id ) use ( $event_id ) {
				return $id !== $event_id;
			}
		);
		if ( count( $kept ) !== count( $slugs ) ) {
			update_option( self::SLUGS_OPTION, $kept, false );
		}

		$index = $this->index();
		if ( in_array( $event_id, $index, true ) ) {
			update_option( self::INDEX_OPTION, array_values( array_diff( $index, array( $event_id ) ) ), false );
		}
	}

	/**
	 * Forgets everything this plugin cached, plus the breaker and the key/contract notices.
	 *
	 * The tracked index is the fast path; a database sweep then catches entries an index that lost an update
	 * (two requests rewriting it at once) no longer lists.
	 */
	public function purge_all(): void {
		foreach ( $this->index() as $event_id ) {
			$this->purge_event_caches( $event_id, true );
		}
		$this->purge_untracked();

		delete_transient( self::LIST_TRANSIENT );
		delete_transient( self::BACKOFF );
		delete_transient( self::INVALID_KEY_NOTICE );
		delete_transient( self::CONTRACT_NOTICE );
		delete_option( self::SLUGS_OPTION );
		delete_option( self::INDEX_OPTION );
		$this->memo       = array();
		$this->raw_drafts = array();
	}

	/**
	 * Per-event cache entries in every locale, plus this request's memos. The slug map and the index are the
	 * caller's business (forget() edits them, purge_all() deletes them), so a bulk purge rewrites nothing per id.
	 *
	 * @param int  $event_id       Odavent event id.
	 * @param bool $with_last_good Whether the last-good copies go too.
	 */
	private function purge_event_caches( int $event_id, bool $with_last_good ): void {
		foreach ( Locale::all_key_parts() as $loc ) {
			delete_transient( $this->public_key( $event_id, $loc ) );
			delete_transient( $this->preview_key( $event_id, $loc ) );
			if ( $with_last_good ) {
				delete_option( $this->lastgood_key( $event_id, $loc ) );
			}
		}

		foreach ( array_keys( $this->memo ) as $memo_key ) {
			if ( 0 === strpos( $memo_key, $event_id . '|' ) ) {
				unset( $this->memo[ $memo_key ] );
			}
		}
		foreach ( array_keys( $this->raw_drafts ) as $raw_key ) {
			if ( 0 === strpos( $raw_key, $event_id . '|' ) ) {
				unset( $this->raw_drafts[ $raw_key ] );
			}
		}
	}

	/**
	 * Removes last-good copies and event transients found in the options table, whether or not the index
	 * knows them. Names are looked up with a prepared, escaped LIKE and removed through the options API, so an
	 * object cache is kept in step. A site without a usable $wpdb (or a failed query) skips the sweep.
	 */
	private function purge_untracked(): void {
		global $wpdb;

		if ( ! is_object( $wpdb ) || ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'esc_like' ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return;
		}

		$sql   = "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s";
		$names = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the table name is not user input; values are placeholders.
			$wpdb->prepare(
				$sql,
				$wpdb->esc_like( self::LASTGOOD_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_' . self::PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . self::PREFIX ) . '%'
			)
		);
		if ( ! is_array( $names ) ) {
			return;
		}

		foreach ( $names as $name ) {
			if ( ! is_string( $name ) ) {
				continue;
			}
			if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				delete_option( $name );
			} elseif ( 0 === strpos( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}
	}

	// ---- loading -------------------------------------------------------------------

	private function load( int $id, string $slug_hint, bool $preview_allowed, ?string $locale, string $loc ): Event_View {
		if ( $id <= 0 ) {
			return Event_View::empty( Event_View::GONE );
		}

		$cached = get_transient( $this->public_key( $id, $loc ) );
		if ( is_array( $cached ) ) {
			$marker = $this->marker_of( $cached );
			if ( self::MARKER_GONE === $marker ) {
				return $this->fallback( $id, $loc, Event_View::GONE );
			}
			if ( self::MARKER_UNKNOWN === $marker ) {
				return Event_View::empty( Event_View::UNAVAILABLE );
			}
			if ( self::MARKER_DRAFT === $marker ) {
				if ( ! $preview_allowed ) {
					return Event_View::empty( Event_View::DRAFT );
				}
			} elseif ( $this->is_payload_for( $cached, $id ) && Event_View::DRAFT !== Event_View::state_of( $cached ) ) {
				return Event_View::from_payload( $cached );
			}
		}

		if ( $preview_allowed ) {
			$preview = get_transient( $this->preview_key( $id, $loc ) );
			if ( is_array( $preview ) && $this->is_payload_for( $preview, $id ) ) {
				return Event_View::from_payload( $preview, true );
			}
		}

		// The same draft was already fetched by a visitor read in this request: reuse it for the editor.
		if ( $preview_allowed && isset( $this->raw_drafts[ $id . '|' . $loc ] ) ) {
			return $this->accept( $this->raw_drafts[ $id . '|' . $loc ], $id, true, $loc );
		}

		if ( $this->breaker_active() ) {
			return $this->fallback( $id, $loc, Event_View::UNAVAILABLE );
		}

		if ( $this->key_routes_usable() ) {
			return $this->from_key_result( $this->client->site_event( $id, $locale ), $id, $slug_hint, $preview_allowed, $locale, $loc );
		}

		return $this->public_read( $id, $slug_hint, $preview_allowed, $locale, $loc );
	}

	private function from_key_result( Result $result, int $id, string $slug_hint, bool $preview_allowed, ?string $locale, string $loc ): Event_View {
		if ( $result->ok ) {
			if ( ! $this->is_payload_for( $result->data, $id ) ) {
				return $this->failed( $id, $loc );
			}

			return $this->accept( $result->data, $id, $preview_allowed, $loc );
		}

		switch ( $result->code ) {
			case Result::PLAN_REQUIRED:
				$this->options->set_connection_disabled( true );
				return $this->public_read( $id, $slug_hint, $preview_allowed, $locale, $loc );

			case Result::NOT_FOUND:
				$this->forget( $id );
				$this->set_marker( $id, $loc, self::MARKER_GONE, min( $this->ttl(), self::GONE_MAX_TTL ) );
				return Event_View::empty( Event_View::GONE );

			case Result::INVALID_KEY:
				set_transient( self::INVALID_KEY_NOTICE, 1, self::NOTICE_TTL );
				return $this->failed( $id, $loc );

			default:
				return $this->failed( $id, $loc );
		}
	}

	/**
	 * Public read by slug (no key, or the key's plan no longer covers the plugin). A 404 here is
	 * ambiguous (unpublished, or the slug changed), so the last good copy is kept and served.
	 */
	private function public_read( int $id, string $slug_hint, bool $preview_allowed, ?string $locale, string $loc ): Event_View {
		$slug_hint = trim( $slug_hint );
		if ( '' === $slug_hint ) {
			return $this->fallback( $id, $loc, Event_View::UNAVAILABLE );
		}

		$result = $this->client->public_event( $slug_hint, $locale );

		if ( $result->ok && Event_View::is_valid_payload( $result->data ) ) {
			if ( $this->has_marker_key( $result->data ) ) {
				return $this->failed( $id, $loc );
			}
			if ( $this->is_payload_for( $result->data, $id ) ) {
				return $this->accept( $result->data, $id, $preview_allowed, $loc );
			}
			// The slug now belongs to another event: same as a 404 for this id.
			$this->forget_slug( $slug_hint );
			return $this->public_not_found( $id, $loc );
		}

		if ( Result::NOT_FOUND === $result->code ) {
			return $this->public_not_found( $id, $loc );
		}

		return $this->failed( $id, $loc );
	}

	private function public_not_found( int $id, string $loc ): Event_View {
		$this->set_marker( $id, $loc, self::MARKER_GONE, min( $this->ttl(), self::GONE_MAX_TTL ) );

		return $this->fallback( $id, $loc, Event_View::GONE );
	}

	/**
	 * Caches a valid payload for $id and returns its view. Drafts never reach the public
	 * transient or last-good: a visitor gets a content-free marker, an editor the preview cache.
	 *
	 * @param array $payload Valid payload of event $id.
	 */
	private function accept( array $payload, int $id, bool $preview_allowed, string $loc ): Event_View {
		// `__state` is the cache's own marker key: a payload carrying it is refused, so it can never be
		// stored in a form that reads back as a marker (or smuggles one in).
		if ( $this->has_marker_key( $payload ) ) {
			return $this->failed( $id, $loc );
		}

		delete_transient( self::BACKOFF );

		$contract = $payload['contract'] ?? null;
		if ( is_int( $contract ) && $contract > Client::SUPPORTED_CONTRACT ) {
			set_transient( self::CONTRACT_NOTICE, 1, self::NOTICE_TTL );
		}

		$state = Event_View::state_of( $payload );

		if ( Event_View::UNAVAILABLE === $state ) {
			// Unknown status from a newer Odavent: show nothing and keep no content. A content-free marker
			// stops every page view from calling Odavent again for the same answer.
			$this->set_marker( $id, $loc, self::MARKER_UNKNOWN, min( $this->ttl(), self::GONE_MAX_TTL ) );

			return Event_View::empty( Event_View::UNAVAILABLE );
		}

		if ( Event_View::DRAFT === $state ) {
			$this->set_marker( $id, $loc, self::MARKER_DRAFT, $this->ttl() );
			// Unpublished: no locale may serve its old published copy, as stale or from the cache, any more.
			foreach ( Locale::all_key_parts() as $any_loc ) {
				delete_option( $this->lastgood_key( $id, $any_loc ) );
				if ( $any_loc !== $loc ) {
					delete_transient( $this->public_key( $id, $any_loc ) );
				}
			}
			$this->raw_drafts[ $id . '|' . $loc ] = $payload;
			if ( ! $preview_allowed ) {
				return Event_View::empty( Event_View::DRAFT );
			}
			set_transient( $this->preview_key( $id, $loc ), $payload, self::PREVIEW_TTL );

			return Event_View::from_payload( $payload, true );
		}

		set_transient( $this->public_key( $id, $loc ), $payload, $this->ttl() );
		update_option( $this->lastgood_key( $id, $loc ), $payload, false );
		$this->track( $id );
		$this->remember_slug( $payload );

		return Event_View::from_payload( $payload );
	}

	/**
	 * Odavent failed (unavailable, invalid key, malformed answer): trip the breaker and serve the
	 * last good copy as stale, or UNAVAILABLE.
	 */
	private function failed( int $id, string $loc ): Event_View {
		set_transient( self::BACKOFF, 1, self::BACKOFF_TTL );

		return $this->fallback( $id, $loc, Event_View::UNAVAILABLE );
	}

	private function fallback( int $id, string $loc, string $otherwise ): Event_View {
		$last_good = get_option( $this->lastgood_key( $id, $loc ), null );
		if ( is_array( $last_good ) && $this->is_payload_for( $last_good, $id ) ) {
			$view = Event_View::from_payload( $last_good, false, true );
			if ( $view->has_content() ) {
				return $view;
			}
		}

		return Event_View::empty( $otherwise );
	}

	// ---- slug resolution -----------------------------------------------------------

	private function resolve_from_list( string $slug ): ?int {
		$list = get_transient( self::LIST_TRANSIENT );

		if ( ! is_array( $list ) ) {
			if ( $this->breaker_active() ) {
				return $this->slug_map()[ $slug ] ?? null;
			}

			$result = $this->client->list_events();
			if ( $result->ok ) {
				delete_transient( self::BACKOFF );
			} else {
				if ( Result::PLAN_REQUIRED === $result->code ) {
					$this->options->set_connection_disabled( true );
					return $this->resolve_public( $slug );
				}
				if ( Result::INVALID_KEY === $result->code ) {
					set_transient( self::INVALID_KEY_NOTICE, 1, self::NOTICE_TTL );
				}
				set_transient( self::BACKOFF, 1, self::BACKOFF_TTL );

				return $this->slug_map()[ $slug ] ?? null;
			}

			$list = $this->store_list( $result->data );
		}

		foreach ( $list as $row ) {
			// A draft is not resolvable by slug: no visitor may tell a draft from an unknown slug.
			if ( is_array( $row ) && 'draft' !== ( $row['status'] ?? null ) && ( $row['slug'] ?? null ) === $slug && is_int( $row['id'] ?? null ) ) {
				return $row['id'];
			}
		}

		return null;
	}

	private function resolve_public( string $slug ): ?int {
		$known = $this->slug_map()[ $slug ] ?? null;
		if ( is_int( $known ) ) {
			return $known;
		}

		if ( $this->breaker_active() ) {
			return null;
		}

		$locale = Locale::current();
		$loc    = null === $locale ? Locale::DEFAULT_KEY : $locale;
		$result = $this->client->public_event( $slug, $locale );

		if ( $result->ok && Event_View::is_valid_payload( $result->data ) ) {
			$id   = $result->data['event']['id'];
			$view = $this->accept( $result->data, $id, false, $loc );
			// Seed the memo so get() for this event in this request makes no second call.
			$this->memo[ $id . '|' . $loc . '|0' ] = $view;

			return $view->has_content() ? $id : null;
		}

		if ( ! $result->ok && Result::NOT_FOUND !== $result->code ) {
			set_transient( self::BACKOFF, 1, self::BACKOFF_TTL );
		}

		return null;
	}

	/**
	 * Caches the events list and refreshes the last known slug -> id map (non-draft rows only).
	 *
	 * @param array $data List rows from the key route.
	 * @return array The rows.
	 */
	private function store_list( array $data ): array {
		set_transient( self::LIST_TRANSIENT, $data, self::LIST_TTL );

		$map = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) || 'draft' === ( $row['status'] ?? null ) ) {
				continue;
			}
			$slug = $row['slug'] ?? null;
			$id   = $row['id'] ?? null;
			if ( is_string( $slug ) && '' !== $slug && is_int( $id ) && $id > 0 ) {
				$map[ $slug ] = $id;
			}
		}
		if ( $map !== $this->slug_map() ) {
			update_option( self::SLUGS_OPTION, $map, false );
		}

		return $data;
	}

	private function remember_slug( array $payload ): void {
		$slug = $payload['event']['slug'] ?? null;
		$id   = $payload['event']['id'];
		if ( ! is_string( $slug ) || '' === $slug ) {
			return;
		}

		$map = $this->slug_map();
		if ( ( $map[ $slug ] ?? null ) !== $id ) {
			$map[ $slug ] = $id;
			update_option( self::SLUGS_OPTION, $map, false );
		}
	}

	private function forget_slug( string $slug ): void {
		$map = $this->slug_map();
		if ( array_key_exists( $slug, $map ) ) {
			unset( $map[ $slug ] );
			update_option( self::SLUGS_OPTION, $map, false );
		}
	}

	/**
	 * @return array<string, int>
	 */
	private function slug_map(): array {
		$map = get_option( self::SLUGS_OPTION, array() );

		return is_array( $map ) ? $map : array();
	}

	// ---- helpers ---------------------------------------------------------------------

	private function key_routes_usable(): bool {
		return $this->options->has_key() && ! $this->options->is_connection_disabled();
	}

	private function breaker_active(): bool {
		return false !== get_transient( self::BACKOFF );
	}

	/**
	 * The marker a cached array stands for, or null when it is a payload. A marker is exactly one key:
	 * `__state` set to a known name. Anything else (a payload that carries `__state` among other keys) is no marker.
	 *
	 * @param array $cached Cached transient value.
	 */
	private function marker_of( array $cached ): ?string {
		if ( 1 !== count( $cached ) ) {
			return null;
		}

		$marker = $cached[ self::MARKER ] ?? null;

		return is_string( $marker ) && in_array( $marker, self::MARKERS, true ) ? $marker : null;
	}

	private function has_marker_key( array $payload ): bool {
		return array_key_exists( self::MARKER, $payload );
	}

	/**
	 * @param array $payload Candidate payload.
	 */
	private function is_payload_for( array $payload, int $id ): bool {
		return Event_View::is_valid_payload( $payload ) && ! $this->has_marker_key( $payload ) && $payload['event']['id'] === $id;
	}

	private function set_marker( int $id, string $loc, string $state, int $ttl ): void {
		set_transient( $this->public_key( $id, $loc ), array( self::MARKER => $state ), $ttl );
		$this->track( $id );
	}

	private function track( int $id ): void {
		$index = $this->index();
		if ( ! in_array( $id, $index, true ) ) {
			$index[] = $id;
			update_option( self::INDEX_OPTION, $index, false );
		}
	}

	/**
	 * @return int[]
	 */
	private function index(): array {
		$index = get_option( self::INDEX_OPTION, array() );

		return is_array( $index ) ? array_values( array_filter( $index, 'is_int' ) ) : array();
	}

	private function ttl(): int {
		return max( Options::MIN_CACHE_TTL, min( Options::MAX_CACHE_TTL, $this->options->cache_ttl() ) );
	}

	private function public_key( int $id, string $loc ): string {
		return self::PREFIX . $id . '_' . $loc;
	}

	private function preview_key( int $id, string $loc ): string {
		return self::PREFIX . $id . '_' . $loc . self::PREVIEW_SUFFIX;
	}

	private function lastgood_key( int $id, string $loc ): string {
		return self::LASTGOOD_PREFIX . $id . '_' . $loc;
	}

	/**
	 * Keeps a draft preview out of browser, proxy and page caches.
	 */
	private function mark_uncacheable(): void {
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}
}

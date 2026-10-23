<?php
namespace Odavent\Events\Data;

/**
 * What a page renders for one event: a lifecycle state plus, when allowed, the payload.
 *
 * Built only through from_payload() and empty(). A draft payload is kept only for a preview view:
 * a visitor's draft view holds nothing. Every accessor tolerates missing keys and wrong types,
 * because the payload comes from the network (and from older or newer Odavent versions).
 */
final class Event_View {

	const LIVE        = 'live';
	const ENDED       = 'ended';
	const PAUSED      = 'paused';
	const CANCELLED   = 'cancelled';
	const ARCHIVED    = 'archived';
	const DRAFT       = 'draft';
	const GONE        = 'gone';
	const UNAVAILABLE = 'unavailable';

	const STATES = array(
		self::LIVE,
		self::ENDED,
		self::PAUSED,
		self::CANCELLED,
		self::ARCHIVED,
		self::DRAFT,
		self::GONE,
		self::UNAVAILABLE,
	);

	/** @var string */
	private $state;

	/** @var array */
	private $payload;

	/** @var bool */
	private $preview;

	/** @var bool */
	private $stale;

	private function __construct( string $state, array $payload, bool $preview, bool $stale ) {
		$this->state   = $state;
		$this->payload = $payload;
		$this->preview = $preview;
		$this->stale   = $stale;
	}

	/**
	 * A view of an Odavent payload (the `data` of the API envelope).
	 *
	 * An invalid payload or an unknown status gives an empty UNAVAILABLE view, and a draft
	 * without $preview gives an empty DRAFT view: the draft's content is not kept.
	 *
	 * @param array $payload Event payload.
	 * @param bool  $preview Whether the reader may see a draft (editor preview).
	 * @param bool  $stale   Whether this is the last good copy served because Odavent failed.
	 */
	public static function from_payload( array $payload, bool $preview = false, bool $stale = false ): Event_View {
		$state = self::state_of( $payload );

		if ( self::UNAVAILABLE === $state ) {
			return self::empty( self::UNAVAILABLE );
		}

		if ( self::DRAFT === $state && ! $preview ) {
			return self::empty( self::DRAFT );
		}

		return new self( $state, $payload, self::DRAFT === $state, $stale );
	}

	/**
	 * A view without content. An unknown state becomes UNAVAILABLE.
	 *
	 * @param string $state One of the state constants.
	 */
	public static function empty( string $state ): Event_View {
		if ( ! in_array( $state, self::STATES, true ) ) {
			$state = self::UNAVAILABLE;
		}

		return new self( $state, array(), false, false );
	}

	/**
	 * Whether a payload carries a non-empty `event` object with a positive integer `id`.
	 *
	 * @param array $payload Event payload.
	 */
	public static function is_valid_payload( array $payload ): bool {
		if ( ! isset( $payload['event'] ) || ! is_array( $payload['event'] ) || array() === $payload['event'] ) {
			return false;
		}

		$id = $payload['event']['id'] ?? null;

		return is_int( $id ) && $id > 0;
	}

	/**
	 * The lifecycle state a payload maps to (UNAVAILABLE when invalid or the status is unknown).
	 *
	 * @param array $payload Event payload.
	 */
	public static function state_of( array $payload ): string {
		if ( ! self::is_valid_payload( $payload ) ) {
			return self::UNAVAILABLE;
		}

		$event = $payload['event'];

		switch ( $event['status'] ?? null ) {
			case 'draft':
				return self::DRAFT;
			case 'paused':
				return self::PAUSED;
			case 'cancelled':
				return self::CANCELLED;
			case 'archived':
				return self::ARCHIVED;
			case 'published':
				return true === ( $event['ended'] ?? null ) ? self::ENDED : self::LIVE;
			default:
				return self::UNAVAILABLE;
		}
	}

	public function state(): string {
		return $this->state;
	}

	/**
	 * True only for a draft shown to an editor. Such a response must never be page-cached.
	 */
	public function is_preview(): bool {
		return $this->preview;
	}

	/**
	 * True when this is the last good copy, served because Odavent could not be reached.
	 */
	public function is_stale(): bool {
		return $this->stale;
	}

	public function has_content(): bool {
		return array() !== $this->payload;
	}

	/**
	 * @return array The `event` object, or [] when there is none.
	 */
	public function event(): array {
		$event = $this->payload['event'] ?? null;

		return is_array( $event ) ? $event : array();
	}

	/**
	 * @return array[] Ticket rows.
	 */
	public function tickets(): array {
		return $this->rows( 'tickets' );
	}

	/**
	 * @return array[] Session rows.
	 */
	public function sessions(): array {
		return $this->rows( 'sessions' );
	}

	/**
	 * @return array[] Speaker rows.
	 */
	public function speakers(): array {
		return $this->rows( 'speakers' );
	}

	/**
	 * @return array[] Partner rows.
	 */
	public function partners(): array {
		return $this->rows( 'partners' );
	}

	/**
	 * Speakers keyed by their integer id (rows without one are left out).
	 *
	 * @return array<int, array>
	 */
	public function speakers_by_id(): array {
		$by_id = array();
		foreach ( $this->speakers() as $speaker ) {
			$id = $speaker['id'] ?? null;
			if ( is_int( $id ) ) {
				$by_id[ $id ] = $speaker;
			}
		}

		return $by_id;
	}

	/**
	 * Register is offered only for a published event (live or ended) whose registration is open.
	 */
	public function can_register(): bool {
		if ( self::LIVE !== $this->state && self::ENDED !== $this->state ) {
			return false;
		}

		return true === ( $this->event()['registration_open'] ?? null );
	}

	/**
	 * Which notice the page shows: ended, paused, cancelled, draft_preview, unavailable, or null.
	 */
	public function notice_key(): ?string {
		switch ( $this->state ) {
			case self::ENDED:
			case self::ARCHIVED:
				return 'ended';
			case self::PAUSED:
				return 'paused';
			case self::CANCELLED:
				return 'cancelled';
			case self::DRAFT:
				return $this->preview ? 'draft_preview' : null;
			case self::UNAVAILABLE:
				return 'unavailable';
			default:
				return null;
		}
	}

	/**
	 * The array rows of a top-level list, re-indexed; [] when the list is missing or not an array.
	 *
	 * @param string $key Top-level payload key.
	 * @return array[]
	 */
	private function rows( string $key ): array {
		$list = $this->payload[ $key ] ?? null;
		if ( ! is_array( $list ) ) {
			return array();
		}

		return array_values( array_filter( $list, 'is_array' ) );
	}
}

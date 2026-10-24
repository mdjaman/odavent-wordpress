<?php
namespace Odavent\Events\Refresh;

/**
 * Purges the full-page caches of posts that show Odavent content after an event changed.
 *
 * Which posts show a given event cannot be known cheaply (blocks fall back to the default event,
 * shortcodes may name it by slug), so every published post of a public type containing an
 * Odavent block (`wp:odavent-events/`) or shortcode (`[odavent_`) is purged: correctness over
 * precision. Sites with special needs adjust the list with the `odavent_events_purge_post_ids`
 * filter. When more than LIMIT posts qualify, the most recent ones (highest ids) are purged.
 */
class Page_Cache_Purger {

	const LIMIT = 500;

	/** Page-cache plugin functions taking a post id, called when present. */
	const CACHE_FUNCTIONS = array( 'rocket_clean_post', 'w3tc_flush_post', 'wp_cache_post_change' );

	/** @var int[]|null Post ids found in this request. */
	private $post_ids = null;

	/**
	 * @param int $event_id Odavent event id that changed.
	 * @return int Number of posts purged.
	 */
	public function purge_event( int $event_id ): int {
		/**
		 * Filters the ids of the posts purged when an Odavent event changes.
		 *
		 * @param int[] $post_ids Published posts using any Odavent block or shortcode (max 500).
		 * @param int   $event_id Odavent event id.
		 */
		$filtered = apply_filters( 'odavent_events_purge_post_ids', $this->post_ids(), $event_id );
		$post_ids = $this->normalize_ids( $filtered );

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );

			foreach ( self::CACHE_FUNCTIONS as $function ) {
				if ( function_exists( $function ) ) {
					call_user_func( $function, $post_id );
				}
			}
			do_action( 'litespeed_purge_post', $post_id );

			/**
			 * Fires for each post purged after an Odavent event changed, for other page caches.
			 *
			 * @param int $post_id Post id.
			 */
			do_action( 'odavent_events_purge_post', $post_id );
		}

		return count( $post_ids );
	}

	/**
	 * Published posts of public types whose content uses an Odavent block or shortcode.
	 * One query per request.
	 *
	 * @return int[]
	 */
	private function post_ids(): array {
		if ( null !== $this->post_ids ) {
			return $this->post_ids;
		}

		global $wpdb;

		$types = array_values( array_filter( (array) get_post_types( array( 'public' => true ), 'names' ), 'is_string' ) );
		if ( array() === $types || ! is_object( $wpdb ) ) {
			$this->post_ids = array();
			return $this->post_ids;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})"
			. ' AND (post_content LIKE %s OR post_content LIKE %s) ORDER BY ID DESC LIMIT ' . self::LIMIT;
		$args         = array_merge(
			$types,
			array(
				'%' . $wpdb->esc_like( 'wp:odavent-events/' ) . '%',
				'%' . $wpdb->esc_like( '[odavent_' ) . '%',
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only.
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$args ) );

		$this->post_ids = $this->normalize_ids( $ids );

		return $this->post_ids;
	}

	/**
	 * Positive, unique integer ids in their original order.
	 *
	 * @param mixed $ids Candidate ids.
	 * @return int[]
	 */
	private function normalize_ids( $ids ): array {
		if ( ! is_array( $ids ) ) {
			return array();
		}

		$out = array();
		foreach ( $ids as $id ) {
			// preg_match, not ctype_digit(): ext-ctype is optional.
			if ( is_int( $id ) || ( is_string( $id ) && 1 === preg_match( '/^[0-9]+$/D', $id ) ) ) {
				$id = (int) $id;
				if ( $id > 0 && ! in_array( $id, $out, true ) ) {
					$out[] = $id;
				}
			}
		}

		return $out;
	}
}

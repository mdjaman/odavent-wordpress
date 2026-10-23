<?php
namespace Odavent\Events\Rest;

use Odavent\Events\Api\Result;
use Odavent\Events\Data\Event_Repository;
use Odavent\Events\Render\Sanitizer;

/**
 * GET /wp-json/odavent/v1/events: the event picker of the block editor.
 *
 * Only for users who can edit posts. Answers [{id, slug, name, status}] from the site key's events list
 * (drafts included: editors choose events before they are published). Failures are generic WP_Errors that
 * never carry the key or Odavent's own text.
 */
class Editor_Controller {

	const NAMESPACE_V1 = 'odavent/v1';
	const ROUTE        = '/events';

	/** @var Event_Repository */
	private $repository;

	public function __construct( Event_Repository $repository ) {
		$this->repository = $repository;
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			self::ROUTE,
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_events' ),
				'permission_callback' => array( $this, 'can_list' ),
			)
		);
	}

	/**
	 * Permission callback: `edit_posts`. 401 when signed out, 403 when signed in without the capability.
	 *
	 * @return true|\WP_Error
	 */
	public function can_list() {
		if ( current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to list Odavent events.', 'odavent-events' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function list_events() {
		try {
			$result = $this->repository->list_for_editors();
		} catch ( \Throwable $e ) {
			return self::error( Result::UNAVAILABLE );
		}

		if ( ! $result->ok ) {
			return self::error( $result->code );
		}

		$events = array();
		foreach ( $result->data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = $row['id'] ?? null;
			if ( ! is_int( $id ) || $id <= 0 ) {
				continue;
			}

			$events[] = array(
				'id'     => $id,
				'slug'   => Sanitizer::text( $row['slug'] ?? null ),
				'name'   => Sanitizer::text( $row['name'] ?? null ),
				'status' => Sanitizer::text( $row['status'] ?? null ),
			);
		}

		return new \WP_REST_Response( $events, 200 );
	}

	/**
	 * @param string $code Result code.
	 */
	private static function error( string $code ): \WP_Error {
		switch ( $code ) {
			case Result::INVALID_KEY:
				return new \WP_Error(
					'odavent_invalid_key',
					__( 'The Odavent site key is missing or was rejected. Check Settings > Odavent Events.', 'odavent-events' ),
					array( 'status' => 401 )
				);
			case Result::PLAN_REQUIRED:
				return new \WP_Error(
					'odavent_plan_required',
					__( 'Your Odavent plan does not include the WordPress plugin. Events can still be chosen by slug.', 'odavent-events' ),
					array( 'status' => 403 )
				);
			default:
				return new \WP_Error(
					'odavent_unavailable',
					__( 'Odavent could not be reached. Try again in a moment.', 'odavent-events' ),
					array( 'status' => 503 )
				);
		}
	}
}

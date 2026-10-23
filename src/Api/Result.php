<?php
namespace Odavent\Events\Api;

/**
 * Outcome of an Odavent API call. The client never throws: every failure is a Result with a code.
 *
 * Codes: invalid_key, plan_required, not_found, unavailable. Properties are public and set only by
 * the two named constructors (the constructor is private), so a Result is effectively read-only.
 */
final class Result {

	const INVALID_KEY   = 'invalid_key';
	const PLAN_REQUIRED = 'plan_required';
	const NOT_FOUND     = 'not_found';
	const UNAVAILABLE   = 'unavailable';

	/** @var bool */
	public $ok;

	/** @var array Unwrapped envelope `data` (empty on failure). */
	public $data;

	/** @var string '' when ok, otherwise one of the code constants. */
	public $code;

	/** @var int HTTP status, 0 when there was no HTTP response. */
	public $status;

	/** @var string Short generic text for display. Never a raw response body and never the key. */
	public $message;

	private function __construct( bool $ok, array $data, string $code, int $status, string $message ) {
		$this->ok      = $ok;
		$this->data    = $data;
		$this->code    = $code;
		$this->status  = $status;
		$this->message = $message;
	}

	public static function ok( array $data, int $status = 200 ): Result {
		return new self( true, $data, '', $status, '' );
	}

	public static function fail( string $code, int $status = 0, string $message = '' ): Result {
		return new self( false, array(), $code, $status, $message );
	}
}

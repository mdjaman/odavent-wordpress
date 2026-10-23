<?php
namespace Odavent\Events\Tests\Support;

use Odavent\Events\Settings\Options;

/**
 * Test double that replaces the ODAVENT_SITE_KEY constant lookup (a defined constant cannot be undefined between tests).
 */
class OptionsWithConstant extends Options {

	/** @var string */
	public $constant = '';

	protected function constant_key(): string {
		return $this->constant;
	}
}

<?php

namespace s00d\GuzzleHttpRequest\Facades;

use Illuminate\Support\Facades\Facade as BaseFacade;

class GHRFacade extends BaseFacade {

	/**
	 * Get the registered name of the component.
	 *
	 * @return string
	 */
	protected static function getFacadeAccessor() { return 'GHR'; }
}

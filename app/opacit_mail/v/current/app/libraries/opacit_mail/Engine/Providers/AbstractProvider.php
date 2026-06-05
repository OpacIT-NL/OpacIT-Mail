<?php

namespace opacit_mail\Engine\Providers;

abstract class AbstractProvider
{
	use \opacit_mail\Mail\Log\Inherit;

	abstract public function IsActive() : bool;
}

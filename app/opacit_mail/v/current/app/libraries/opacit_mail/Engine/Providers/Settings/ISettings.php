<?php

namespace opacit_mail\Engine\Providers\Settings;

interface ISettings
{
	public function Load(\opacit_mail\Engine\Model\Account $oAccount) : array;

	public function Save(\opacit_mail\Engine\Model\Account $oAccount, \opacit_mail\Engine\Settings $oSettings) : bool;

	public function Delete(\opacit_mail\Engine\Model\Account $oAccount) : bool;
}

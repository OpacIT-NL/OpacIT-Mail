<?php

namespace opacit_mail\Engine\Providers;

use opacit_mail\Engine\Model\Account;
use opacit_mail\Engine\Providers\Settings\ISettings;

class Settings extends \opacit_mail\Engine\Providers\AbstractProvider
{
	private ISettings $oDriver;

	public function __construct(ISettings $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function Load(Account $oAccount) : \opacit_mail\Engine\Settings
	{
		return new \opacit_mail\Engine\Settings($this, $oAccount, $this->oDriver->Load($oAccount));
	}

	public function Save(Account $oAccount, \opacit_mail\Engine\Settings $oSettings) : bool
	{
		return $this->oDriver->Save($oAccount, $oSettings);
	}

	public function IsActive() : bool
	{
		return true;
	}
}

<?php

namespace opacit_mail\Engine\Providers\Filters;

interface FiltersInterface
{
	public function Load(\opacit_mail\Engine\Model\Account $oAccount) : array;

	public function Save(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool;

	public function Activate(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName) : bool;

	public function Delete(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName) : bool;
}

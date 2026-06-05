<?php

namespace opacit_mail\Engine\Providers\Suggestions;

interface ISuggestions
{
//	use \opacit_mail\Mail\Log\Inherit;
	public function Process(\opacit_mail\Engine\Model\Account $oAccount, string $sQuery, int $iLimit = 20) : array;
//	public function SetLogger(\opacit_mail\Mail\Log\Logger $oLogger) : void
}

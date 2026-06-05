<?php

namespace opacit_mail\Engine\SASL;

class Plain extends \opacit_mail\Engine\SASL
{

	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $passphrase,
		?string $authzid = null) : string
	{
		return $this->encode("{$authzid}\x00{$username}\x00{$passphrase}");
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}

}

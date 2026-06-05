<?php

namespace opacit_mail\Engine\SASL;

use opacit_mail\Engine\SensitiveString;

class Login extends \opacit_mail\Engine\SASL
{
	protected SensitiveString $passphrase;

	public function authenticate(string $username,
		#[\SensitiveParameter]
		string $passphrase,
		?string $challenge = null) : string
	{
		if ($challenge && !\str_starts_with($this->decode($challenge), 'Username:')) {
			throw new \Exception("Invalid response: {$this->decode($challenge)}");
		}
		$this->passphrase = new SensitiveString($passphrase);
		return $this->encode($username);
	}

	public function challenge(string $challenge) : ?string
	{
		if ($challenge && 'Password:' !== $this->decode($challenge)) {
			throw new \Exception("invalid response: {$challenge}");
		}
		return $this->encode($this->passphrase->getValue());
	}

	public static function isSupported(string $param) : bool
	{
		return true;
	}

}

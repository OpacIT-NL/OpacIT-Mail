<?php

/*
 * This file is part of MailSo.
 *
 * (c) 2022 DJMaze
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace X2Mail\Mail\Net;

use X2Mail\Engine\SensitiveString;

/**
 * @category MailSo
 * @package Net
 * @property string $username
 * @property string $passphrase
 */
class ConnectSettings implements \JsonSerializable
{
	public string $host;

	public int $port;

	/**
	 * stream timeout in seconds
	 */
	public int $timeout = 10;

	// none, TLS, STARTTLS
	public int $type = 9; // ConnectionSecurityType::AUTO_DETECT
//	public int $type = Enumerations\ConnectionSecurityType::NONE;

	public SSLContext $ssl;
//	public bool $tls_weak = false;

	// Authentication settings used by all child classes
	public bool $useAuth = true;
	public bool $lowerLogin = true;
	// NC-only OAUTHBEARER: a malicious or MITM mail server must not be able to
	// downgrade the client into sending the OIDC bearer token over PLAIN/LOGIN.
	// Offer OAuth mechanisms only. (Configured domains already get this list
	// from DomainConfigService; this guards the no-config default.)
	public array $SASLMechanisms = [
		'OAUTHBEARER',
		'XOAUTH2'
	];

	private string $username = '';
	private ?SensitiveString $passphrase = null;

	public function __construct()
	{
		$this->ssl = new SSLContext;
	}

	public function __get(string $name)
	{
		$name = \strtolower($name);
		if ('passphrase' === $name || 'password' === $name) {
			return $this->passphrase ? $this->passphrase->getValue() : '';
		}
		if ('username' === $name || 'login' === $name) {
			return $this->username;
		}
	}

	public function __set(string $name,
		#[\SensitiveParameter]
		$value
	) {
		$name = \strtolower($name);
		if ('passphrase' === $name || 'password' === $name) {
			$this->passphrase = \is_string($value) ? new SensitiveString($value) : $value;
		}
		if ('username' === $name || 'login' === $name) {
			$this->username = $value;
		}
	}

	public function fixUsername(string $value) : string
	{
		$value = \X2Mail\Engine\IDN::emailToAscii($value);
//		$value = \X2Mail\Engine\IDN::emailToAscii(\X2Mail\Mail\Base\Utils::Trim($value));
		// Convert to lowercase
		if ($this->lowerLogin) {
			$value = \mb_strtolower($value);
		}
		return $value;
	}

	public static function fromArray(array $aSettings) : self
	{
		/** @phpstan-ignore new.static */
		$object = new static;
		$object->host = $aSettings['host'];
		$object->port = $aSettings['port'];
		$object->type = $aSettings['type'] ?? $aSettings['secure'] ?? \X2Mail\Mail\Net\Enumerations\ConnectionSecurityType::AUTO_DETECT->value;
		if (isset($aSettings['timeout'])) {
			$object->timeout = $aSettings['timeout'];
		}
		if (isset($aSettings['lowerLogin'])) {
			$object->lowerLogin = !empty($aSettings['lowerLogin']);
		}
		$object->ssl = SSLContext::fromArray($aSettings['ssl'] ?? []);
		if (!empty($aSettings['sasl']) && \is_array($aSettings['sasl'])) {
			$object->SASLMechanisms = $aSettings['sasl'];
		}
//		$object->tls_weak = !empty($aSettings['tls_weak']);
		return $object;
	}

	#[\ReturnTypeWillChange]
	public function jsonSerialize()
	{
		return array(
//			'@Object' => 'Object/ConnectSettings',
			'host' => $this->host,
			'port' => $this->port,
			'type' => $this->type,
			'timeout' => $this->timeout,
			'lowerLogin' => $this->lowerLogin,
			'sasl' => $this->SASLMechanisms,
			'ssl' => $this->ssl
//			'tls_weak' => $this->tls_weak
		);
	}

}

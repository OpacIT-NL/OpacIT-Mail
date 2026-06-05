<?php

namespace opacit_mail\Engine\Providers;

class Filters extends \opacit_mail\Engine\Providers\AbstractProvider
{
	/**
	 * @var \opacit_mail\Engine\Providers\Filters\FiltersInterface
	 */
	private $oDriver;

	public function __construct(\opacit_mail\Engine\Providers\Filters\FiltersInterface $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	private static function handleException(\Throwable $oException, int $defNotification) : void
	{
		if ($oException instanceof \opacit_mail\Mail\Net\Exceptions\SocketCanNotConnectToHostException) {
			throw new \opacit_mail\Engine\Exceptions\ClientException(\opacit_mail\Engine\Notifications::ConnectionError->value, $oException);
		}

		if ($oException instanceof \opacit_mail\Mail\Sieve\Exceptions\NegativeResponseException) {
			throw new \opacit_mail\Engine\Exceptions\ClientException(
				\opacit_mail\Engine\Notifications::ClientViewError->value, $oException, \implode("\r\n", $oException->GetResponses())
			);
		}

		throw new \opacit_mail\Engine\Exceptions\ClientException($defNotification, $oException);
	}

	public function Load(\opacit_mail\Engine\Model\Account $oAccount) : array
	{
		try
		{
			return $this->IsActive() ? $this->oDriver->Load($oAccount) : array();
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \opacit_mail\Engine\Notifications::CantGetFilters->value);
		}
		return array();
	}

	public function Save(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Save($oAccount, $sScriptName, $sRaw)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \opacit_mail\Engine\Notifications::CantSaveFilters->value);
		}
		return false;
	}

	public function ActivateScript(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Activate($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \opacit_mail\Engine\Notifications::CantActivateFiltersScript->value);
		}
	}

	public function DeleteScript(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName)
	{
		try
		{
			return $this->IsActive()
				? $this->oDriver->Delete($oAccount, $sScriptName)
				: false;
		}
		catch (\Throwable $oException)
		{
			self::handleException($oException, \opacit_mail\Engine\Notifications::CantDeleteFiltersScript->value);
		}
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof \opacit_mail\Engine\Providers\Filters\FiltersInterface;
	}
}

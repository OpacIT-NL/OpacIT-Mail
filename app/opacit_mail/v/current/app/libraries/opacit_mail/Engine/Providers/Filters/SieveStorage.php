<?php

namespace opacit_mail\Engine\Providers\Filters;

class SieveStorage implements FiltersInterface
{
	use \opacit_mail\Mail\Log\Inherit;

	const SIEVE_FILE_NAME = 'opacit_mail.user';

	/**
	 * @var \opacit_mail\Engine\Plugins\Manager
	 */
	private $oPlugins;

	/**
	 * @var \opacit_mail\Engine\Config\Application
	 */
	private $oConfig;

	public function __construct($oPlugins, $oConfig)
	{
		$this->oPlugins = $oPlugins;
		$this->oConfig = $oConfig;
	}

	protected function getConnection(\opacit_mail\Engine\Model\Account $oAccount) : ?\opacit_mail\Mail\Sieve\SieveClient
	{
		$oSieveClient = new \opacit_mail\Mail\Sieve\SieveClient();
		$oSieveClient->SetLogger($this->oLogger);
		return $oAccount->SieveConnectAndLogin($this->oPlugins, $oSieveClient, $this->oConfig)
			 ? $oSieveClient
			 : null;
	}

	public function Load(\opacit_mail\Engine\Model\Account $oAccount) : array
	{
		$aModules = array();
		$aScripts = array();

		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$aModules = $oSieveClient->Modules();
			\sort($aModules);

			$aList = $oSieveClient->ListScripts();

			foreach ($aList as $name => $active) {
				$aScripts[$name] = array(
					'@Object' => 'Object/SieveScript',
					'name' => $name,
					'active' => $active,
					'body' => $oSieveClient->GetScript($name)
				);
			}

			$oSieveClient->Disconnect();

			if (!isset($aList[self::SIEVE_FILE_NAME])) {
				$aScripts[self::SIEVE_FILE_NAME] = array(
					'@Object' => 'Object/SieveScript',
					'name' => self::SIEVE_FILE_NAME,
					'active' => false,
					'body' => ''
				);
			}
		}

		\ksort($aScripts);

		return array(
			'Capa' => $aModules,
			'Scripts' => $aScripts
		);
	}

	public function Save(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName, string $sRaw) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->PutScript($sScriptName, $sRaw);
			return true;
		}
		return false;
	}

	/**
	 * If $sScriptName is the empty string (i.e., ""), then any active script is disabled.
	 */
	public function Activate(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->SetActiveScript(\trim($sScriptName));
			return true;
		}
		return false;
	}
/*
	public function Check(\opacit_mail\Engine\Model\Account $oAccount, string $sScript) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			$oSieveClient->CheckScript($sScript);
			return true;
		}
		return false;
	}
*/
	public function Delete(\opacit_mail\Engine\Model\Account $oAccount, string $sScriptName) : bool
	{
		$oSieveClient = $this->getConnection($oAccount);
		if ($oSieveClient) {
			if (isset($oSieveClient->ListScripts()[$sScriptName])) {
				$oSieveClient->DeleteScript(\trim($sScriptName));
			}
			return true;
		}
		return false;
	}
}

<?php

namespace opacit_mail\Engine\Model;

use opacit_mail\Engine\Utils;
use opacit_mail\Engine\Exceptions\ClientException;
use opacit_mail\Engine\Notifications;
use opacit_mail\Engine\Providers\Storage\Enumerations\StorageType;
use opacit_mail\Engine\SensitiveString;

class MainAccount extends Account
{
	private ?SensitiveString $sCryptKey = null;

	public static function NewInstanceFromTokenArray(
		\opacit_mail\Engine\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false
	) : ?MainAccount {
		$oAccount = parent::NewInstanceFromTokenArray($oActions, $aAccountHash, $bThrowExceptionOnFalse);

		return $oAccount instanceof MainAccount ? $oAccount : null;
	}

	public function resealCryptKey(SensitiveString $oOldPass) : bool
	{
		$oStorage = \opacit_mail\Engine\Api::Actions()->StorageProvider();
		$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
		if ($sKey) {
			$sKey = \opacit_mail\Engine\Crypt::DecryptFromJSON($sKey, $oOldPass);
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$sKey = \opacit_mail\Engine\Crypt::EncryptToJSON($sKey, $this->ImapPass());
			if ($sKey) {
				$this->sCryptKey = null;
				if (\opacit_mail\Engine\Api::Actions()->StorageProvider()->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey)) {
					return true;
				}
			}
		}
		return false;
	}

	public function CryptKey() : string
	{
		if (!$this->sCryptKey) {
			// Seal the cryptkey so that people who change their login password
			// can use the old password to re-seal the cryptkey
			$oStorage = \opacit_mail\Engine\Api::Actions()->StorageProvider();
			$sKey = $oStorage->Get($this, StorageType::ROOT->value, '.cryptkey');
			if (!$sKey) {
				$sKey = \opacit_mail\Engine\Crypt::EncryptToJSON(
					\sha1($this->ImapPass() . APP_SALT),
					$this->ImapPass()
				);
				$oStorage->Put($this, StorageType::ROOT->value, '.cryptkey', $sKey);
			}
			$sKey = \opacit_mail\Engine\Crypt::DecryptFromJSON($sKey, $this->ImapPass());
			if (!$sKey) {
				throw new ClientException(Notifications::CryptKeyError->value);
			}
			$this->sCryptKey = new SensitiveString(\hex2bin($sKey));
		}
		return $this->sCryptKey;
	}

/*
	// Stores settings in MainAccount
	public function settings() : \opacit_mail\Engine\Settings
	{
		return \opacit_mail\Engine\Api::Actions()->SettingsProvider()->Load($this);
	}
*/
}

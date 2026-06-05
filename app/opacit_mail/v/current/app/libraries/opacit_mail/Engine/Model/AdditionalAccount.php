<?php

namespace opacit_mail\Engine\Model;

use opacit_mail\Engine\Utils;
use opacit_mail\Engine\Exceptions\ClientException;

class AdditionalAccount extends Account
{
	public function ParentEmail() : string
	{
		return \opacit_mail\Engine\IDN::emailToAscii(\opacit_mail\Engine\Api::Actions()->getMainAccountFromToken()->Email());
	}

	public function Hash() : string
	{
		return \sha1(parent::Hash() . $this->ParentEmail());
	}

	public static function convertArray(array $aAccount) : array
	{
		$aResult = parent::convertArray($aAccount);
		$iCount = \count($aAccount);
		if ($aResult && 7 < $iCount && 9 >= $iCount) {
			$aResult['hmac'] = \array_pop($aAccount);
		}
		return $aResult;
	}

	public function asTokenArray(MainAccount $oMainAccount) : array
	{
		$sHash = $oMainAccount->CryptKey();
		$aData = $this->jsonSerialize();
		$aData['pass'] = \opacit_mail\Engine\Crypt::EncryptUrlSafe($aData['pass'], $sHash); // sPassword
		if (!empty($aData['smtp']['pass'])) {
			$aData['smtp']['pass'] = \opacit_mail\Engine\Crypt::EncryptUrlSafe($aData['smtp']['pass'], $sHash);
		}
		$aData['hmac'] = \hash_hmac('sha1', $aData['pass'], $sHash);
		return $aData;
	}

	public static function NewInstanceFromTokenArray(
		\opacit_mail\Engine\Actions $oActions,
		array $aAccountHash,
		bool $bThrowExceptionOnFalse = false) : ?Account /* PHP7.4: ?self*/
	{
		$aAccountHash = static::convertArray($aAccountHash);
		if (!empty($aAccountHash['email'])) {
			$sHash = $oActions->getMainAccountFromToken()->CryptKey();
			// hmac only set when asTokenArray() was used
			$sPasswordHMAC = $aAccountHash['hmac'] ?? null;
			if ($sPasswordHMAC) {
				if ($sPasswordHMAC === \hash_hmac('sha1', $aAccountHash['pass'], $sHash)) {
					$aAccountHash['pass'] = \opacit_mail\Engine\Crypt::DecryptUrlSafe($aAccountHash['pass'], $sHash);
					if (!empty($aAccountHash['smtp']['pass'])) {
						$aAccountHash['smtp']['pass'] = \opacit_mail\Engine\Crypt::DecryptUrlSafe($aAccountHash['smtp']['pass'], $sHash);
					}
				} else {
					$aAccountHash['pass'] = '';
					if (!empty($aAccountHash['smtp']['pass'])) {
						$aAccountHash['smtp']['pass'] = '';
					}
				}
			}
			return parent::NewInstanceFromTokenArray($oActions, $aAccountHash, $bThrowExceptionOnFalse);
		}
		return null;
	}

}

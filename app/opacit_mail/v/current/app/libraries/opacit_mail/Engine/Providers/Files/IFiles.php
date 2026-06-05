<?php

namespace opacit_mail\Engine\Providers\Files;

interface IFiles
{
	public function GenerateLocalFullFileName(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : string;

	public function PutFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, /*resource*/ $rSource) : bool;

	public function MoveUploadedFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, string $sSource) : bool;

	/**
	 * @return resource|bool
	 */
	public function GetFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, string $sOpenMode = 'rb');

	/**
	 * @return string|bool
	 */
	public function GetFileName(\opacit_mail\Engine\Model\Account $oAccount, string $sKey);

	public function Clear(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : bool;

	/**
	 * @return int | bool
	 */
	public function FileSize(\opacit_mail\Engine\Model\Account $oAccount, string $sKey);

	public function FileExists(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : bool;

	public function GC(int $iTimeToClearInHours = 24) : bool;
}

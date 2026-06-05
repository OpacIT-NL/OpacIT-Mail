<?php

namespace opacit_mail\Engine\Providers;

class Files extends \opacit_mail\Engine\Providers\AbstractProvider
{
	/**
	 * @var \opacit_mail\Engine\Providers\Files\IFiles
	 */
	private $oDriver;

	public function __construct(\opacit_mail\Engine\Providers\Files\IFiles $oDriver)
	{
		$this->oDriver = $oDriver;
	}

	public function PutFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, /*resource*/ $rSource) : bool
	{
		return $this->oDriver->PutFile($oAccount, $sKey, $rSource);
	}

	public function MoveUploadedFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, string $sSource) : bool
	{
		return $this->oDriver->MoveUploadedFile($oAccount, $sKey, $sSource);
	}

	/**
	 * @return resource|bool
	 */
	public function GetFile(\opacit_mail\Engine\Model\Account $oAccount, string $sKey, string $sOpenMode = 'rb')
	{
		return $this->oDriver->GetFile($oAccount, $sKey, $sOpenMode);
	}

	public function GetFileName(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : string
	{
		return $this->oDriver->GetFileName($oAccount, $sKey);
	}

	public function Clear(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : bool
	{
		return $this->oDriver->Clear($oAccount, $sKey);
	}

	public function FileSize(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : int
	{
		return $this->oDriver->FileSize($oAccount, $sKey);
	}

	public function FileExists(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : bool
	{
		return $this->oDriver->FileExists($oAccount, $sKey);
	}

	public function GC(int $iTimeToClearInHours = 24) : bool
	{
		return $this->oDriver ? $this->oDriver->GC($iTimeToClearInHours) : false;
	}

	public function CloseAllOpenedFiles() : bool
	{
		return $this->oDriver && \method_exists($this->oDriver, 'CloseAllOpenedFiles') ?
			$this->oDriver->CloseAllOpenedFiles() : false;
	}

	public function GenerateLocalFullFileName(\opacit_mail\Engine\Model\Account $oAccount, string $sKey) : string
	{
		return $this->oDriver ? $this->oDriver->GenerateLocalFullFileName($oAccount, $sKey) : '';
	}

	public function IsActive() : bool
	{
		return $this->oDriver instanceof \opacit_mail\Engine\Providers\Files\IFiles;
	}
}

<?php

namespace opacit_mail\Engine\Actions;

use opacit_mail\Engine\Enumerations\Capa;

trait Filters
{
	private ?\opacit_mail\Engine\Providers\Filters $oFiltersProvider = null;

	/**
	 * @throws \opacit_mail\Mail\RuntimeException
	 */
	public function DoFilters() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->Load($oAccount));
	}

	/**
	 * @throws \opacit_mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptSave() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		$sName = $this->GetActionParam('name', '');

		if ($this->GetActionParam('active', false)) {
//			$this->FiltersProvider()->ActivateScript($oAccount, $sName);
		}

		return $this->DefaultResponse($this->FiltersProvider()->Save(
			$oAccount, $sName, $this->GetActionParam('body', '')
		));
	}

	/**
	 * @throws \opacit_mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptActivate() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->ActivateScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	/**
	 * @throws \opacit_mail\Mail\RuntimeException
	 */
	public function DoFiltersScriptDelete() : array
	{
		$oAccount = $this->getAccountFromToken();

		if (!$this->GetCapa(Capa::SIEVE->value, $oAccount)) {
			return $this->FalseResponse();
		}

		return $this->DefaultResponse($this->FiltersProvider()->DeleteScript(
			$oAccount, $this->GetActionParam('name', '')
		));
	}

	protected function FiltersProvider() : \opacit_mail\Engine\Providers\Filters
	{
		if (!$this->oFiltersProvider) {
			$this->oFiltersProvider = new \opacit_mail\Engine\Providers\Filters($this->fabrica('filters'));
		}
		return $this->oFiltersProvider;
	}
}

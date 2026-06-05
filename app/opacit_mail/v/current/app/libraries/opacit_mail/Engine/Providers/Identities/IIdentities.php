<?php

namespace opacit_mail\Engine\Providers\Identities;

use opacit_mail\Engine\Model\Account;
use opacit_mail\Engine\Model\Identity;

interface IIdentities
{
	/**
	 * @param Account $account
	 *
	 * @return Identity[]
	 */
	public function GetIdentities(Account $account): array;

	/**
	 * @param Account $account
	 * @param Identity[] $identities
	 *
	 * @return void
	 */
	public function SetIdentities(Account $account, array $identities): void;

	/**
	 * @return bool
	 */
	public function SupportsStore(): bool;

	/**
	 * @return string
	 */
	public function Name(): string;
}

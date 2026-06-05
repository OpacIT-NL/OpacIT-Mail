<?php

namespace X2Mail\Engine\Actions;

use X2Mail\Engine\Enumerations\Capa;
use X2Mail\Engine\Notifications;
use X2Mail\Engine\Model\Account;
use X2Mail\Engine\Model\MainAccount;
use X2Mail\Engine\Model\AdditionalAccount;
use X2Mail\Engine\Exceptions\ClientException;
use X2Mail\Engine\Cookies;
use X2Mail\Engine\SensitiveString;

trait UserAuth
{
	/**
	 * @var bool | null | Account
	 */
	private $oAdditionalAuthAccount = false;
	private $oMainAuthAccount = false;

	public function DoResealCryptKey() : array
	{
		return $this->DefaultResponse(
			$this->getMainAccountFromToken()->resealCryptKey(
				new SensitiveString($this->GetActionParam('passphrase', ''))
			)
		);
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	protected function resolveLoginCredentials(string $sEmail, SensitiveString $oPassword): array
	{
		$sEmail = \X2Mail\Engine\IDN::emailToAscii(\X2Mail\Mail\Base\Utils::Trim($sEmail));

		$sNewEmail = $sEmail;
		$this->Plugins()->RunHook('login.credentials.step-1', array(&$sNewEmail));
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$oDomain = null;
		$oDomainProvider = $this->DomainProvider();

		// When email address is missing the domain, try to add it
		if (!\str_contains($sEmail, '@')) {
			$this->logWrite("The email address '{$sEmail}' is incomplete", \LOG_INFO, 'LOGIN');
			if (!$oDomain) {
				$sDefDomain = \trim($this->Config()->Get('login', 'default_domain', ''));
				if (\strlen($sDefDomain)) {
					if ('HTTP_HOST' === $sDefDomain || 'SERVER_NAME' === $sDefDomain) {
						$sDefDomain = \preg_replace('/:[0-9]+$/D', '', $_SERVER[$sDefDomain]);
					} else if ('gethostname' === $sDefDomain) {
						$sDefDomain = \gethostname();
					}
					$sEmail .= '@' . $sDefDomain;
					$this->logWrite("Default domain '{$sDefDomain}' will be used.", \LOG_INFO, 'LOGIN');
				} else {
					$this->logWrite('Default domain not configured.', \LOG_INFO, 'LOGIN');
				}
			}
		}

		$sNewEmail = $sEmail;
		$sPassword = $oPassword->getValue();
		$this->Plugins()->RunHook('login.credentials.step-2', array(&$sNewEmail, &$sPassword));
		$this->logMask($sPassword);
		if ($sNewEmail) {
			$sEmail = $sNewEmail;
		}

		$sImapUser = $sEmail;
		$sSmtpUser = $sEmail;
		if (\str_contains($sEmail, '@')
		 && ($oDomain || ($oDomain = $oDomainProvider->Load(\X2Mail\Mail\Base\Utils::getEmailAddressDomain($sEmail), true)))
		) {
			$sEmail = $oDomain->ImapSettings()->fixUsername($sEmail, false);
			$sImapUser = $oDomain->ImapSettings()->fixUsername($sImapUser);
			$sSmtpUser = $oDomain->SmtpSettings()->fixUsername($sSmtpUser);
		}

		$sNewEmail = $sEmail;
		$sNewImapUser = $sImapUser;
		$sNewSmtpUser = $sSmtpUser;
		$this->Plugins()->RunHook('login.credentials', array(&$sNewEmail, &$sNewImapUser, &$sPassword, &$sNewSmtpUser));

		$oPassword->setValue($sPassword);

		return [
			'email' => $sNewEmail ?: $sEmail,
			'domain' => $oDomain,
			'imapUser' => $sNewImapUser ?: $sImapUser,
			'smtpUser' => $sNewSmtpUser ?: $sSmtpUser,
			'pass' => $oPassword
		];
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function LoginProcess(string $sEmail, SensitiveString $oPassword, bool $bMainAccount = true): Account
	{
		$aCredentials = $this->resolveLoginCredentials($sEmail, $oPassword);

		if (!\str_contains($aCredentials['email'], '@') || !\strlen($oPassword)) {
			throw new ClientException(Notifications::InvalidInputArgument->value);
		}

		$oDomain = $this->DomainProvider()->getByEmailAddress($aCredentials['email']);

		$oAccount = null;
		try {
			$oAccount = $bMainAccount ? new MainAccount : new AdditionalAccount;
			$oAccount->setCredentials(
				$aCredentials['domain'],
				$aCredentials['email'],
				$aCredentials['imapUser'],
				$oPassword,
				$aCredentials['smtpUser']
//				,new SensitiveString($oPassword)
			);
			$this->Plugins()->RunHook('filter.account', array($oAccount));
			if (!$oAccount) {
				throw new ClientException(Notifications::AccountFilterError->value);
			}
		} catch (\Throwable $oException) {
			$this->LoggerAuthHelper($oAccount, $sEmail);
			throw $oException;
		}

		$this->imapConnect($oAccount, true);
		if ($bMainAccount) {
			// Must be here due to bug #1241
			$this->SetMainAuthAccount($oAccount);
			$this->Plugins()->RunHook('login.success', array($oAccount));

			$this->SetAuthToken($oAccount);
			$this->SetAdditionalAuthToken(null);
		}

		return $oAccount;
	}

	/**
	 * Reconstructs the MainAccount from the NC SSO session instead of a login POST
	 * or an auth cookie. Primary account-resolution path — called unconditionally
	 * by getMainAccountFromToken().
	 *
	 * Sources the email from EngineHelper::getSsoEmail() and uses the sentinel
	 * password 'oidc_login|<uid>'; beforeLogin() swaps the sentinel for the live
	 * OAUTHBEARER token on every connect (for both IMAP and SMTP), so there is no
	 * token-expiry issue and no separate SMTP password is needed — mirroring the
	 * 5-arg setCredentials() call in LoginProcess().
	 *
	 * The sentinel SensitiveString is passed by reference into
	 * resolveLoginCredentials() (which may mutate it via the login.credentials.step-2
	 * hook) and then into setCredentials() — the same contract as LoginProcess().
	 * Under the NC-only deployment no plugin registers that hook, so the sentinel
	 * reaches setCredentials() unchanged and beforeLogin() swaps it for the live
	 * token at connect.
	 */
	protected function accountFromNcSession() : ?MainAccount
	{
		$helper = \OCP\Server::get(\OCA\X2Mail\Util\EngineHelper::class);
		if (!$helper->isOIDCLogin()) {
			return null;
		}
		$sEmail = $helper->getSsoEmail();
		if (!$sEmail || !\str_contains($sEmail, '@')) {
			return null;
		}
		// Self-contained guard: never build a uid-less 'oidc_login|' sentinel that
		// would still pass LoginProcess()'s str_starts_with('oidc_login|') check.
		$sUid = $helper->getSsoUid();
		if (!$sUid) {
			return null;
		}
		// Sentinel password — beforeLogin() swaps it for the live OIDC token at connect.
		$oPassword = new SensitiveString('oidc_login|' . $sUid);
		$aCred = $this->resolveLoginCredentials($sEmail, $oPassword);
		$oAccount = new MainAccount;
		$oAccount->setCredentials(
			$aCred['domain'], $aCred['email'], $aCred['imapUser'], $oPassword, $aCred['smtpUser']
		);
		return $oAccount;
	}

	public function switchAccount(string $sEmail) : bool
	{
		$this->Http()->ServerNoCache();
		$oMainAccount = $this->getMainAccountFromToken(false);
		if ($sEmail && $oMainAccount && $this->GetCapa(Capa::ADDITIONAL_ACCOUNTS->value)) {
			$oAccount = null;
			if ($oMainAccount->Email() !== $sEmail) {
				$sEmail = \X2Mail\Engine\IDN::emailToAscii($sEmail);
				$aAccounts = $this->GetAccounts($oMainAccount);
				if (!isset($aAccounts[$sEmail])) {
					throw new ClientException(Notifications::AccountDoesNotExist->value);
				}
				try {
					$oAccount = AdditionalAccount::NewInstanceFromTokenArray(
						$this, $aAccounts[$sEmail], true
					);
				} catch (\Throwable $e) {
					throw new ClientException(Notifications::AccountSwitchFailed->value, $e);
				}
				if (!$oAccount) {
					throw new ClientException(Notifications::AccountSwitchFailed->value);
				}

				// Test the login
				$oImapClient = new \X2Mail\Mail\Imap\ImapClient;
				$oImapClient->SetLogger($this->Logger());
				$this->imapConnect($oAccount, false, $oImapClient);
			}
			$this->SetAdditionalAuthToken($oAccount);
			return true;
		}
		return false;
	}

	/**
	 * Returns X2Mail\Engine\Model\AdditionalAccount when it exists,
	 * else returns X2Mail\Engine\Model\MainAccount when it exists,
	 * else null
	 *
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function getAccountFromToken(bool $bThrowExceptionOnFalse = true): ?Account
	{
		$this->getMainAccountFromToken($bThrowExceptionOnFalse);

		if (false === $this->oAdditionalAuthAccount && isset($_COOKIE[self::AUTH_ADDITIONAL_TOKEN_KEY])) {
			$aData = Cookies::getSecure(self::AUTH_ADDITIONAL_TOKEN_KEY);
			if ($aData) {
				$this->oAdditionalAuthAccount = AdditionalAccount::NewInstanceFromTokenArray(
					$this,
					$aData,
					$bThrowExceptionOnFalse
				);
			}
			if (!$this->oAdditionalAuthAccount) {
				$this->oAdditionalAuthAccount = null;
				Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
			}
		}

		return $this->oAdditionalAuthAccount ?: $this->oMainAuthAccount;
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	public function getMainAccountFromToken(bool $bThrowExceptionOnFalse = true): ?MainAccount
	{
		if (false === $this->oMainAuthAccount) try {
			$this->oMainAuthAccount = $this->accountFromNcSession();
			if (!$this->oMainAuthAccount && $bThrowExceptionOnFalse) {
				throw new ClientException(Notifications::InvalidToken->value, null, 'No SSO session');
			}
		} catch (\Throwable $e) {
			if ($bThrowExceptionOnFalse) {
				throw $e;
			}
		}

		return $this->oMainAuthAccount;
	}

	public function SetMainAuthAccount(MainAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = false;
		$this->oMainAuthAccount = $oAccount;
	}

	public function SetAuthToken(MainAccount $oAccount): void
	{
		$this->SetMainAuthAccount($oAccount);
	}

	public function SetAdditionalAuthToken(?AdditionalAccount $oAccount): void
	{
		$this->oAdditionalAuthAccount = $oAccount ?: false;
		Cookies::setSecure(self::AUTH_ADDITIONAL_TOKEN_KEY, $oAccount);
	}

	/**
	 * Logout methods
	 */

	public function Logout(bool $bMain) : void
	{
//		Cookies::clear(Utils::SESSION_TOKEN);
		Cookies::clear(self::AUTH_ADDITIONAL_TOKEN_KEY);
		$bMain && Cookies::clear(self::AUTH_SPEC_TOKEN_KEY);
	}

	/**
	 * @throws \X2Mail\Engine\Exceptions\ClientException
	 */
	protected function imapConnect(Account $oAccount, bool $bAuthLog = false, ?\X2Mail\Mail\Imap\ImapClient $oImapClient = null): void
	{
		try {
			if (!$oImapClient) {
				$oImapClient = $this->ImapClient();
			}
			$oAccount->ImapConnectAndLogin($this->Plugins(), $oImapClient, $this->Config());
		} catch (ClientException $oException) {
			throw $oException;
		} catch (\X2Mail\Mail\Net\Exceptions\ConnectionException $oException) {
			throw new ClientException(Notifications::ConnectionError->value, $oException);
		} catch (\X2Mail\Mail\Imap\Exceptions\LoginBadCredentialsException $oException) {
			if ($bAuthLog) {
				$this->LoggerAuthHelper($oAccount);
			}

			if ($this->Config()->Get('imap', 'show_login_alert', true)) {
				throw new ClientException(Notifications::AuthError->value, $oException, $oException->getAlertFromStatus());
			} else {
				throw new ClientException(Notifications::AuthError->value, $oException);
			}
		} catch (\Throwable $oException) {
			throw new ClientException(Notifications::AuthError->value, $oException);
		}
	}

}

<?php

namespace OCA\opacit_mail\Util;

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;

class EngineHelper
{
    public function __construct(
        private IConfig $config,
        private IAppConfig $appConfig,
        private IUserConfig $userConfig,
        private ISession $session,
        private IUserSession $userSession,
        private IAppManager $appManager,
        private LoggerInterface $logger,
        private IEventDispatcher $eventDispatcher,
        private ICrypto $crypto,
    ) {
    }

    public function loadApp(): void
    {
        if (\class_exists('opacit_mail\\Engine\\Api')) {
            return;
        }

        // opacit_mail namespace autoloader (case-sensitive PSR-4 style)
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'opacit_mail\\')) {
                $file = OPACIT_MAIL_LIBRARIES_PATH . \strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        // Lowercase-filename autoloader for opacit_mail\Engine
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'opacit_mail\\Engine\\')) {
                $file = OPACIT_MAIL_LIBRARIES_PATH . 'opacit_mail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 19), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                $parts = \explode('\\', \substr($sClassName, 19));
                $fileName = \array_pop($parts);
                $dirPath = \implode(DIRECTORY_SEPARATOR, \array_map('strtolower', $parts));
                $file = OPACIT_MAIL_LIBRARIES_PATH . 'opacit_mail/Engine/'
                    . ($dirPath ? $dirPath . DIRECTORY_SEPARATOR : '')
                    . $fileName . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        $_ENV['OPACIT_MAIL_INCLUDE_AS_API'] = true;

        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_opacit_mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('opacit_mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        $oConfig = \opacit_mail\Engine\Api::Config();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \opacit_mail\Engine\Api::Actions();
            $doLogin = !$oActions->getMainAccountFromToken(false);
            $aCredentials = $this->getLoginCredentials();
            if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                $isOIDC = \str_starts_with($aCredentials[2], 'oidc_login|');
                try {
                    $oActions->LoginProcess(
                        $aCredentials[1],
                        new \opacit_mail\Engine\SensitiveString($aCredentials[2])
                    );
                } catch (\opacit_mail\Engine\Exceptions\ClientException $e) {
                    if (!$isOIDC && $e->getCode() !== \opacit_mail\Engine\Notifications::ConnectionError->value) {
                        $sUID = $this->userSession->getUser()->getUID();
                        $this->session->set('opacit_mail-passphrase', '');
                        $this->userConfig->deleteUserConfig($sUID, 'opacit_mail', 'passphrase');
                    }
                    $this->logger->debug('opacit_mail login failed: ' . $e->getMessage());
                } catch (\Throwable $e) {
                    // Non-login errors — don't touch credentials
                    $this->logger->warning('opacit_mail engine login error: ' . $e->getMessage());
                }
            }

            if ($handle) {
                \header_remove('Content-Security-Policy');
                \opacit_mail\Engine\Service::Handle();
                exit;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('opacit_mail engine bootstrap error: ' . $e->getMessage());
        }
    }

    /**
     * Whether the engine currently has an authenticated main account.
     * Call after startApp() — the result is cached by the engine, so this
     * reflects the outcome of the SSO auto-login attempt without side effects.
     */
    public function hasAuthenticatedAccount(): bool
    {
        if (!\class_exists('opacit_mail\\Engine\\Api')) {
            return false;
        }
        try {
            return \opacit_mail\Engine\Api::Actions()->getMainAccountFromToken(false) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Returns the SSO uid stored in the NC session, or null if not set.
     */
    public function getSsoUid(): ?string
    {
        $uid = $this->session->get('opacit_mail-uid');
        return \is_string($uid) && $uid !== '' ? $uid : null;
    }

    /**
     * Returns the current Nextcloud session id, or null if unavailable.
     *
     * Stable per session (changes only on NC session regeneration), it is the
     * per-session secret the engine derives its connection/CSRF token from in
     * place of the former self-set x2mtoken cookie. getId() throws when no
     * session is active (e.g. CLI), so we guard and return null.
     */
    public function getNcSessionId(): ?string
    {
        try {
            $id = $this->session->getId();
        } catch (\Throwable $e) {
            return null;
        }
        return $id !== '' ? $id : null;
    }

    /**
     * Returns the email for the current SSO user, identical to the value
     * FilterAppData seeds into AppData in the nextcloud engine plugin so the
     * NC-session reconstruction matches the live login path. Resolution order:
     *   1. custom opacit_mail email: IUserConfig opacit_mail/email (overrides everything)
     *   2. profile email: IUserConfig settings/email
     *   3. IUser::getEMailAddress() (NC account email)
     *   4. uid itself (last resort — guarantees a non-empty return)
     * Returns null when no SSO uid is present in the session.
     */
    public function getSsoEmail(): ?string
    {
        $uid = $this->getSsoUid();
        if ($uid === null) {
            return null;
        }

        $custom = $this->userConfig->getValueString($uid, 'opacit_mail', 'email', '');
        if ($custom !== '') {
            return $custom;
        }

        $email = $this->userConfig->getValueString($uid, 'settings', 'email', '');
        if ($email !== '') {
            return $email;
        }

        $user = $this->userSession->getUser();
        if ($user !== null && $user->getUID() === $uid) {
            $email = $user->getEMailAddress();
            if ($email !== '' && $email !== null) {
                return $email;
            }
        }

        return $uid;
    }

    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('opacit_mail', 'autologin-oidc', '0') !== '0') {
            if ($this->appManager->isEnabledForUser('oidc_login') || $this->appManager->isEnabledForUser('user_oidc')) {
                if ($this->session->get('is_oidc')) {
                    if ($this->session->get('oidc_access_token')) {
                        return true;
                    }
                    \opacit_mail\Engine\Log::debug('Nextcloud', 'OIDC access_token missing');
                } else {
                    \opacit_mail\Engine\Log::debug('Nextcloud', 'No OIDC login');
                }
            } else {
                \opacit_mail\Engine\Log::debug('Nextcloud', 'OIDC login disabled');
            }
        }
        return false;
    }

    /**
     * Single source for the OIDC access token used for IMAP/SMTP OAUTHBEARER.
     * Order: token exchange (if an audience is configured) -> fresh login token
     * via user_oidc public event -> session value (oidc_login / cached).
     *
     * Pass $audienceOverride (e.g. from the setup wizard Test Login) to exchange
     * for that audience instead of the stored one; null falls back to config.
     */
    public function getOidcAccessToken(?string $audienceOverride = null): ?string
    {
        $audience = $audienceOverride
            ?? $this->appConfig->getValueString('opacit_mail', 'oidc-exchange-audience', '');
        if ($audience !== '') {
            $exchanged = $this->dispatchTokenEvent(
                'OCA\\UserOIDC\\Event\\ExchangedTokenRequestedEvent',
                $audience
            );
            if ($exchanged !== null) {
                return $exchanged;
            }
            $this->logger->warning(
                'OIDC token exchange for audience "' . $audience . '" yielded no token; '
                . 'falling back to the login token'
            );
        }

        $fresh = $this->dispatchTokenEvent('OCA\\UserOIDC\\Event\\ExternalTokenRequestedEvent', null);
        if ($fresh !== null) {
            return $fresh;
        }

        $sessionToken = $this->session->get('oidc_access_token');
        return \is_string($sessionToken) && $sessionToken !== '' ? $sessionToken : null;
    }

    private function dispatchTokenEvent(string $eventClass, ?string $audienceArg): ?string
    {
        if (!\class_exists($eventClass)) {
            return null;
        }
        try {
            $event = $audienceArg === null ? new $eventClass() : new $eventClass($audienceArg);
            if (!$event instanceof Event) {
                return null;
            }
            $this->eventDispatcher->dispatchTyped($event);
            if (!\method_exists($event, 'getToken')) {
                return null;
            }
            $token = $event->getToken();
            if (!\is_object($token) || !\method_exists($token, 'getAccessToken')) {
                return null;
            }
            $access = $token->getAccessToken();
            return \is_string($access) && $access !== '' ? $access : null;
        } catch (\Throwable $e) {
            $this->logger->warning('OIDC token event failed (' . $eventClass . '): ' . $e->getMessage());
            return null;
        }
    }

    /** @return array{string, string, string|null} */
    private function getLoginCredentials(): array
    {
        $sUID = $this->userSession->getUser()->getUID();
        $sEmail = $this->userConfig->getValueString($sUID, 'opacit_mail', 'email');
        $sPassword = $this->userConfig->getValueString($sUID, 'opacit_mail', 'passphrase');
        if ($sEmail && $sPassword) {
            $sPassword = $this->decodePassword($sPassword, \md5($sEmail));
            if ($sPassword) {
                return [$sUID, $sEmail, $sPassword];
            }
        }

        if ($this->session->get('opacit_mail-uid') === $sUID && $this->isOIDCLogin()) {
            $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email');
            return [$sUID, $sEmail, "oidc_login|{$sUID}"];
        }
        if ($this->session->get('opacit_mail-uid') === $sUID) {
            $sEmail = '';
            $sPassword = '';
            $autologin = $this->appConfig->getValueString('opacit_mail', 'autologin', '0') !== '0';
            $autologinEmail = $this->appConfig->getValueString('opacit_mail', 'autologin-with-email', '0') !== '0';
            if ($autologin || $autologinEmail) {
                $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email') ?: $sUID;
                $sPassword = $this->session->get('opacit_mail-passphrase');
            }
            if ($sPassword) {
                return [$sUID, $sEmail, $this->decodePassword($sPassword, $sUID)];
            }
        }
        return [$sUID, '', ''];
    }

    public function encodePassword(string $sPassword, string $sSalt): string
    {
        return $this->crypto->encrypt($sPassword, $sSalt);
    }

    public function decodePassword(string $sPassword, string $sSalt): ?string
    {
        try {
            return $this->crypto->decrypt($sPassword, $sSalt);
        } catch (\Exception $e) {
            return null;
        }
    }
}

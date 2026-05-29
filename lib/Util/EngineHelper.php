<?php

namespace OCA\X2Mail\Util;

use OCP\App\IAppManager;
use OCP\Config\IUserConfig;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUserSession;
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
    ) {
    }

    public function loadApp(): void
    {
        if (\class_exists('X2Mail\\Engine\\Api')) {
            return;
        }

        // X2Mail namespace autoloader (case-sensitive PSR-4 style)
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\')) {
                $file = X2MAIL_LIBRARIES_PATH . \strtr($sClassName, '\\', DIRECTORY_SEPARATOR) . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        // Lowercase-filename autoloader for X2Mail\Engine
        \spl_autoload_register(function ($sClassName) {
            if (\str_starts_with($sClassName, 'X2Mail\\Engine\\')) {
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . \strtolower(\strtr(\substr($sClassName, 14), '\\', DIRECTORY_SEPARATOR))
                    . '.php';
                if (\is_file($file)) {
                    include_once $file;
                    return;
                }
                $parts = \explode('\\', \substr($sClassName, 14));
                $fileName = \array_pop($parts);
                $dirPath = \implode(DIRECTORY_SEPARATOR, \array_map('strtolower', $parts));
                $file = X2MAIL_LIBRARIES_PATH . 'X2Mail/Engine/'
                    . ($dirPath ? $dirPath . DIRECTORY_SEPARATOR : '')
                    . $fileName . '.php';
                if (\is_file($file)) {
                    include_once $file;
                }
            }
        });

        $_ENV['X2MAIL_INCLUDE_AS_API'] = true;

        if (!\defined('APP_DATA_FOLDER_PATH')) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            \define('APP_DATA_FOLDER_PATH', $dataDir . '/appdata_x2mail/');
        }

        $app_dir = \dirname(\dirname(__DIR__)) . '/app';
        $index = $app_dir . '/index.php';
        if (!\is_readable($index)) {
            $this->logger->warning('X2Mail: app/index.php not readable, skipping engine bootstrap');
            return;
        }
        require_once $index;
    }

    public function startApp(bool $handle = false): void
    {
        $this->loadApp();

        $oConfig = \X2Mail\Engine\Api::Config();

        if (false !== \stripos(\php_sapi_name(), 'cli')) {
            return;
        }

        try {
            $oActions = \X2Mail\Engine\Api::Actions();
            $doLogin = !$oActions->getMainAccountFromToken(false);
            $aCredentials = $this->getLoginCredentials();
            if ($doLogin && $aCredentials[1] && $aCredentials[2]) {
                try {
                    $oActions->LoginProcess(
                        $aCredentials[1],
                        new \X2Mail\Engine\SensitiveString($aCredentials[2])
                    );
                } catch (\X2Mail\Engine\Exceptions\ClientException $e) {
                    // OIDC login failure — no credentials to clear
                    $this->logger->debug('X2Mail SSO login failed: ' . $e->getMessage());
                } catch (\Throwable $e) {
                    // Non-login errors — don't touch credentials
                    $this->logger->warning('X2Mail engine login error: ' . $e->getMessage());
                }
            }

            if ($handle) {
                \header_remove('Content-Security-Policy');
                \X2Mail\Engine\Service::Handle();
                exit;
            }
        } catch (\Throwable $e) {
            $this->logger->warning('X2Mail engine bootstrap error: ' . $e->getMessage());
        }
    }

    /**
     * Whether the engine currently has an authenticated main account.
     * Call after startApp() — the result is cached by the engine, so this
     * reflects the outcome of the SSO auto-login attempt without side effects.
     */
    public function hasAuthenticatedAccount(): bool
    {
        if (!\class_exists('X2Mail\\Engine\\Api')) {
            return false;
        }
        try {
            return \X2Mail\Engine\Api::Actions()->getMainAccountFromToken(false) !== null;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isOIDCLogin(): bool
    {
        if ($this->appConfig->getValueString('x2mail', 'autologin-oidc', '0') !== '0') {
            if ($this->appManager->isEnabledForUser('oidc_login') || $this->appManager->isEnabledForUser('user_oidc')) {
                if ($this->session->get('is_oidc')) {
                    if ($this->session->get('oidc_access_token')) {
                        return true;
                    }
                    \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC access_token missing');
                } else {
                    \X2Mail\Engine\Log::debug('Nextcloud', 'No OIDC login');
                }
            } else {
                \X2Mail\Engine\Log::debug('Nextcloud', 'OIDC login disabled');
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
            ?? $this->appConfig->getValueString('x2mail', 'oidc-exchange-audience', '');
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

    /** @return array{string, string, string} */
    private function getLoginCredentials(): array
    {
        $sUID = $this->userSession->getUser()->getUID();
        if ($this->session->get('x2mail-uid') === $sUID && $this->isOIDCLogin()) {
            $sEmail = $this->userConfig->getValueString($sUID, 'settings', 'email');
            return [$sUID, $sEmail, "oidc_login|{$sUID}"];
        }
        return [$sUID, '', ''];
    }
}

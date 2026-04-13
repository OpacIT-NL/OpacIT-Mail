<?php

declare(strict_types=1);

namespace OCA\X2Mail\Controller;

use OCA\X2Mail\Service\ConnectivityCheckService;
use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Config\IUserConfig;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class SetupController extends Controller
{
    private const APP_ID = 'x2mail';
    private const OIDC_PROVIDER_KEY = 'oidc-provider';

    public function __construct(
        string $appName,
        IRequest $request,
        private IAppConfig $appConfig,
        private IAppManager $appManager,
        private DomainConfigService $domainService,
        private ISession $session,
        private IUserSession $userSession,
        private IUserConfig $userConfig,
        private LoggerInterface $logger,
        private EngineHelper $engineHelper,
        private ConnectivityCheckService $connectivityCheckService,
    ) {
        parent::__construct($appName, $request);
    }

    /**
     * Load current setup configuration for the wizard form.
     */
    #[NoCSRFRequired]
    public function getConfig(): JSONResponse
    {
        $domains = $this->domainService->listDomains();
        $domainConfigs = [];

        foreach ($domains as $domain) {
            $raw = $this->domainService->readDomainConfig($domain);
            if (!$raw) {
                continue;
            }

            $imapSasl = $raw['IMAP']['sasl'] ?? [];
            $hasOAuth = \in_array('OAUTHBEARER', $imapSasl) || \in_array('XOAUTH2', $imapSasl);
            $authType = $hasOAuth ? 'oauth' : 'plain';

            $domainConfigs[$domain] = [
                'imap_host' => $raw['IMAP']['host'] ?? '',
                'imap_port' => $raw['IMAP']['port'] ?? 143,
                'imap_ssl' => DomainConfigService::sslToString($raw['IMAP']['type'] ?? 0),
                'smtp_host' => $raw['SMTP']['host'] ?? '',
                'smtp_port' => $raw['SMTP']['port'] ?? 25,
                'smtp_ssl' => DomainConfigService::sslToString($raw['SMTP']['type'] ?? 0),
                'smtp_auth' => $raw['SMTP']['useAuth'] ?? false,
                'auth_type' => $authType,
                'sieve' => $raw['Sieve']['enabled'] ?? false,
            ];
        }

        // OIDC status
        $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
        $oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');
        $oidcAutoLogin = $this->appConfig->getValueString(self::APP_ID, 'autologin-oidc', '0');

        $oidcProvider = $this->resolvePreferredOidcProvider(
            $this->appConfig->getValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, ''),
            $userOidcInstalled,
            $oidcLoginInstalled,
        ) ?? 'none';

        // Suggest domain from admin's email (part after @)
        $suggestedDomain = '';
        $user = $this->userSession->getUser();
        if ($user) {
            $email = $user->getEMailAddress();
            if ($email && \str_contains($email, '@')) {
                $suggestedDomain = \substr($email, \strpos($email, '@') + 1);
            }
        }

        return new JSONResponse([
            'domains' => $domainConfigs,
            'suggested_domain' => $suggestedDomain,
            'single_domain_mode' => true,
            'multiple_domains_detected' => \count($domainConfigs) > 1,
            'domain_count' => \count($domainConfigs),
            'oidc' => [
                'enabled' => $oidcAutoLogin === '1',
                'provider' => $oidcProvider,
                'user_oidc' => $userOidcInstalled,
                'oidc_login' => $oidcLoginInstalled,
            ],
        ]);
    }

    /**
     * Run preflight checks against IMAP/SMTP/OIDC.
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function preflightCheck(
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $smtp_host = '',
        int $smtp_port = 25,
        string $smtp_ssl = 'none',
        bool $smtp_auth = false,
        string $auth_type = 'plain',
        string $oidc_provider = 'user_oidc'
    ): JSONResponse {
        $imapHost = $imap_host;
        $imapPort = $imap_port;
        $imapSsl = $this->normalizeSslMode($imap_ssl);
        $smtpHost = $smtp_host ?: $imap_host;
        $smtpPort = $smtp_port;
        $smtpSsl = $this->normalizeSslMode($smtp_ssl);
        $smtpAuth = $smtp_auth;
        $authType = \strtolower($auth_type);

        if ($imapPort < 1 || $imapPort > 65535) {
            return new JSONResponse(['error' => 'Invalid IMAP port'], 400);
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(['error' => 'Invalid SMTP port'], 400);
        }

        // Validate hostname format — prevent injection of special characters
        if ($imapHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
            return new JSONResponse(['error' => 'Invalid IMAP hostname'], 400);
        }
        if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
            return new JSONResponse(['error' => 'Invalid SMTP hostname'], 400);
        }

        $userOidc = $this->appManager->isEnabledForUser('user_oidc');
        $oidcLogin = $this->appManager->isEnabledForUser('oidc_login');
        $requestedProvider = $this->normalizeOidcProvider($oidc_provider);
        if ($requestedProvider === null) {
            return new JSONResponse(['error' => 'Invalid OIDC provider'], 400);
        }
        $resolvedProvider = $this->resolvePreferredOidcProvider(
            $requestedProvider,
            $userOidc,
            $oidcLogin,
        ) ?? 'none';

        $results = [];

        // IMAP check
        $imapResult = $this->connectivityCheckService->checkImap($imapHost, $imapPort, $imapSsl);
        $results['imap'] = $imapResult;

        // OAuth capability check
        if ($imapResult['connected'] && \in_array($authType, ['oauth', 'oauthbearer', 'xoauth2'])) {
            $hasOAuth = false;
            $authMethods = [];
            foreach ($imapResult['capabilities'] as $cap) {
                if (\str_starts_with($cap, 'AUTH=')) {
                    $method = \substr($cap, 5);
                    $authMethods[] = $method;
                    if ($method === 'OAUTHBEARER' || $method === 'XOAUTH2') {
                        $hasOAuth = true;
                    }
                }
            }
            $results['imap']['oauth_supported'] = $hasOAuth;
            $results['imap']['auth_methods'] = $authMethods;
        }

        // SMTP check
        $results['smtp'] = $this->connectivityCheckService->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
        if ($results['smtp']['connected'] && \in_array($authType, ['oauth', 'oauthbearer', 'xoauth2'], true)) {
            $smtpAuthMethods = $results['smtp']['auth_methods'];
            $smtpHasOAuth = \in_array('OAUTHBEARER', $smtpAuthMethods, true)
                || \in_array('XOAUTH2', $smtpAuthMethods, true);
            $results['smtp']['oauth_supported'] = $smtpHasOAuth;
            if ($smtpAuth) {
                $results['smtp']['auth_required'] = true;
            }
        }

        // OIDC check
        if (\in_array($authType, ['oauth', 'oauthbearer', 'xoauth2'])) {
            $oidcResult = [
                'user_oidc' => $userOidc,
                'oidc_login' => $oidcLogin,
                'any_installed' => $userOidc || $oidcLogin,
                'requested_provider' => $requestedProvider,
                'provider' => $resolvedProvider,
                'provider_fallback' => $requestedProvider !== $resolvedProvider,
            ];

            if ($resolvedProvider === 'user_oidc') {
                $storeToken = $this->appConfig->getValueString('user_oidc', 'store_login_token', '0');
                $oidcResult['store_login_token'] = $storeToken === '1';

                // Check if at least one OIDC provider is configured
                try {
                    $oidcResult['provider_configured'] = $this->appConfig->getValueString(
                        'user_oidc',
                        'provider-1-mappingUid',
                        '',
                        lazy: true
                    ) !== '';
                } catch (\Throwable $e) {
                    $oidcResult['provider_configured'] = null;
                }
            }

            // Session checks (only available in browser, not occ)
            $oidcResult['session_is_oidc'] = (bool) $this->session->get('is_oidc');
            $oidcResult['session_has_token'] = (bool) $this->session->get('oidc_access_token');

            // Decode JWT payload for admin diagnostics (no signature verification needed)
            $accessToken = $this->session->get('oidc_access_token');
            if ($accessToken && \is_string($accessToken)) {
                $parts = \explode('.', $accessToken);
                if (\count($parts) === 3) {
                    $b64 = \strtr($parts[1], '-_', '+/');
                    $b64 .= \str_repeat('=', (4 - \strlen($b64) % 4) % 4);
                    $payload = \json_decode(\base64_decode($b64), true);
                    if ($payload) {
                        $oidcResult['token'] = [
                            'email' => $payload['email'] ?? null,
                            'aud' => $payload['aud'] ?? null,
                            'iss' => $payload['iss'] ?? null,
                            'exp' => $payload['exp'] ?? null,
                            'expires_in' => isset($payload['exp']) ? $payload['exp'] - \time() : null,
                        ];
                    }
                }
            }

            $results['oidc'] = $oidcResult;
        }

        return new JSONResponse($results);
    }

    /**
     * Save setup configuration (create or update domain).
     */
    public function saveSetup(
        string $domain = '',
        string $imap_host = '',
        int $imap_port = 143,
        string $imap_ssl = 'none',
        string $smtp_host = '',
        int $smtp_port = 25,
        string $smtp_ssl = 'none',
        bool $smtp_auth = false,
        string $auth_type = 'plain',
        bool $sieve = false,
        string $oidc_provider = 'user_oidc'
    ): JSONResponse {
        $domain = \trim($domain);
        $imapHost = \trim($imap_host);
        $imapPort = $imap_port;
        $imapSsl = $this->normalizeSslMode($imap_ssl);
        $smtpHost = \trim($smtp_host) ?: $imapHost;
        $smtpPort = $smtp_port;
        $smtpSsl = $this->normalizeSslMode($smtp_ssl);
        $smtpAuth = $smtp_auth;
        $authType = \strtolower($auth_type);
        $requestedProvider = $this->normalizeOidcProvider($oidc_provider);

        // Validation
        if ($domain === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
        }
        if ($imapHost === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'IMAP host is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $imapHost)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP hostname'], 400);
        }
        if ($smtpHost !== '' && !\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $smtpHost)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP hostname'], 400);
        }
        if ($imapPort < 1 || $imapPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP port'], 400);
        }
        if ($smtpPort < 1 || $smtpPort > 65535) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP port'], 400);
        }
        // Normalize legacy auth type values
        if ($authType === 'oauthbearer' || $authType === 'xoauth2') {
            $authType = 'oauth';
        }
        if (!\in_array($authType, ['plain', 'oauth'])) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid auth type'], 400);
        }
        if ($requestedProvider === null) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid OIDC provider'], 400);
        }
        if (!\in_array($imapSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid IMAP SSL mode'], 400);
        }
        if (!\in_array($smtpSsl, ['none', 'ssl', 'starttls'], true)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid SMTP SSL mode'], 400);
        }

        try {
            $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
            $oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');
            $resolvedProvider = $this->resolvePreferredOidcProvider(
                $requestedProvider,
                $userOidcInstalled,
                $oidcLoginInstalled,
            );
            if ($authType === 'oauth' && $resolvedProvider === null) {
                return new JSONResponse(['status' => 'error', 'message' => 'No OIDC provider enabled'], 400);
            }

            $domainConfig = $this->domainService->buildDomainConfig(
                $imapHost,
                $imapPort,
                $imapSsl,
                $smtpHost,
                $smtpPort,
                $smtpSsl,
                $smtpAuth,
                $authType,
                $sieve,
            );
            $this->domainService->writeDomainConfig($domain, $domainConfig);

            $removedDomains = [];
            $cleanupWarnings = [];
            // Single-domain mode: write first, then consolidate old configs.
            foreach ($this->domainService->listDomains() as $existing) {
                if ($existing === $domain) {
                    continue;
                }

                try {
                    $this->domainService->deleteDomainConfig($existing);
                    $removedDomains[] = $existing;
                } catch (\Throwable $cleanupError) {
                    $cleanupWarnings[] = $existing;
                    $this->logger->warning(
                        'X2Mail saveSetup cleanup failed for domain "' . $existing . '": ' . $cleanupError->getMessage()
                    );
                }
            }

            // Set app config for OIDC auto-login
            $isOAuth = $authType === 'oauth';
            $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
            $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', $isOAuth ? '1' : '0');
            if ($resolvedProvider !== null) {
                $this->appConfig->setValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, $resolvedProvider);
            }

            // Ensure store_login_token is set for user_oidc
            if ($isOAuth && $resolvedProvider === 'user_oidc' && $userOidcInstalled) {
                $this->appConfig->setValueString('user_oidc', 'store_login_token', '1');
            }

            // Set engine config for this auth mode
            try {
                $this->engineHelper->loadApp();
                $oConfig = \X2Mail\Engine\Api::Config();
                $oConfig->Set('login', 'default_domain', $domain);
                $oConfig->Set('webmail', 'allow_additional_identities', true);
                $oConfig->Set('imap', 'show_login_alert', false);
                $oConfig->Set('defaults', 'autologout', 15);
                $oConfig->Set('defaults', 'contacts_autosave', false);
                if ($isOAuth) {
                    $oConfig->Set('webmail', 'allow_additional_accounts', false);
                    $oConfig->Set('login', 'sign_me_auto', \X2Mail\Engine\Enumerations\SignMeType::Unused);
                }
                $oConfig->Save();

                // Invalidate stale auth: NC session + engine session + stored credentials
                $this->session->remove('x2mail-passphrase');
                \X2Mail\Engine\Api::Actions()->Logout(true);

                if ($isOAuth) {
                    $this->userConfig->deleteKey('x2mail', 'passphrase');
                    $this->userConfig->deleteKey('x2mail', 'email');
                }
            } catch (\Throwable $e) {
                // Non-fatal
            }

            return new JSONResponse([
                'status' => 'success',
                'message' => $removedDomains === []
                    ? "Domain '{$domain}' saved"
                    : "Domain '{$domain}' saved and replaced " . \count($removedDomains) . ' previous domain(s)',
                'removed_domains' => $removedDomains,
                'cleanup_warnings' => $cleanupWarnings,
                'provider' => $resolvedProvider,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('X2Mail saveSetup failed: ' . $e->getMessage());
            return new JSONResponse(['status' => 'error', 'message' => 'Save failed'], 500);
        }
    }

    /**
     * Delete a domain configuration.
     */
    public function deleteDomain(string $domain = ''): JSONResponse
    {
        $domain = \trim($domain);
        if ($domain === '') {
            return new JSONResponse(['status' => 'error', 'message' => 'Domain is required'], 400);
        }
        if (!\preg_match('/\A[a-zA-Z0-9.\-]+\z/', $domain)) {
            return new JSONResponse(['status' => 'error', 'message' => 'Invalid domain name'], 400);
        }

        try {
            $this->domainService->deleteDomainConfig($domain);
            return new JSONResponse(['status' => 'success', 'message' => "Domain '{$domain}' deleted"]);
        } catch (\Throwable $e) {
            $this->logger->error('X2Mail deleteDomain failed: ' . $e->getMessage());
            return new JSONResponse(['status' => 'error', 'message' => 'Delete failed'], 500);
        }
    }

    private function normalizeSslMode(string $ssl): string
    {
        $ssl = \strtolower(\trim($ssl));
        return $ssl === 'tls' ? 'starttls' : $ssl;
    }

    private function normalizeOidcProvider(string $provider): ?string
    {
        $provider = \strtolower(\trim($provider));
        return \in_array($provider, ['user_oidc', 'oidc_login'], true) ? $provider : null;
    }

    private function resolvePreferredOidcProvider(
        string $provider,
        bool $userOidcInstalled,
        bool $oidcLoginInstalled,
    ): ?string {
        $normalized = $this->normalizeOidcProvider($provider);
        if ($normalized === 'user_oidc' && $userOidcInstalled) {
            return $normalized;
        }
        if ($normalized === 'oidc_login' && $oidcLoginInstalled) {
            return $normalized;
        }

        if ($userOidcInstalled) {
            return 'user_oidc';
        }
        if ($oidcLoginInstalled) {
            return 'oidc_login';
        }

        return null;
    }
}

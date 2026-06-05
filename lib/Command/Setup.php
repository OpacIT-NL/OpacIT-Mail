<?php

declare(strict_types=1);

namespace OCA\X2Mail\Command;

use OCA\X2Mail\Service\ConnectivityCheckService;
use OCA\X2Mail\Service\DomainConfigService;
use OCA\X2Mail\Util\EngineHelper;
use Symfony\Component\Console\Command\Command;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Setup extends Command
{
    private const APP_ID = 'x2mail';
    private const OIDC_PROVIDER_KEY = 'oidc-provider';

    public function __construct(
        private IAppConfig $appConfig,
        private DomainConfigService $domainService,
        private IAppManager $appManager,
        private EngineHelper $engineHelper,
        private ConnectivityCheckService $connectivityCheckService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('x2mail:setup')
            ->setDescription('Configure X2Mail mail server connection and authentication')
            ->addOption('imap-host', null, InputOption::VALUE_REQUIRED, 'IMAP server hostname')
            ->addOption('imap-port', null, InputOption::VALUE_REQUIRED, 'IMAP server port', '143')
            ->addOption(
                'imap-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'IMAP SSL mode (none, ssl, tls/starttls)',
                'none'
            )
            ->addOption('smtp-host', null, InputOption::VALUE_REQUIRED, 'SMTP server hostname (defaults to imap-host)')
            ->addOption('smtp-port', null, InputOption::VALUE_REQUIRED, 'SMTP server port', '25')
            ->addOption(
                'smtp-ssl',
                null,
                InputOption::VALUE_REQUIRED,
                'SMTP SSL mode (none, ssl, tls/starttls)',
                'none'
            )
            ->addOption('smtp-auth', null, InputOption::VALUE_NONE, 'Require SMTP authentication')
            ->addOption('domain', null, InputOption::VALUE_REQUIRED, 'Mail domain (e.g. example.com)')
            ->addOption(
                'auth',
                null,
                InputOption::VALUE_REQUIRED,
                'Auth type: oauth (default/SSO) or plain (legacy)',
                'oauth'
            )
            ->addOption(
                'oidc-provider',
                null,
                InputOption::VALUE_REQUIRED,
                'OIDC provider app (user_oidc, oidc_login)',
                'user_oidc'
            )
            ->addOption('sieve', null, InputOption::VALUE_NONE, 'Enable Sieve filtering support')
            ->addOption('skip-checks', null, InputOption::VALUE_NONE, 'Skip connectivity and capability checks')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $imapHost = $input->getOption('imap-host');
        $domain = $input->getOption('domain');

        if (!$imapHost) {
            $output->writeln('<error>--imap-host is required</error>');
            return 1;
        }
        if (!$domain) {
            $output->writeln('<error>--domain is required</error>');
            return 1;
        }

        $imapPort = (int) $input->getOption('imap-port');
        $imapSsl = $this->normalizeSslMode((string) $input->getOption('imap-ssl'));
        $smtpHost = $input->getOption('smtp-host') ?: $imapHost;
        $smtpPort = (int) $input->getOption('smtp-port');
        $smtpSsl = $this->normalizeSslMode((string) $input->getOption('smtp-ssl'));
        $smtpAuth = $input->getOption('smtp-auth');
        $authType = \strtolower($input->getOption('auth'));
        $requestedOidcProvider = $this->normalizeOidcProvider((string) $input->getOption('oidc-provider'));
        $sieve = $input->getOption('sieve');
        $skipChecks = $input->getOption('skip-checks');

        // Normalize legacy auth values
        if ($authType === 'oauthbearer' || $authType === 'xoauth2') {
            $authType = 'oauth';
        }
        if (!\in_array($authType, ['plain', 'oauth'])) {
            $output->writeln('<error>Invalid --auth. Must be: oauth or plain</error>');
            return 1;
        }
        if ($requestedOidcProvider === null) {
            $output->writeln('<error>Invalid --oidc-provider. Must be: user_oidc or oidc_login</error>');
            return 1;
        }
        foreach (['imap-ssl' => $imapSsl, 'smtp-ssl' => $smtpSsl] as $name => $val) {
            if (!\in_array($val, ['none', 'ssl', 'starttls'], true)) {
                $output->writeln("<error>Invalid --{$name}. Must be: none, ssl, tls/starttls</error>");
                return 1;
            }
        }

        $isOAuth = $authType === 'oauth';
        $errors = 0;
        $userOidcInstalled = $this->appManager->isEnabledForUser('user_oidc');
        $oidcLoginInstalled = $this->appManager->isEnabledForUser('oidc_login');
        $oidcProvider = $this->resolvePreferredOidcProvider(
            $requestedOidcProvider,
            $userOidcInstalled,
            $oidcLoginInstalled,
        );

        // ═══════════════════════════════════════════
        // PREFLIGHT CHECKS
        // ═══════════════════════════════════════════
        $output->writeln('<info>Preflight Checks</info>');
        $output->writeln('');

        // 1. IMAP
        if (!$skipChecks) {
            $imapResult = $this->connectivityCheckService->checkImap($imapHost, $imapPort, $imapSsl);
            if ($imapResult['connected']) {
                $authMethods = [];
                $hasOAuth = false;
                foreach ($imapResult['capabilities'] as $cap) {
                    if (\str_starts_with($cap, 'AUTH=')) {
                        $method = \substr($cap, 5);
                        $authMethods[] = $method;
                        if ($method === 'OAUTHBEARER' || $method === 'XOAUTH2') {
                            $hasOAuth = true;
                        }
                    }
                }
                $authStr = $authMethods ? \implode(', ', $authMethods) : 'no AUTH capability advertised';
                $output->writeln("  <info>✓ IMAP  {$imapHost}:{$imapPort} ({$authStr})</info>");

                if ($isOAuth && !$hasOAuth) {
                    $output->writeln(
                        '  <error>✗ IMAP server does not support OAUTHBEARER/XOAUTH2</error>'
                    );
                    $output->writeln(
                        '    Dovecot: https://doc.dovecot.org/configuration_manual/authentication/oauth2/'
                    );
                    $errors++;
                }
                if ($imapResult['tls_warning'] !== '') {
                    $output->writeln('  <comment>  ↳ ' . $imapResult['tls_warning'] . '</comment>');
                }
                if (\in_array('STARTTLS', $imapResult['capabilities']) && $imapSsl === 'none') {
                    $output->writeln('  <comment>  ↳ STARTTLS available — consider --imap-ssl tls</comment>');
                }
            } else {
                $output->writeln("  <error>✗ IMAP  {$imapHost}:{$imapPort} — {$imapResult['error']}</error>");
                $errors++;
            }

            // 2. SMTP
            $smtpResult = $this->connectivityCheckService->checkSmtp($smtpHost, $smtpPort, $smtpSsl);
            if ($smtpResult['connected']) {
                $banner = $smtpResult['banner']
                    ? \preg_replace('/^220\s*/', '', $smtpResult['banner'])
                    : '';
                $smtpLabel = "  <info>✓ SMTP  {$smtpHost}:{$smtpPort}"
                    . ($banner ? " ({$banner})" : '') . '</info>';
                $output->writeln($smtpLabel);
                if ($isOAuth && $smtpAuth) {
                    $smtpHasOAuth = \in_array('OAUTHBEARER', $smtpResult['auth_methods'], true)
                        || \in_array('XOAUTH2', $smtpResult['auth_methods'], true);
                    if (!$smtpHasOAuth) {
                        $output->writeln(
                            '  <error>✗ SMTP server does not support OAUTHBEARER/XOAUTH2'
                            . ' for authenticated sending</error>'
                        );
                        $errors++;
                    }
                }
                if ($smtpResult['starttls_supported'] && $smtpSsl === 'none') {
                    $output->writeln('  <comment>  ↳ STARTTLS available — consider --smtp-ssl tls</comment>');
                }
                if ($smtpResult['tls_warning'] !== '') {
                    $output->writeln('  <comment>  ↳ ' . $smtpResult['tls_warning'] . '</comment>');
                }
            } else {
                $output->writeln("  <error>✗ SMTP  {$smtpHost}:{$smtpPort} — {$smtpResult['error']}</error>");
                $errors++;
            }
        } else {
            $output->writeln('  <comment>⊘ IMAP/SMTP checks skipped (--skip-checks)</comment>');
        }

        // 3. OIDC
        if ($isOAuth) {
            if ($oidcProvider === null) {
                $output->writeln('  <error>✗ OIDC  No provider installed (need user_oidc or oidc_login)</error>');
                $output->writeln('    → occ app:install user_oidc');
                return 1;
            }

            if ($requestedOidcProvider !== $oidcProvider) {
                $output->writeln(
                    "  <comment>↳ Requested {$requestedOidcProvider}, using {$oidcProvider}"
                    . ' because only that provider is enabled</comment>'
                );
            }

            $oidcInfo = $oidcProvider;
            if ($oidcProvider === 'user_oidc') {
                $storeToken = $this->appConfig->getValueString('user_oidc', 'store_login_token', '0');
                if ($storeToken !== '1') {
                    $this->appConfig->setValueString('user_oidc', 'store_login_token', '1');
                    $oidcInfo .= ', store_login_token=1 (set)';
                } else {
                    $oidcInfo .= ', store_login_token=1';
                }
            }
            $output->writeln("  <info>✓ OIDC  {$oidcInfo}</info>");
        } else {
            $output->writeln('  <comment>⊘ OIDC  skipped (plain auth)</comment>');
        }

        $output->writeln('');

        if ($errors > 0 && !$skipChecks) {
            $output->writeln("<error>{$errors} check(s) failed. Fix issues above or use --skip-checks.</error>");
            return 1;
        }

        // ═══════════════════════════════════════════
        // CONFIGURATION
        // ═══════════════════════════════════════════
        $output->writeln('<info>Applying Configuration</info>');
        $output->writeln('');

        // Write domain config
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
        $output->writeln("  Domain config: <comment>{$domain}</comment>");
        $removedDomains = [];
        $cleanupWarnings = [];
        foreach ($this->domainService->listDomains() as $existing) {
            if ($existing !== $domain) {
                try {
                    $this->domainService->deleteDomainConfig($existing);
                    $removedDomains[] = $existing;
                } catch (\Throwable $cleanupError) {
                    $cleanupWarnings[] = $existing;
                    $output->writeln(
                        '  <comment>Cleanup skipped for ' . $existing . ': '
                        . $cleanupError->getMessage() . '</comment>'
                    );
                }
            }
        }
        if ($removedDomains !== []) {
            $output->writeln(
                '  Consolidated single-domain config: <comment>'
                . \implode(', ', $removedDomains) . '</comment> removed'
            );
        }
        if ($cleanupWarnings !== []) {
            $output->writeln(
                '  <comment>Some previous domains could not be removed: '
                . \implode(', ', $cleanupWarnings) . '</comment>'
            );
        }

        // NC app config
        $this->appConfig->setValueString(self::APP_ID, 'autologin', '1');
        if ($isOAuth) {
            $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', '1');
        } else {
            $this->appConfig->setValueString(self::APP_ID, 'autologin-oidc', '0');
        }
        if ($oidcProvider !== null) {
            $this->appConfig->setValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, $oidcProvider);
        }

        // Engine config (app_path, default_domain)
        $appDir = \dirname(\dirname(__DIR__)) . '/app';
        if (\is_dir($appDir)) {
            try {
                $this->engineHelper->loadApp();
                $oConfig = \X2Mail\Engine\Api::Config();
                $webPath = $this->appManager->getAppWebPath(self::APP_ID);
                $appPath = \preg_replace(
                    '#(?<!:)/+#',
                    '/',
                    \rtrim($webPath, '/') . '/app/'
                );
                // Only set domain + app_path — all other defaults handled by InstallStep
                $oConfig->Set('webmail', 'app_path', $appPath);
                $oConfig->Set('login', 'default_domain', $domain);
                $oConfig->Save();
                $output->writeln("  Engine app_path: <comment>{$appPath}</comment>");
            } catch (\Throwable $e) {
                $output->writeln('  <comment>Engine config skipped: ' . $e->getMessage() . '</comment>');
            }
        }

        // ═══════════════════════════════════════════
        // SUMMARY
        // ═══════════════════════════════════════════
        $output->writeln('');
        $output->writeln('<info>Setup complete!</info>');
        $output->writeln('');
        $output->writeln('  Domain:    ' . $domain);
        $output->writeln('  IMAP:      ' . $imapHost . ':' . $imapPort . ' (' . \strtoupper($imapSsl) . ')');
        $output->writeln('  SMTP:      ' . $smtpHost . ':' . $smtpPort . ' (' . \strtoupper($smtpSsl) . ')');
        $output->writeln('  Auth:      ' . $authType);
        $output->writeln('  Sieve:     ' . ($sieve ? 'enabled' : 'disabled'));
        if ($isOAuth) {
            $output->writeln('  OIDC:      ' . $oidcProvider);
        }

        if ($isOAuth) {
            $output->writeln('');
            $output->writeln('<comment>Mail server requirements (your responsibility):</comment>');
            $output->writeln('  1. IMAP server must support OAUTHBEARER or XOAUTH2 SASL mechanism');
            if ($smtpAuth) {
                $output->writeln(
                    '  2. SMTP server must support OAUTHBEARER or XOAUTH2 when --smtp-auth is enabled'
                );
                $output->writeln(
                    '  3. IMAP/SMTP server must validate tokens against your OIDC provider (e.g. Keycloak)'
                );
                $output->writeln('     → Dovecot/Postfix: configure oauth2 validation or token introspection');
                $output->writeln('  4. OIDC provider must include correct audience in access tokens');
                $output->writeln('     → Keycloak: add audience mapper to the Nextcloud client');
                $output->writeln('  5. IMAP username must match the email claim in the OIDC token');
            } else {
                $output->writeln('  2. IMAP server must validate tokens against your OIDC provider (e.g. Keycloak)');
                $output->writeln('     → Dovecot: configure oauth2 passdb with introspection endpoint');
                $output->writeln('  3. OIDC provider must include correct audience in access tokens');
                $output->writeln('     → Keycloak: add audience mapper to the Nextcloud client');
                $output->writeln('  4. IMAP username must match the email claim in the OIDC token');
            }
        }

        return 0;
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
        if ($provider === 'user_oidc' && $userOidcInstalled) {
            return $provider;
        }
        if ($provider === 'oidc_login' && $oidcLoginInstalled) {
            return $provider;
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

<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Command;

use OCA\opacit_mail\Service\DomainConfigService;
use OCA\opacit_mail\Service\LogService;
use OCA\opacit_mail\Util\EngineHelper;
use OCA\opacit_mail\Util\SetupResolvers;
use Symfony\Component\Console\Command\Command;
use OCP\App\IAppManager;
use OCP\IAppConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Status extends Command
{
    use SetupResolvers;

    private const APP_ID = 'opacit_mail';
    private const OIDC_PROVIDER_KEY = 'oidc-provider';

    private IAppConfig $appConfig;
    private DomainConfigService $domainService;
    private IAppManager $appManager;
    private LogService $logService;
    private EngineHelper $engineHelper;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('opacit_mail:status')
            ->setDescription('Show opacit_mail configuration status')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initServices();

        $output->writeln('<info>opacit_mail Status</info>');
        $output->writeln('');

        // Domains
        $domains = $this->domainService->listDomains();
        $output->writeln('<comment>Configured Domains:</comment>');
        if (empty($domains)) {
            $output->writeln('  (none)');
        } else {
            foreach ($domains as $domain) {
                $config = $this->domainService->readDomainConfig($domain);
                if ($config) {
                    $imapHost = $config['IMAP']['host'] ?? '?';
                    $imapPort = $config['IMAP']['port'] ?? '?';
                    $imapType = $config['IMAP']['type'] ?? 0;
                    $imapSsl = \is_int($imapType) ? DomainConfigService::sslToString($imapType) : 'custom';
                    $smtpHost = $config['SMTP']['host'] ?? '?';
                    $smtpPort = $config['SMTP']['port'] ?? '?';
                    $smtpType = $config['SMTP']['type'] ?? 0;
                    $smtpSsl = \is_int($smtpType) ? DomainConfigService::sslToString($smtpType) : 'custom';
                    $sasl = $config['IMAP']['sasl'] ?? [];
                    $hasOAuth = \in_array('OAUTHBEARER', $sasl) || \in_array('XOAUTH2', $sasl);
                    $authMode = $hasOAuth ? 'OAUTHBEARER/XOAUTH2' : 'unknown';
                    $sieve = ($config['Sieve']['enabled'] ?? false) ? 'yes' : 'no';
                    $output->writeln("  {$domain}");
                    $output->writeln("    IMAP: {$imapHost}:{$imapPort} ({$imapSsl})");
                    $output->writeln("    SMTP: {$smtpHost}:{$smtpPort} ({$smtpSsl})");
                    $output->writeln("    Auth: {$authMode}");
                    $output->writeln("    Sieve: {$sieve}");
                } else {
                    $output->writeln("  {$domain} (config unreadable)");
                }
            }
            if (\count($domains) > 1) {
                $output->writeln('');
                $output->writeln(
                    '  <comment>Warning: release branch uses one active domain.'
                    . ' Re-run occ opacit_mail:setup or save in the setup wizard to consolidate.</comment>'
                );
            }
        }

        $output->writeln('');

        // SSO/OIDC status
        $output->writeln('<comment>SSO Configuration:</comment>');
        $oidcEnabled = $this->appConfig->getValueString(self::APP_ID, 'autologin-oidc', '0') === '1';
        $autologin = $this->appConfig->getValueString(self::APP_ID, 'autologin', '0') === '1';

        $userOidc = $this->appManager->isEnabledForUser('user_oidc');
        $oidcLogin = $this->appManager->isEnabledForUser('oidc_login');
        $configuredProvider = $this->normalizeOidcProvider(
            $this->appConfig->getValueString(self::APP_ID, self::OIDC_PROVIDER_KEY, '')
        );
        $provider = $this->resolvePreferredOidcProvider($configuredProvider, $userOidc, $oidcLogin) ?? 'none';

        $output->writeln('  OIDC auto-login: ' . ($oidcEnabled ? '<info>enabled</info>' : 'disabled'));
        $output->writeln('  Autologin:       ' . ($autologin ? '<info>enabled</info>' : 'disabled'));
        $providerLabel = match (true) {
            $provider === 'none' => '<error>none installed</error>',
            $configuredProvider !== null && $configuredProvider !== $provider =>
                "<comment>{$provider} (fallback from {$configuredProvider})</comment>",
            default => "<info>{$provider}</info>",
        };
        $output->writeln('  Provider:        ' . $providerLabel);

        if ($provider === 'user_oidc' && $userOidc) {
            $storeToken = $this->appConfig->getValueString(
                'user_oidc',
                'store_login_token',
                '0'
            );
            $tokenLabel = $storeToken === '1'
                ? '<info>enabled</info>'
                : '<error>disabled (set store_login_token=1)</error>';
            $output->writeln('  Token store:     ' . $tokenLabel);

            try {
                $hasProvider = $this->appConfig->getValueString(
                    'user_oidc',
                    'provider-1-mappingUid',
                    '',
                    lazy: true
                ) !== '';
                $providerStatus = $hasProvider
                    ? '<info>configured</info>'
                    : '<comment>not detected (occ user_oidc:provider)</comment>';
                $output->writeln('  OIDC provider:   ' . $providerStatus);
            } catch (\Throwable $e) {
                // Cannot check — skip
            }
        }

        $output->writeln('');
        $output->writeln(
            '  <comment>Token diagnostics only available in browser wizard'
            . ' (Admin → opacit_mail → Run Checks)</comment>'
        );

        $output->writeln('');

        // Engine version and app_path
        $output->writeln('<comment>opacit_mail Engine:</comment>');
        $appDir = \dirname(\dirname(__DIR__)) . '/app';
        if (\is_dir($appDir)) {
            try {
                $this->engineHelper->loadApp();
                $output->writeln('  Version: ' . APP_VERSION);
                $appPath = \opacit_mail\Engine\Api::Config()->Get('webmail', 'app_path', '(not set)');
                $output->writeln('  app_path: ' . $appPath);
            } catch (\Throwable $e) {
                $output->writeln('  <error>Failed to load engine: ' . $e->getMessage() . '</error>');
            }
        } else {
            $output->writeln('  <comment>Engine not present at app/</comment>');
        }

        $output->writeln('');

        // Debug log status
        $output->writeln('<comment>Debug Log:</comment>');
        $debugEnabled = $this->logService->isEnabled();
        $output->writeln('  Status: ' . ($debugEnabled ? '<info>enabled</info>' : 'disabled'));
        $output->writeln('  File: ' . $this->domainService->getDataPath() . '/opacit_mail.log');
        $output->writeln('  Toggle: occ config:app:set opacit_mail debug_log --value=1|0');

        $output->writeln('');
        $output->writeln('  Data path: ' . $this->domainService->getDataPath());

        return 0;
    }

    private function initServices(): void
    {
        $this->appConfig = \OCP\Server::get(IAppConfig::class);
        $this->domainService = \OCP\Server::get(DomainConfigService::class);
        $this->appManager = \OCP\Server::get(IAppManager::class);
        $this->logService = \OCP\Server::get(LogService::class);
        $this->engineHelper = \OCP\Server::get(EngineHelper::class);
    }
}

<?php

declare(strict_types=1);

namespace OCA\X2Mail\Service;

use OCA\X2Mail\Util\EngineHelper;

class ConnectivityCheckService
{
    public function __construct(
        private ?EngineHelper $engineHelper = null,
    ) {
    }

    /**
     * @return array{connected: bool, capabilities: list<string>, error: string,
     *               starttls_supported: bool, tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    public function checkImap(string $host, int $port, string $ssl): array
    {
        if ($host === '') {
            return [
                'connected' => false,
                'capabilities' => [],
                'error' => 'No host specified',
                'starttls_supported' => false,
                'tls_active' => false,
                'tls_verified' => false,
                'tls_warning' => '',
            ];
        }

        $mode = \strtolower($ssl);
        $strictResult = $this->runImapCheck($host, $port, $mode, $this->buildTlsContextOptions($host, false));
        if ($strictResult['connected'] || $mode === 'none') {
            return $strictResult;
        }

        $relaxedResult = $this->runImapCheck($host, $port, $mode, $this->buildTlsContextOptions($host, true));
        if ($relaxedResult['connected']) {
            $relaxedResult['tls_verified'] = false;
            $relaxedResult['tls_warning'] = 'TLS verification failed with current X2Mail SSL settings;'
                . ' diagnostics retried with relaxed certificate checks.'
                . ($strictResult['error'] !== '' ? ' Strict check: ' . $strictResult['error'] : '');
            $relaxedResult['error'] = '';
            return $relaxedResult;
        }

        return $strictResult;
    }

    /**
     * @return array{connected: bool, banner: string, capabilities: list<string>,
     *               auth_methods: list<string>, error: string, starttls_supported: bool,
     *               tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    public function checkSmtp(string $host, int $port, string $ssl): array
    {
        if ($host === '') {
            return [
                'connected' => false,
                'banner' => '',
                'capabilities' => [],
                'auth_methods' => [],
                'error' => 'No host specified',
                'starttls_supported' => false,
                'tls_active' => false,
                'tls_verified' => false,
                'tls_warning' => '',
            ];
        }

        $mode = \strtolower($ssl);
        $strictResult = $this->runSmtpCheck($host, $port, $mode, $this->buildTlsContextOptions($host, false));
        if ($strictResult['connected'] || $mode === 'none') {
            return $strictResult;
        }

        $relaxedResult = $this->runSmtpCheck($host, $port, $mode, $this->buildTlsContextOptions($host, true));
        if ($relaxedResult['connected']) {
            $relaxedResult['tls_verified'] = false;
            $relaxedResult['tls_warning'] = 'TLS verification failed with current X2Mail SSL settings;'
                . ' diagnostics retried with relaxed certificate checks.'
                . ($strictResult['error'] !== '' ? ' Strict check: ' . $strictResult['error'] : '');
            $relaxedResult['error'] = '';
            return $relaxedResult;
        }

        return $strictResult;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return resource
     */
    private function openSocket(string $host, int $port, bool $implicitTls, array $tlsOptions)
    {
        $errno = 0;
        $errstr = '';
        $context = \stream_context_create(['ssl' => $tlsOptions]);

        $scheme = $implicitTls ? 'ssl://' : 'tcp://';
        [$fp, $warning] = $this->captureWarnings(
            static fn () => \stream_socket_client(
                $scheme . $host . ':' . $port,
                $errno,
                $errstr,
                10,
                \STREAM_CLIENT_CONNECT,
                $context
            )
        );

        if (!\is_resource($fp)) {
            throw new \RuntimeException($warning ?? "Connection failed (errno={$errno})");
        }

        \stream_set_timeout($fp, 10);

        return $fp;
    }

    /**
     * @param resource $fp
     */
    private function readLine($fp): ?string
    {
        $line = \fgets($fp, 4096);
        return $line === false ? null : $line;
    }

    /**
     * @return list<string>
     */
    private function extractImapCapabilities(string $banner): array
    {
        if (\preg_match('/\[CAPABILITY\s+([^\]]+)\]/', $banner, $matches) !== 1) {
            return [];
        }

        return \array_values(\array_filter(\explode(' ', \trim($matches[1]))));
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function fetchImapCapabilities($fp, string $tag): array
    {
        \fwrite($fp, $tag . " CAPABILITY\r\n");
        $response = $this->readImapTaggedResponse($fp, $tag);
        foreach ($response as $line) {
            if (\str_starts_with($line, '* CAPABILITY ')) {
                return \array_values(\array_filter(\explode(' ', \trim(\substr($line, 13)))));
            }
        }

        return [];
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function readImapTaggedResponse($fp, string $tag): array
    {
        $lines = [];
        $tries = 0;

        while ($tries++ < 20) {
            $line = $this->readLine($fp);
            if ($line === null) {
                break;
            }

            $lines[] = $line;
            if (\str_starts_with($line, $tag . ' ')) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $response
     */
    private function imapResponseIsOk(array $response, string $tag): bool
    {
        foreach ($response as $line) {
            if (\preg_match('/^' . \preg_quote($tag, '/') . '\s+OK\b/i', $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param resource $fp
     * @return list<string>
     */
    private function readSmtpResponse($fp): array
    {
        $lines = [];
        $tries = 0;

        while ($tries++ < 20) {
            $line = $this->readLine($fp);
            if ($line === null) {
                break;
            }

            $lines[] = $line;
            if (\preg_match('/^\d{3}\s/', $line) === 1) {
                break;
            }
        }

        return $lines;
    }

    /**
     * @param list<string> $response
     */
    private function smtpResponseCode(array $response): ?int
    {
        if ($response === []) {
            return null;
        }

        if (\preg_match('/^(\d{3})[-\s]/', $response[0], $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    /**
     * @param resource $fp
     * @return array{capabilities: list<string>, auth_methods: list<string>}
     */
    private function smtpEhlo($fp): array
    {
        \fwrite($fp, "EHLO x2mail.local\r\n");
        $response = $this->readSmtpResponse($fp);
        if ($this->smtpResponseCode($response) !== 250) {
            throw new \RuntimeException('EHLO rejected by server');
        }

        $capabilities = [];
        $authMethods = [];
        foreach ($response as $line) {
            if (\preg_match('/^250[-\s](.+)$/', \trim($line), $matches) === 1) {
                $capability = \trim($matches[1]);
                if ($capability !== '') {
                    $parts = \preg_split('/\s+/', $capability);
                    if ($parts !== false) {
                        $name = \strtoupper($parts[0]);
                        $capabilities[] = $name;
                        if ($name === 'AUTH' && \count($parts) > 1) {
                            foreach (\array_slice($parts, 1) as $method) {
                                $authMethods[] = \strtoupper($method);
                            }
                        }
                    }
                }
            }
        }

        return [
            'capabilities' => \array_values(\array_unique($capabilities)),
            'auth_methods' => \array_values(\array_unique($authMethods)),
        ];
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{connected: bool, capabilities: list<string>, error: string,
     *               starttls_supported: bool, tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    private function runImapCheck(string $host, int $port, string $mode, array $tlsOptions): array
    {
        $result = [
            'connected' => false,
            'capabilities' => [],
            'error' => '',
            'starttls_supported' => false,
            'tls_active' => false,
            'tls_verified' => false,
            'tls_warning' => '',
        ];

        try {
            $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);
            $banner = $this->readLine($fp);
            if ($banner === null) {
                \fclose($fp);
                $result['error'] = 'No response from server';
                return $result;
            }

            $capabilities = $this->extractImapCapabilities($banner);
            if ($capabilities === []) {
                $capabilities = $this->fetchImapCapabilities($fp, 'A001');
            }

            $starttlsSupported = \in_array('STARTTLS', $capabilities, true);
            $result['starttls_supported'] = $starttlsSupported;

            if ($mode === 'starttls' || $mode === 'tls') {
                if (!$starttlsSupported) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS not advertised by server';
                    return $result;
                }

                \fwrite($fp, "A002 STARTTLS\r\n");
                $startTlsResponse = $this->readImapTaggedResponse($fp, 'A002');
                if (!$this->imapResponseIsOk($startTlsResponse, 'A002')) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS rejected by server';
                    return $result;
                }

                $this->enableTls($fp);
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
                $capabilities = $this->fetchImapCapabilities($fp, 'A003');
            } elseif ($mode === 'ssl') {
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
            }

            $result['connected'] = true;
            $result['capabilities'] = $capabilities;

            \fwrite($fp, "A004 LOGOUT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     * @return array{connected: bool, banner: string, capabilities: list<string>,
     *               auth_methods: list<string>, error: string, starttls_supported: bool,
     *               tls_active: bool, tls_verified: bool, tls_warning: string}
     */
    private function runSmtpCheck(string $host, int $port, string $mode, array $tlsOptions): array
    {
        $result = [
            'connected' => false,
            'banner' => '',
            'capabilities' => [],
            'auth_methods' => [],
            'error' => '',
            'starttls_supported' => false,
            'tls_active' => false,
            'tls_verified' => false,
            'tls_warning' => '',
        ];

        try {
            $fp = $this->openSocket($host, $port, $mode === 'ssl', $tlsOptions);
            $bannerLines = $this->readSmtpResponse($fp);
            $banner = \trim($bannerLines[0] ?? '');
            if ($banner === '') {
                \fclose($fp);
                $result['error'] = 'No response from server';
                return $result;
            }

            $result['banner'] = $banner;
            $ehlo = $this->smtpEhlo($fp);
            $result['capabilities'] = $ehlo['capabilities'];
            $result['auth_methods'] = $ehlo['auth_methods'];
            $result['starttls_supported'] = \in_array('STARTTLS', $ehlo['capabilities'], true);

            if ($mode === 'starttls' || $mode === 'tls') {
                if (!$result['starttls_supported']) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS not advertised by server';
                    return $result;
                }

                \fwrite($fp, "STARTTLS\r\n");
                $startTlsResponse = $this->readSmtpResponse($fp);
                if ($this->smtpResponseCode($startTlsResponse) !== 220) {
                    \fclose($fp);
                    $result['error'] = 'STARTTLS rejected by server';
                    return $result;
                }

                $this->enableTls($fp);
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
                $ehlo = $this->smtpEhlo($fp);
                $result['capabilities'] = $ehlo['capabilities'];
                $result['auth_methods'] = $ehlo['auth_methods'];
            } elseif ($mode === 'ssl') {
                $result['tls_active'] = true;
                $result['tls_verified'] = $this->isTlsVerificationEnabled($tlsOptions);
            }

            $result['connected'] = true;
            \fwrite($fp, "QUIT\r\n");
            \fclose($fp);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @param resource $fp
     */
    private function enableTls($fp): void
    {
        [$enabled, $warning] = $this->captureWarnings(
            static fn () => \stream_socket_enable_crypto($fp, true, \STREAM_CRYPTO_METHOD_TLS_CLIENT)
        );

        if ($enabled !== true) {
            throw new \RuntimeException($warning ?? 'STARTTLS negotiation failed');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTlsContextOptions(string $host, bool $relaxed): array
    {
        $defaults = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'disable_compression' => true,
            'security_level' => 1,
        ];

        try {
            if ($this->engineHelper !== null) {
                $this->engineHelper->loadApp();
            }

            if (\class_exists('\\X2Mail\\Engine\\Api')) {
                $oConfig = \X2Mail\Engine\Api::Config();
                $defaults['verify_peer'] = (bool) $oConfig->Get('ssl', 'verify_certificate', true);
                $defaults['verify_peer_name'] = (bool) $oConfig->Get('ssl', 'verify_certificate', true);
                $defaults['allow_self_signed'] = (bool) $oConfig->Get('ssl', 'allow_self_signed', false);
                $defaults['disable_compression'] = (bool) $oConfig->Get('ssl', 'disable_compression', true);
                $defaults['security_level'] = (int) $oConfig->Get('ssl', 'security_level', 1);

                $cafile = \trim((string) $oConfig->Get('ssl', 'cafile', ''));
                if ($cafile !== '') {
                    $defaults['cafile'] = $cafile;
                }

                $capath = \trim((string) $oConfig->Get('ssl', 'capath', ''));
                if ($capath !== '') {
                    $defaults['capath'] = $capath;
                }

                $localCert = \trim((string) $oConfig->Get('ssl', 'local_cert', ''));
                if ($localCert !== '') {
                    $defaults['local_cert'] = $localCert;
                }
            }
        } catch (\Throwable $e) {
            // Fall back to built-in defaults when engine bootstrap is unavailable.
        }

        if ($relaxed) {
            $defaults['verify_peer'] = false;
            $defaults['verify_peer_name'] = false;
            $defaults['allow_self_signed'] = true;
            unset($defaults['cafile'], $defaults['capath']);
            return $defaults;
        }

        if (!empty($defaults['verify_peer']) && !empty($defaults['verify_peer_name'])) {
            $defaults['peer_name'] = $host;
        } elseif (empty($defaults['verify_peer'])) {
            $defaults['allow_self_signed'] = true;
        }

        return $defaults;
    }

    /**
     * @param array<string, mixed> $tlsOptions
     */
    private function isTlsVerificationEnabled(array $tlsOptions): bool
    {
        return ($tlsOptions['verify_peer'] ?? false) === true
            && ($tlsOptions['verify_peer_name'] ?? false) === true;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return array{0: T, 1: ?string}
     */
    private function captureWarnings(callable $callback): array
    {
        $warning = null;

        \set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        });

        try {
            $result = $callback();
        } finally {
            \restore_error_handler();
        }

        return [$result, $warning];
    }
}

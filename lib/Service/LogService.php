<?php

declare(strict_types=1);

namespace OCA\opacit_mail\Service;

use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * opacit_mail debug logger — writes to appdata_opacit_mail/opacit_mail.log
 * Independent from NC log level. Enable via:
 *   occ config:app:set opacit_mail debug_log --value=1
 *   or Admin -> opacit_mail -> Enable debug logging
 */
class LogService
{
    private const APP_ID = 'opacit_mail';
    private ?bool $enabled = null;
    private ?string $logFile = null;

    public function __construct(
        private IAppConfig $appConfig,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->enabled === null) {
            try {
                $this->enabled = $this->appConfig->getValueString(self::APP_ID, 'debug_log', '0') === '1';
            } catch (\Throwable $e) {
                $this->enabled = false;
            }
        }
        return $this->enabled;
    }

    private function getLogFile(): string
    {
        if ($this->logFile === null) {
            $dataDir = \rtrim(\trim($this->config->getSystemValue('datadirectory', '')), '\\/');
            $logDir = $dataDir . '/appdata_opacit_mail';
            if (!\is_dir($logDir)) {
                @\mkdir($logDir, 0750, true);
            }
            $this->logFile = $logDir . '/opacit_mail.log';
        }
        return $this->logFile;
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->write('DEBUG', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARN', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context, true);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context = [], bool $force = false): void
    {
        if (!$force && !$this->isEnabled()) {
            return;
        }

        $timestamp = \date('Y-m-d H:i:s');
        $user = '-';
        try {
            $u = $this->userSession->getUser();
            $user = $u ? $u->getUID() : '-';
        } catch (\Throwable $e) {
            // no session context (CLI, etc.)
        }

        $line = "[{$timestamp}] [{$level}] [{$user}] {$message}";
        if (!empty($context)) {
            $line .= ' ' . \json_encode($context, \JSON_UNESCAPED_SLASHES);
        }
        $line .= "\n";

        $logFile = $this->getLogFile();
        $isNew = !\file_exists($logFile);
        @\file_put_contents($logFile, $line, \FILE_APPEND | \LOCK_EX);
        if ($isNew) {
            @\chmod($logFile, 0600);
        }
    }
}

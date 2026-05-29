<?php

declare(strict_types=1);

namespace OCA\X2Mail\Util;

/**
 * Shared SSL-mode and OIDC-provider resolution used by the setup command,
 * the status command, and the setup controller.
 */
trait SetupResolvers
{
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

    /**
     * Resolve the effective OIDC provider, normalizing the request and
     * falling back to whichever provider is actually installed.
     */
    private function resolvePreferredOidcProvider(
        ?string $provider,
        bool $userOidcInstalled,
        bool $oidcLoginInstalled,
    ): ?string {
        $normalized = $provider === null ? null : $this->normalizeOidcProvider($provider);
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

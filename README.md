# X2Mail — Nextcloud Webmail with Native SSO

Feature-rich webmail client for Nextcloud 33 with native Single Sign-On via OAuth2 (OAUTHBEARER/XOAUTH2). Users log into Nextcloud via SSO and get webmail without a second login.

## How It Works

X2Mail bridges your Nextcloud OIDC login to your IMAP server. The OIDC access token is used directly for IMAP authentication — no extra passwords anywhere.

```
User → Keycloak SSO → Nextcloud (user_oidc)
  → X2Mail takes access token from session
  → IMAP AUTHENTICATE OAUTHBEARER <token>
  → Dovecot validates token via introspection → Keycloak
  → Mailbox opens — zero extra login
```

## What X2Mail Requires From The Mail Server

X2Mail is a webmail client. For productive use it needs a mail stack that can both open the mailbox and submit outgoing mail for the authenticated user.

### Required Capabilities

- **IMAP OAuth** — the IMAP server must support `XOAUTH2` or `OAUTHBEARER`
- **SMTP submission** — the SMTP submission endpoint used by X2Mail must support authenticated sending
- **OIDC token validation** — the mail server must be able to validate access tokens against your OIDC provider
- **Stable mail identity** — the authenticated mail identity must match the address model expected by the mail server, typically the canonical user email address

### Supported Architecture Types

X2Mail is not tied to one specific mail server product. The common requirement is always the same: IMAP OAuth plus an authenticated SMTP submission path.

- **Single VPS / Docker stack** — Nextcloud, X2Mail, Dovecot, Postfix and an IdP can run together on one VPS with one domain
- **Dovecot + Postfix** — common self-hosted setup; IMAP on Dovecot, SMTP submission on Postfix, auth often delegated to Dovecot SASL
- **Dovecot submission service** — submission/auth handled in Dovecot, relayed to another MTA
- **Integrated mail stacks** such as mailcow-like deployments — valid if they expose IMAP OAuth and authenticated SMTP submission
- **Other OAuth-capable SMTP/IMAP servers** — valid if they support the same client-facing capabilities
- **Gateway-based deployments** — an additional mail gateway such as PMG can sit in front as MX/filter/transport layer while X2Mail still talks to the actual IMAP and submission services

### Typical Deployment Shapes

**Single VPS / Docker stack**

```
User -> Nextcloud + X2Mail
     -> Dovecot IMAP
     -> Postfix Submission
     -> IdP
```

**Split services**

```
User -> Nextcloud + X2Mail
     -> Mail host (Dovecot/Postfix)
     -> IdP on separate host
```

**Gateway / MX in front**

```
Internet -> PMG or other mail gateway -> Mail host
User     -> Nextcloud + X2Mail -------> IMAP + Submission host
```

In the gateway case, the gateway can be part of the inbound/outbound transport path, but X2Mail still depends on the real IMAP and submission services.

### Not Required By X2Mail

- A specific MTA brand
- PMG, LMTP, or any specific internal mail routing topology as a mandatory component
- A public mail provider

The product requirement is capability-based, not vendor-based.

## Prerequisites

### 1. Nextcloud with OIDC Login

Nextcloud must have SSO login working via one of these apps:

- **`user_oidc`** (recommended) — official Nextcloud OIDC app
- **`oidc_login`** — third-party alternative

```bash
occ app:install user_oidc
occ user_oidc:provider YourProvider \
  -c YOUR_CLIENT_ID \
  -s YOUR_CLIENT_SECRET \
  -d https://your-idp.example.com/realms/your-realm/.well-known/openid-configuration
```

### 2. IMAP Server with OAuth2 Support

Your IMAP server must support token-based authentication (OAUTHBEARER/XOAUTH2 SASL).

**Dovecot** (requires 2.4+):
- Enable `auth-oauth2` mechanism
- Configure `passdb` with `oauth2` driver + token introspection endpoint
- Docs: https://doc.dovecot.org/2.4.2/core/config/auth/databases/oauth2.html

### 3. OIDC Provider Configuration

Your OIDC provider (Keycloak, Authentik, etc.) must:
- Include correct **audience** in access tokens (e.g. `aud: "dovecot"`)
- Include **email claim** in access token
- Expose a **token introspection endpoint** for the IMAP server

### 4. SMTP Submission Server

X2Mail sends mail via authenticated SMTP submission (port 587). In SSO mode, the submission endpoint must support `OAUTHBEARER` or `XOAUTH2` SASL authentication — the same OAuth token used for IMAP is reused for SMTP.

**Dovecot + Postfix** (recommended):

Postfix Submission delegates authentication to Dovecot SASL, which validates the OAuth token against the same introspection endpoint used for IMAP. This means a single Dovecot `passdb oauth2` config serves both IMAP and SMTP auth.

`/etc/postfix/master.cf` — Submission service:

```
submission inet n  -  y  -  -  smtpd
  -o smtpd_tls_security_level=may
  -o smtpd_sasl_auth_enable=yes
  -o smtpd_sasl_type=dovecot
  -o smtpd_sasl_path=private/auth
  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject
  -o smtpd_recipient_restrictions=permit_sasl_authenticated,reject
```

Dovecot SASL socket for Postfix (`dovecot.conf`):

```
auth_mechanisms = plain login xoauth2 oauthbearer

service auth {
  unix_listener /var/spool/postfix/private/auth {
    mode = 0660
    user = postfix
    group = postfix
  }
}
```

This makes Postfix advertise `AUTH PLAIN LOGIN XOAUTH2 OAUTHBEARER` on port 587. X2Mail uses the same OAuth token for both IMAP and SMTP — no separate credentials needed.

**STARTTLS:** Use a valid TLS certificate on the submission port (e.g. via `acme.sh` with DNS-01 challenge). Self-signed certificates can cause STARTTLS negotiation failures with PHP-based SMTP clients.

> **Important:** In SSO mode (`--auth oauth`), the setup command automatically enables SMTP authentication. Without it, X2Mail connects to SMTP without `AUTH`, which only works if the submission endpoint trusts the client network.

## Installation

```bash
cd /path/to/nextcloud/custom_apps
tar xzf x2mail-*.tar.gz
chown -R www-data:www-data x2mail
occ app:enable x2mail
```

Download the latest tarball from [GitHub Releases](https://github.com/NK-IT-CLOUD/x2mail/releases).

## Setup

### Quick Setup (SSO — default)

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 143 \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl tls \
  --domain example.com \
  --sieve
```

In SSO mode, SMTP authentication is enabled automatically. The setup command runs preflight checks and shows compact results:

```
✓ IMAP  mail.example.com:143 (XOAUTH2, OAUTHBEARER)
✓ SMTP  mail.example.com:587/STARTTLS (XOAUTH2, OAUTHBEARER)
✓ OIDC  user_oidc, token_store=ok
```

The preflight validates that both IMAP and SMTP submission endpoints advertise `XOAUTH2` or `OAUTHBEARER`.

### Setup Wizard (Browser)

The admin UI at **Settings → X2Mail** includes a setup wizard with the same preflight checks plus live SSO diagnostics:

```
✓ IMAP  mail.example.com:143 (XOAUTH2, OAUTHBEARER)
✓ SMTP  mail.example.com:587/STARTTLS (XOAUTH2, OAUTHBEARER)
✓ OIDC  user_oidc, token_store=ok
✓ SSO   Active session with valid token
✓ TOKEN email=user@example.com, aud=dovecot,nextcloud, expires=4min
```

The wizard decodes your JWT access token and verifies that the email claim and audience are correct for IMAP authentication.
The release branch keeps exactly one active domain configuration; saving the wizard replaces older stored domain configs automatically.

### Setup Options

| Option | Default | Description |
|---|---|---|
| `--imap-host` | (required) | IMAP server hostname |
| `--imap-port` | 143 | IMAP port |
| `--imap-ssl` | none | `none`, `ssl`, or `tls/starttls` |
| `--smtp-host` | same as IMAP | SMTP server hostname |
| `--smtp-port` | 587 | SMTP submission port |
| `--smtp-ssl` | tls | `none`, `ssl`, or `tls/starttls` |
| `--smtp-auth` | auto | Enabled automatically in SSO mode; requires SMTP `OAUTHBEARER`/`XOAUTH2` |
| `--domain` | (required) | Mail domain (e.g. `example.com`) |
| `--auth` | oauth | `oauth` (SSO) or `plain` (legacy) |
| `--oidc-provider` | user_oidc | `user_oidc` or `oidc_login` |
| `--sieve` | no | Enable Sieve filtering |
| `--skip-checks` | no | Skip connectivity checks |

### Check Status

```bash
occ x2mail:status
```

Shows configured domains, IMAP/SMTP settings, SSO configuration, provider status, and token store.
If older installs still have more than one stored domain config, the status command warns and the next wizard save or `occ x2mail:setup` run consolidates them to one active profile.

### Admin Panel

The legacy engine admin panel still exists for low-level maintenance, but the release branch is moving all required setup into **Settings → X2Mail**. The intended admin surface is a restricted Nextcloud settings page with one active domain profile and only the SSO-relevant options.

### Legacy: Password Auth

If SSO is not available, X2Mail can be configured with `--auth plain`. Users then enter their email and password manually each time they open X2Mail — credentials are forwarded to the mail server via IMAP/SMTP PLAIN authentication.

```bash
occ x2mail:setup \
  --imap-host mail.example.com \
  --imap-port 993 --imap-ssl ssl \
  --smtp-host mail.example.com \
  --smtp-port 587 --smtp-ssl tls --smtp-auth \
  --domain example.com \
  --auth plain
```

Per-user credentials can also be pre-configured by an admin:

```bash
occ x2mail:settings <uid> <email> [password]
```

## SSO Token Flow

```
1. User opens Nextcloud → Keycloak SSO login
2. user_oidc obtains access token + refresh token
3. X2Mail TokenBridgeListener stores token in session
4. User clicks "Email" → X2Mail reads token
5. IMAP AUTHENTICATE OAUTHBEARER <token>
6. Dovecot validates token → Keycloak introspection
7. Mailbox opens — automatic, no extra login
8. User sends mail → SMTP AUTH OAUTHBEARER <same token>
9. Postfix → Dovecot SASL → Keycloak introspection → accepted
```

The same access token is used for both IMAP (reading) and SMTP (sending). No separate credentials or per-protocol configuration needed.

### Token Refresh

Access tokens expire after ~5 minutes. X2Mail's `TokenRefreshMiddleware` automatically refreshes via `user_oidc`'s TokenService on every NC request.

**Requirement:** `user_oidc` must have `store_login_token=1` (set automatically by `occ x2mail:setup`).

## Features

- **SSO Webmail** — Keycloak/OIDC login → IMAP without extra credentials
- **OAuth2 IMAP auth** — OAUTHBEARER + XOAUTH2 (auto-detected)
- **Single-domain release setup** — one active server profile for all SSO users
- **Automatic token refresh** — no session drops
- **Setup Wizard** — preflight checks + JWT token diagnostics
- **Admin Panel via SSO** — NC admin = engine admin, no extra password
- **NC33 native theme** — light + dark mode
- **Sieve filtering** support
- **Nextcloud integration** — Contacts, Files, Calendar
- **Multiple identities** — send from different addresses
- **OpenPGP / S/MIME** encryption
- **`occ` commands** — setup, status, settings

## Troubleshooting

### "Login form appears instead of mailbox"
- `occ x2mail:status` — is OIDC auto-login enabled?
- Is `store_login_token=1` set for user_oidc?
- Are you logged in via SSO (not direct NC login)?

### "IMAP authentication failed"
- Run the setup wizard and check the TOKEN line — is email claim present?
- Does the audience include your IMAP server (e.g. `dovecot`)?
- Can Dovecot reach the OIDC introspection endpoint?
- Check: `journalctl -u dovecot | grep auth`

### "Recipient address rejected: Access denied" when sending

- Is SMTP auth enabled? Check domain config: `SMTP.useAuth` must be `true`
- In SSO mode, `--smtp-auth` is enforced automatically — re-run `occ x2mail:setup` if migrating from an older version
- Does the submission endpoint advertise `OAUTHBEARER`? Check: `openssl s_client -connect mail.example.com:587 -starttls smtp` then `EHLO test` — look for `AUTH ... OAUTHBEARER`
- Check Postfix logs: `journalctl -u postfix | grep submission` — look for `sasl_method=OAUTHBEARER`

### "STARTTLS failed / TLS unknown CA"

- The SMTP submission endpoint needs a valid TLS certificate (not self-signed)
- Use `acme.sh` with DNS-01 challenge or a reverse proxy cert for the submission hostname
- Alternatively, configure `--smtp-ssl none` for trusted internal networks

### "Admin panel not loading"
- Are you a Nextcloud admin? (NC admin = engine admin via SSO)
- Hard-refresh browser: Ctrl+Shift+R

## Requirements

- Nextcloud 33+
- PHP 8.3+
- IMAP server with OAUTHBEARER (Dovecot 2.4+)
- SMTP submission (port 587) with OAUTHBEARER via Dovecot SASL
- Valid TLS certificate on submission endpoint (STARTTLS)
- OIDC provider (Keycloak, Authentik, etc.) + `user_oidc`

## Development

```bash
git clone https://github.com/NK-IT-CLOUD/x2mail.git
cd x2mail
make build    # Build release tarball
```

See [CHANGELOG.md](CHANGELOG.md) for version history.
See [RELEASE.md](RELEASE.md) for release process.

## Origin

X2Mail is a permanent fork of [SnappyMail v2.38.2](https://github.com/the-djmaze/snappymail/releases/tag/v2.38.2) — the last release of the project. Rebuilt for Nextcloud 33 with native OIDC/SSO, full rebrand, and ongoing maintenance.

## License

AGPL-3.0 — see [LICENSE](LICENSE)

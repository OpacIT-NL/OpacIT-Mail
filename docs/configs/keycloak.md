# Keycloak OIDC Configuration (X2Mail)

This guide defines the IdP side for X2Mail with masked example values only.

## Objective

Issue access tokens that can be accepted by the mail server for SASL
`OAUTHBEARER` / `XOAUTH2` authentication.

## Required Clients

- Nextcloud client: `nextcloud`
- Mail server client (example): `mail-service`

## Required Token Properties

Access token must contain:

- `aud` includes `mail-service` (and optionally `nextcloud`)
- stable user identity (`email` claim recommended)

## Recommended Setup (Audience Mapper)

1. Open Keycloak Admin UI
2. Go to **Clients -> nextcloud -> Client scopes / Mappers**
3. Add **Audience** mapper:
   - Included Client Audience: `mail-service`
   - Add to access token: enabled
4. Ensure `email` claim is present in access token

Expected token excerpt:

```json
{
  "aud": ["nextcloud", "mail-service"],
  "email": "user@example.com"
}
```

## Optional: Token Exchange

If audience cannot be added directly to the Nextcloud token, X2Mail can request a
mail-scoped token via `--imap-audience mail-service`.

Use this only when direct audience mapping is not possible.

## Network Requirements

- Nextcloud must reach Keycloak for login + refresh
- Mail server must reach Keycloak for introspection/JWKS validation

## Verification

- Login to Nextcloud via OIDC
- In X2Mail wizard, run preflight and check TOKEN output includes expected `aud`

# Security and operations

## Implemented controls

- Breeze session authentication for the web UI and Sanctum bearer tokens for `/api/v1`.
- Authenticator-app two-factor authentication (TOTP) with encrypted secrets, confirmation before activation, one-time recovery codes, replay-resistant verification, and rate-limited challenges.
- Privileged roles require 2FA by default. Accounts with enabled 2FA must also provide an authenticator or recovery code before a Sanctum token is issued.
- System Administrator 2FA recovery requires administrator password confirmation and an explicit identity-verification attestation. It revokes the target account's database sessions and API tokens and creates an audit record and in-app notification.
- Password recovery requires a matching employee ID and registered work email, returns a generic response to prevent account discovery, uses hashed single-use tokens that expire after 30 minutes, and rate-limits requests.
- Successful password resets rotate the remember token, revoke Sanctum API tokens, and invalidate existing browser sessions.
- Role checks plus scoped token abilities.
- CSRF protection for browser forms and request validation for write operations.
- API throttling: 60 requests/minute and 5 token attempts/minute.
- Secure response headers including clickjacking, MIME sniffing, referrer, and browser-permission controls.
- Generic production API errors with no stack traces.
- Write-request audit records containing actor, route, target model, status, IP, user agent, request ID, and input field names. Values and passwords are not captured.
- Integration event logs without credentials or full provider responses.
- Composite indexes for high-use attendance, timesheet, and leave reporting queries.
- Aggregate-only payloads for AI analysis.

## Operational checklist

```bash
vendor/bin/pint --test
php artisan test
composer audit
npm audit
php artisan route:list --path=api
```

Use `APP_ENV=production`, `APP_DEBUG=false`, HTTPS, secure cookies, a least-privilege database user, encrypted backups, and restricted access to `storage`. Change the seeded password immediately and set a unique `INITIAL_USER_PASSWORD` before initial production seeding.

Keep `APP_KEY` stable and secret because 2FA secrets and recovery codes are encrypted with it. Losing or rotating the key without a controlled migration makes existing 2FA enrollments unreadable. Configure `TWO_FACTOR_REQUIRED_ROLES` as a comma-separated list; the secure default is `system-administrator,hr-manager,department-head`.

Users enroll from **Account Settings → Two-factor security** by confirming their password, scanning the QR code in a TOTP-compatible authenticator, and confirming the current six-digit code. Recovery codes must be stored offline. Password reset does not remove 2FA.

Configure a real transactional mail provider in production. The local `log` mailer only writes reset emails to `storage/logs/laravel.log`; it does not deliver them. Set `APP_URL` to the public HTTPS origin so reset links point to the correct trusted host.

For Gmail SMTP, use the isolated `gmail` mailer documented in `docs/GMAIL_PASSWORD_RESET_SETUP.md`. It requires Google 2-Step Verification and an App Password. Never use or store the normal Gmail account password.

Audit and integration tables grow over time. Define an organization-approved retention policy and archive/delete old records through a reviewed scheduled command; do not silently purge legally required audit data.

Uploaded leave attachments use the private local disk. Production web servers must never expose `storage/app/private` directly. Downloads must continue through authorized application routes.

## Incident handling

If a credential or bearer token leaks, revoke/rotate it, clear cached Zoom tokens with `php artisan cache:clear`, inspect `audit_logs` and `integration_events`, and review application/web-server logs. Back up evidence before applying retention cleanup. For a lost authenticator, use a one-time recovery code; if none remain, a System Administrator must verify the employee's identity and perform the audited reset from the employee profile.

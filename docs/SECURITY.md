# Security and operations

## Implemented controls

- Breeze session authentication for the web UI and Sanctum bearer tokens for `/api/v1`.
- Authenticator-app two-factor authentication (TOTP) with encrypted secrets, confirmation before activation, one-time recovery codes, replay-resistant verification, and rate-limited challenges.
- Privileged roles require 2FA by default. Accounts with enabled 2FA must also provide an authenticator or recovery code before a Sanctum token is issued.
- System Administrator 2FA recovery requires administrator password confirmation and an explicit identity-verification attestation. It revokes the target account's database sessions and API tokens and creates an audit record and in-app notification.
- Password recovery requires a matching employee ID and registered work email, returns a generic response to prevent account discovery, uses hashed single-use tokens that expire after 30 minutes, and rate-limits requests.
- Successful password resets rotate the remember token, revoke Sanctum API tokens, and invalidate existing browser sessions.
- An account may be signed in on one device at a time. A second sign-in does not take the session over: the account is closed on both sides, the device that was already working is signed out, and the arriving sign-in is turned away. Each side is shown a dialog naming which of the two it is, and the login page repeats it. The signed-out device finds out without being touched — an open screen asks the server every SESSION_HEARTBEAT_SECONDS (default 5) while its tab is in the foreground, and asks again the moment a backgrounded tab is looked at. Raise that value to trade promptness for traffic. A device that closes its browser without signing out leaves its slot held, so its next sign-in is turned away once before it succeeds.
- Role checks plus scoped token abilities.
- CSRF protection for browser forms and request validation for write operations.
- API throttling: 60 requests/minute and 5 token attempts/minute.
- Secure response headers including clickjacking, MIME sniffing, referrer, and browser-permission controls.
- Generic production API errors with no stack traces.
- Write-request audit records containing actor, route, target model, status, IP, user agent, request ID, and input field names. Values and passwords are not captured.
- Integration event logs without credentials or full provider responses.
- Composite indexes for high-use attendance, timesheet, and leave reporting queries.
- Aggregate-only payloads for AI analysis.
- Production security gate (security:check) for HTTPS, secure/encrypted sessions, debug mode, CSP, malware scanning, and secret readiness. SECURITY_ENFORCE_PRODUCTION, SESSION_SECURE_COOKIE, and SESSION_ENCRYPT all default to on once APP_ENV=production and only need an explicit override if you have a specific reason not to enforce them; local/testing environments are unaffected either way (the gate also requires APP_ENV=production to activate).
- Content Security Policy starts in report-only mode and sends sanitized, rate-limited violation reports to /api/v1/security/csp-report. Review reports before changing CSP_MODE=enforce.
- Leave attachments are scanned by the configured ClamAV driver before private storage. Production should enable MALWARE_SCANNING_ENABLED=true and keep MALWARE_SCANNING_FAIL_CLOSED=true; an unavailable scanner rejects uploads.
- Authentication, authorization, CSRF, and rate-limit failures are recorded as sanitized security signals. Raw passwords, tokens, and employee IDs are never logged.

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

Employee IDs are generated from the selected department code by default. Generation locks the department row inside the employee creation transaction, considers soft-deleted historical IDs, and relies on the database unique constraint as a final safeguard. Existing IDs are immutable. Only a System Administrator can disable automatic generation from Account Settings; manual mode should be used only for controlled migrations or legacy identifiers.

SESSION_SECURE_COOKIE, SESSION_ENCRYPT, and SECURITY_ENFORCE_PRODUCTION now default to true automatically once APP_ENV=production — nothing to set unless you want to override them. MALWARE_SCANNING_ENABLED remains a deliberate manual step: install and verify ClamAV first, then set it, since enabling it before ClamAV is ready will fail-closed and reject every leave attachment upload. Start CSP_MODE=report-only, review reports, then switch to enforce. Run php artisan security:check before deploying; do not deploy while any critical check fails. Local/testing environments are unaffected by any of this regardless of these values, since the gate also requires APP_ENV=production.

## Incident handling

If a credential or bearer token leaks, revoke or rotate it, inspect `audit_logs` and `integration_events`, and review application/web-server logs. Back up evidence before applying retention cleanup. For a lost authenticator, use a one-time recovery code; if none remain, a System Administrator must verify the employee's identity and perform the audited reset from the employee profile.

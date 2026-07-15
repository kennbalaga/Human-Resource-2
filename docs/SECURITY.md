# Security and operations

## Implemented controls

- Breeze session authentication for the web UI and Sanctum bearer tokens for `/api/v1`.
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

Audit and integration tables grow over time. Define an organization-approved retention policy and archive/delete old records through a reviewed scheduled command; do not silently purge legally required audit data.

Uploaded leave attachments use the private local disk. Production web servers must never expose `storage/app/private` directly. Downloads must continue through authorized application routes.

## Incident handling

If a credential or bearer token leaks, revoke/rotate it, clear cached Zoom tokens with `php artisan cache:clear`, inspect `audit_logs` and `integration_events`, and review application/web-server logs. Back up evidence before applying retention cleanup.

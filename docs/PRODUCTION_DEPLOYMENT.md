# Production deployment

This is a baseline Linux deployment for Nginx/Apache, PHP-FPM, MySQL, and a process supervisor. Adapt paths and service names to the hosting platform. Test the exact release process in staging first.

## Server requirements

- PHP 8.3+ with `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `mbstring`, `openssl`, `pdo_mysql`, `session`, `tokenizer`, and `xml`
- Composer 2, MySQL 8+/MariaDB 10.6+, and Node.js 20+ for asset builds
- Nginx or Apache with the document root set to the project's `public` directory
- TLS certificate, queue supervisor, and cron access

## Environment

Create a protected `.env` on the server; never deploy a developer `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hrms.example.com
LOG_LEVEL=warning
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=human_resource_2
DB_USERNAME=hrms_app
DB_PASSWORD=use-a-secret-manager
SESSION_DRIVER=database
QUEUE_CONNECTION=database
CACHE_STORE=database
INITIAL_USER_PASSWORD=replace-before-first-seed
TWO_FACTOR_REQUIRED_ROLES=system-administrator,hr-manager,department-head

# Hardening — SESSION_SECURE_COOKIE, SESSION_ENCRYPT, and
# SECURITY_ENFORCE_PRODUCTION all now default to true automatically once
# APP_ENV=production, so they don't need to be set here explicitly. They're
# listed anyway so this file is a complete picture of what's in effect —
# override only if you have a specific reason to run production without one.
# SESSION_SECURE_COOKIE=true
# SESSION_ENCRYPT=true
# SECURITY_ENFORCE_PRODUCTION=true

# Malware scanning does NOT auto-enable — it depends on ClamAV actually
# being installed and reachable. Enabling it before that's verified will
# reject every leave attachment upload (MALWARE_SCANNING_FAIL_CLOSED=true
# is the default). Install and verify ClamAV first, then set:
MALWARE_SCANNING_ENABLED=true

# Content-Security-Policy: start in report-only, confirm no legitimate
# screen is flagged, then switch to enforce. See docs/SECURITY.md.
CSP_MODE=report-only
```

Create a least-privilege MySQL user limited to the HRMS database. Generate `APP_KEY` once with `php artisan key:generate`; preserve it across releases or encrypted application data, cookies, 2FA secrets, and recovery codes become unreadable.

## Release commands

From a new release directory:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
```

Do not run `db:seed` on every deployment. Seed only during approved initialization because seed data can include development accounts. Migrations are the mechanism that updates every developer/production schema after a Git pull.

Use atomic releases (new timestamped directory plus a symlink switch) where possible. Back up the database before non-trivial migrations. Roll back by restoring the previous release and a compatible database backup; do not assume every migration is safely reversible after live writes.

## Web server

Set the site root to `/var/www/hrms/current/public`, deny access to dotfiles, route missing files to `index.php`, and allow PHP execution only for `public/index.php`. Enable HTTPS and redirect HTTP to HTTPS. The web-server user needs write access only to `storage` and `bootstrap/cache`.

Example permissions (adapt user/group):

```bash
chown -R deploy:www-data /var/www/hrms
find /var/www/hrms/current/storage /var/www/hrms/current/bootstrap/cache -type d -exec chmod 775 {} \;
find /var/www/hrms/current/storage /var/www/hrms/current/bootstrap/cache -type f -exec chmod 664 {} \;
```

## Queue and scheduler

**A running queue worker is required, not optional.** Preference emails (attendance reminders, schedule updates, leave-status notifications) are queued (`ShouldQueue`) rather than sent inline, so without a worker running they will accumulate undelivered in the `jobs` table instead of failing loudly. Run a supervised process similar to:

```bash
php /var/www/hrms/current/artisan queue:work --queue=default --sleep=3 --tries=3 --timeout=120
```

Add one cron entry:

```cron
* * * * * cd /var/www/hrms/current && php artisan schedule:run >> /dev/null 2>&1
```

Deploys should call `php artisan queue:restart` so workers load current code.

## Pre-release and post-release checks

Before release:

```bash
vendor/bin/pint --test
php artisan test
composer audit
npm audit
```

After release, verify `/up`, login/logout, privileged-role 2FA enrollment and challenge, one read-only dashboard request, queue health, scheduler logs, storage access, and database backups. Test Gemini separately; an AI provider failure must only produce a warning/event record, never an HR transaction failure.

Monitor application logs, HTTP 5xx/429 rates, queue failures, database capacity, `audit_logs`, and `integration_events`. For uncaught exceptions specifically, set `SENTRY_LARAVEL_DSN` (sign up at sentry.io or point at a self-hosted instance) — the SDK is already wired in `bootstrap/app.php` and does nothing until a DSN is set. Configure encrypted off-host database and private-upload backups, then regularly test restoration.

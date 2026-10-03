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

# Where leave attachments are written. See "Attachment storage" below before
# deploying to anything with an ephemeral filesystem.
WORKFORCE_ATTACHMENT_DISK=local
```

Create a least-privilege MySQL user limited to the HRMS database. Generate `APP_KEY` once with `php artisan key:generate`; preserve it across releases or encrypted application data, cookies, 2FA secrets, and recovery codes become unreadable.

## Attachment storage

Leave attachments are the only user-uploaded files this app keeps, and they are medical certificates and fit notes. `WORKFORCE_ATTACHMENT_DISK` selects the disk they are written to; it is deliberately separate from `FILESYSTEM_DISK` so that pointing the app's general default at a public disk can never make a medical record reachable by URL. Whatever you choose must not be web-served: on the default `local` disk that means never exposing `storage/app/private`.

The default `local` disk is correct on a server with a real filesystem — a VM or anything with a persistent volume mounted over `storage/app`. It is wrong on a platform with an ephemeral filesystem (containers rebuilt per deploy), where every attachment is destroyed on the next release while its database row survives, leaving a download route that 404s. On those platforms either mount a persistent volume at `storage/app` or configure the `s3` disk and set `WORKFORCE_ATTACHMENT_DISK=s3`.

Each attachment records the disk it was written to, so changing this setting affects only new uploads and older files stay reachable. Existing files are not migrated for you — copy them to the new disk yourself before repointing it, or their rows will break.

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

## Biometric terminal and bridge agent

Deploying this is not only a code release. The terminal holds a copy of who
people are, and that copy has to agree with the database the application is
actually running against.

### Read this before anything else

**A device PIN is the employee's record id**, and record ids belong to one
database. The terminal currently holds users enrolled from whichever database
they were exported from. Point the application at a different database and the
same PIN can mean a different person — or nobody.

That failure is silent. A punch arrives, resolves to whoever holds that id in
the new database, and files a real attendance record against the wrong person.
Nothing errors.

So: **check before trusting a single punch.** Pick three or four people, compare
the PIN on the roster against their employee number in both databases, and
confirm they are the same human being.

If the ids do not line up, the enrolment has to be redone against the
production database:

1. Register the terminal in production (Settings → Operational tools →
   Biometric terminals), using the real serial.
2. Reserve PINs there — per department first, not all at once.
3. Export the **ZKTime import file** from *production*.
4. Delete the users on the terminal and re-import from that file, then
   **Machine → Upload user info and FP**.
5. Fingerprint templates already captured can be kept: download them from the
   device first, and upload them back after the users are recreated. Only do
   that if the PINs are unchanged — a template restored onto a different PIN
   attaches somebody's finger to somebody else's record.

Enrolment data does not travel with a code deployment. There is no export or
import of `biometric_enrollments` between environments, deliberately: the
mapping is only meaningful against one set of employee ids.

### Environment

```dotenv
# Required. Unset, the punch endpoint answers 503 to everything -- there is no
# unsigned fallback. Generate a NEW one for production; do not reuse a value
# that has been on a developer machine.
#   openssl rand -hex 32
BIOMETRIC_BRIDGE_SECRET=

# All optional, shown with their defaults.
BIOMETRIC_BRIDGE_MAX_PUNCH_AGE_HOURS=72
BIOMETRIC_BRIDGE_MAX_BATCH_SIZE=500
BIOMETRIC_BRIDGE_REQUESTS_PER_MINUTE=120
BIOMETRIC_BRIDGE_DEVICE_REQUESTS_PER_MINUTE=60
BIOMETRIC_BRIDGE_MAX_PIN_LENGTH=9
BIOMETRIC_BRIDGE_MIN_PUNCH_INTERVAL_MINUTES=2
```

**`TRUSTED_PROXIES` matters more than usual here.** The punch endpoint is rate
limited by address before its signature is checked. Behind a load balancer with
the proxy list wrong, every bridge in the hospital shares one apparent address
and they throttle each other.

The migration that creates `biometric_punches` runs with the `migrate --force`
already in the release commands above. Nothing extra is needed.

### The bridge agent is not deployed with the application

It runs on a PC inside the hospital LAN, because the terminal cannot be reached
from outside it. See `bridge/README.md`. On that machine:

```dotenv
BRIDGE_SERVER_URL=https://hrms.example.com/api/biometric/punches
BRIDGE_SECRET=<the same value as BIOMETRIC_BRIDGE_SECRET above>
BRIDGE_DEVICE_IP=<the terminal's address on the hospital network>
BRIDGE_DEVICE_SERIAL=<the serial registered in the application>
BRIDGE_VERIFY_TLS=true
```

`BRIDGE_VERIFY_TLS=false` is only defensible on a trusted LAN with a
self-signed certificate. Over the public internet it sends a signed payload
across a connection nobody has verified.

### Order of operations

1. Deploy the code and run the migrations.
2. Set `BIOMETRIC_BRIDGE_SECRET` and confirm `php artisan security:check`
   passes — it has to, independently of this feature.
3. Register the terminal and verify the PIN-to-person mapping as above.
4. Configure and start the bridge agent. Run it once with `--dry-run` first:
   it reads the device and reports, writing nothing anywhere.
5. Have one person scan. Confirm the attendance record names the right human
   being before letting a ward through.

### After go-live

Watch for `unmatched`, `unsupported` or `stale` in the agent's log. Those
punches are stored but became no attendance, and are recoverable once the cause
is fixed:

```bash
php artisan biometric:replay --dry-run
php artisan biometric:replay
```

`biometric:replay` is deliberately not scheduled. A replay follows a fix;
running it on a timer would re-attempt the same stuck punches nightly and bury
the signal that something still needs attention.

Finally, turn on `schedule_aware` and `enforce_published_shift` once rosters are
reliably published. The PIN scheme has no check digit, so a mis-keyed PIN lands
on a real colleague; a published shift is what refuses it. Enabling it before
schedules are published rejects almost everything, so it is a step after
go-live, not part of it.

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

# HRMS Workforce Management

Laravel-based Workforce Management module for the Human Resource Management System. It includes session authentication, dashboard, attendance, shifts and schedules, timesheets, leave management, analytics, optional Gemini AI features, and a versioned REST API.

## Requirements

- PHP 8.3 or newer
- Composer 2
- MySQL 8 or MariaDB 10.6 or newer
- Node.js 20 or newer with npm

## Quick start

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm install
npm run build
php artisan serve
```

Open `http://127.0.0.1:8000`. Seeded accounts use the password configured in `INITIAL_USER_PASSWORD` (development default: `ChangeMe123!`). Employees created through the UI also use `LOCAL_EMPLOYEE_DEFAULT_PASSWORD` in local/testing only. Production continues to generate an unguessable password and sends a setup link. Change development defaults outside local development.

| Role | Employee ID |
|---|---|
| System administrator | `SYS-ADMIN-2026-0001` |
| HR manager | `HR-MGR-2026-0001` |
| Department head | `NUR-HEAD-DERM-2026-0001` |
| Employee | `HR-OFFICER-2026-0001` |

## Working as a group

Everyone runs the app on their own computer against the same Railway database, with the same `.env`. The `.env` is committed only in encrypted form, as `.env.encrypted`. The key to open it is shared in the group chat and must never be committed.

First time, and after any pull that changes `.env.encrypted`:

```bash
git pull
php artisan env:decrypt --key="KEY_FROM_THE_GROUP_CHAT" --force
composer install
npm install
npm run build
php artisan serve
```

Once, on every computer that ever uploaded a leave attachment, so the files only that computer has reach everyone:

```bash
php artisan leave-attachments:move-to-database
```

- Do not run `php artisan migrate --seed` or `db:seed`. The database is shared and already set up. Run `php artisan migrate` only when a pull adds a migration.
- To change a setting for everyone, edit `.env`, then run `php artisan env:encrypt --key="KEY_FROM_THE_GROUP_CHAT" --force` and commit `.env.encrypted`.
- An account can be signed in on one device at a time. If two people use the same account at once, each sign-in closes the other's session.

## Development services

```bash
npm run dev
php artisan queue:work --queue=default
php artisan schedule:work
```

Gemini AI is disabled by default and is not required for core HR functions.

## Documentation

- [REST API](docs/API.md)
- [Gemini AI integration](docs/INTEGRATIONS.md)
- [AI workforce scheduling](docs/AI_WORKFORCE_SCHEDULING.md)
- [Burnout risk indicator](docs/BURNOUT_RISK.md)
- [Data privacy](docs/DATA_PRIVACY.md)
- [Security and operations](docs/SECURITY.md)
- [Laragon setup on Windows](docs/LARAGON_SETUP.md)
- [Production deployment](docs/PRODUCTION_DEPLOYMENT.md)
- [Postman collection](docs/postman/HRMS-Workforce-API.postman_collection.json)

## Quality checks

```bash
vendor/bin/pint --test
php artisan test
composer audit
npm audit
npm run build
```

Never commit `.env`, API credentials, tokens, uploaded employee files, or production database dumps.

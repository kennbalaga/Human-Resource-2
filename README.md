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
| System administrator | `SYS-2026-0001` |
| HR manager | `HR-2026-0001` |
| Department head | `NUR-2026-0001` |
| Employee | `HR-2026-0002` |

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

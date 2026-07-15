# HRMS Workforce Management

Laravel-based Workforce Management module for the Human Resource Management System. It includes session authentication, dashboard, attendance, shifts and schedules, timesheets, leave management, analytics, optional third-party integrations, and a versioned REST API.

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

Open `http://127.0.0.1:8000`. Seeded accounts use the password configured in `INITIAL_USER_PASSWORD` (development default: `ChangeMe123!`). Change it outside local development.

| Role | Employee ID |
|---|---|
| System administrator | `SYS-0001` |
| HR manager | `HR-0001` |
| Department head | `NUR-0001` |
| Employee | `HR-0002` |

## Development services

```bash
npm run dev
php artisan queue:work --queue=integrations,default
php artisan schedule:work
```

Third-party integrations are disabled by default and are not required for core HR functions.

## Documentation

- [REST API](docs/API.md)
- [Gemini, Zapier, and Zoom](docs/INTEGRATIONS.md)
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

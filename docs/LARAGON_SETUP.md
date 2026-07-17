# Laragon setup on Windows

Laragon is for Windows. macOS developers can continue using XAMPP, Herd, Valet, or the PHP/MySQL services already configured.

## 1. Install prerequisites

Install Laragon Full, Git, Composer 2, and Node.js LTS. In Laragon, select PHP 8.3 or newer and MySQL 8/MariaDB 10.6 or newer. Restart Laragon after changing versions.

Verify in **Laragon > Terminal**:

```powershell
php -v
composer --version
mysql --version
node -v
npm -v
```

## 2. Place the project

Clone or pull the repository under Laragon's web root, normally:

```powershell
cd C:\laragon\www
git clone YOUR_REPOSITORY_URL Human-Resource-2
cd Human-Resource-2
```

For an existing checkout, run `git pull` inside its project folder. Do not copy another developer's `.env`.

## 3. Install project dependencies

```powershell
composer install
copy .env.example .env
php artisan key:generate
npm install
```

## 4. Create MySQL database

Start **Apache/Nginx** and **MySQL** in Laragon. Open Laragon's database tool or terminal and create a database:

```sql
CREATE DATABASE human_resource_2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configure `.env`:

```dotenv
APP_URL=http://human-resource-2.test
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=human_resource_2
DB_USERNAME=root
DB_PASSWORD=
```

If your Laragon MySQL root account has a password, put it in `DB_PASSWORD`.

## 5. Build and migrate

```powershell
php artisan migrate --seed
npm run build
php artisan storage:link
php artisan optimize:clear
```

Laragon normally creates `http://human-resource-2.test` automatically after **Menu > Reload**. Alternatively:

```powershell
php artisan serve
```

Then open `http://127.0.0.1:8000`.

## 6. Background work

Open a second Laragon terminal:

```powershell
php artisan queue:work --queue=default --tries=3
```

For development scheduling, open another terminal and run `php artisan schedule:work`. Integration credentials are optional; leave all `*_ENABLED=false` until configured.

## 7. Accounts

Use `SYS-0001`, `HR-0001`, `NUR-0001`, or `HR-0002`. The development password is `ChangeMe123!` unless `INITIAL_USER_PASSWORD` was changed before seeding.

## Pulling database changes

Migrations are committed to Git; database contents are not. After every pull:

```powershell
composer install
php artisan migrate
npm install
npm run build
php artisan optimize:clear
```

Never run `migrate:fresh` on a shared or production database because it deletes data.

## Troubleshooting

- `could not find driver`: enable `pdo_mysql` in Laragon's selected `php.ini`, then restart.
- `Access denied`: verify MySQL username/password and that the database exists.
- Vite manifest missing: run `npm install` then `npm run build`.
- Class/package missing after pull: run `composer install` and `composer dump-autoload`.
- Old configuration persists: run `php artisan optimize:clear`.

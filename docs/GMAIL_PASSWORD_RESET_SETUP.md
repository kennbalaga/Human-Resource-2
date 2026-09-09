# Gmail delivery for password resets

The password reset feature already sends through Laravel's configured mailer. Gmail delivery is isolated to the `gmail` mailer, so attendance, schedules, leave, timesheets, APIs, and other application features are not changed.

## Gmail account preparation

1. Use a dedicated hospital or system Gmail account rather than a personal mailbox.
2. Enable 2-Step Verification on that Google account.
3. Create a Google App Password for the HRMS. Do not use the normal Google account password.
4. Keep the App Password only in the local or production `.env` file. Never commit it to Git or paste it into tickets, logs, or chat.

## Application configuration

Update these values in `.env`:

```dotenv
MAIL_MAILER=gmail
GMAIL_SMTP_USERNAME="your-sender@gmail.com"
GMAIL_SMTP_APP_PASSWORD="your-16-character-app-password"
MAIL_FROM_ADDRESS="your-sender@gmail.com"
MAIL_FROM_NAME="Memorial Hospital & Sanitarium"
APP_URL="https://your-real-hrms-domain.example"
```

For local-only testing, `APP_URL=http://127.0.0.1:8000` is acceptable, but the recipient can only open that reset link on the computer running the application.

After changing `.env`, clear the cached application configuration:

```bash
php artisan optimize:clear
```

The employee must also have a real, reachable email address saved as their registered account email. Seeded `@hrms.local` addresses cannot receive internet email.

## Safe rollback

If Gmail delivery is unavailable, set `MAIL_MAILER=log` and clear the configuration cache again. Reset messages will return to `storage/logs/laravel.log` without affecting the rest of the application.

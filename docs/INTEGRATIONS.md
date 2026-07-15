# External integrations

Gemini, Zapier, and Zoom are optional adapters. Each is disabled by default. Timeouts, caught exceptions, queued webhook delivery, and local event recording ensure an unavailable provider does not roll back or disable core HR transactions.

Integration attempts appear under **Integrations** and are stored in `integration_events`. Secrets are never stored there.

## Gemini analytics

Gemini receives only aggregate workforce metrics; employee names, email addresses, reasons, attachment data, and other identifying fields are excluded.

```dotenv
GEMINI_ENABLED=true
GEMINI_API_KEY=your-server-side-key
GEMINI_MODEL=gemini-3.5-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
```

Keep the key server-side and restrict it in Google Cloud where available. Open **Analytics** and select **Generate AI insight**. If Gemini fails, the normal charts and metrics remain available and the UI shows a warning.

## Zapier webhooks

Create a Zap with a **Webhooks by Zapier / Catch Hook** trigger, copy its unique hook URL, then configure:

```dotenv
ZAPIER_ENABLED=true
ZAPIER_WEBHOOK_URL=https://hooks.zapier.com/hooks/catch/...
ZAPIER_SIGNING_SECRET=a-long-random-secret
```

The system queues `attendance.approved`, `timesheet.approved`, and `leave.approved`. It sends JSON containing `event`, `occurred_at`, and `data`. When a signing secret exists, `X-HRMS-Signature` contains a SHA-256 HMAC of the exact JSON body.

Run a worker so queued deliveries are processed:

```bash
php artisan queue:work --queue=integrations,default --tries=3
```

Use the **Integrations** page to send a test. A disabled/deleted Zap or network failure is recorded but does not change the saved HR transaction.

## Zoom meetings

Create a Zoom Server-to-Server OAuth app and grant it permission to create meetings for the configured account user. Then configure:

```dotenv
ZOOM_ENABLED=true
ZOOM_ACCOUNT_ID=...
ZOOM_CLIENT_ID=...
ZOOM_CLIENT_SECRET=...
ZOOM_USER_ID=me
ZOOM_TIMEZONE=Asia/Manila
```

Open **Integrations**, enter a topic, start time, duration, and optional agenda, then create the meeting. Access tokens are cached for 50 minutes and are never persisted in HRMS tables. Failed authentication or API calls return a safe warning.

## Configuration lifecycle

After changing `.env`, run:

```bash
php artisan optimize:clear
```

In production, use a secrets manager or protected environment configuration. Rotate any credential that has appeared in source control, chat, logs, or screenshots.

# Gemini AI integration

Gemini is an optional AI adapter and is disabled by default. Timeouts, caught exceptions, privacy-filtered requests, and local event recording ensure an unavailable provider does not roll back or disable core HR transactions.

Integration attempts appear under **Integrations** and are stored in `integration_events`. Secrets are never stored there.

## Configuration and usage

Gemini receives only aggregate workforce metrics; employee names, email addresses, reasons, attachment data, and other identifying fields are excluded.

```dotenv
GEMINI_ENABLED=true
GEMINI_API_KEY=your-server-side-key
GEMINI_MODEL=gemini-3.5-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
```

Keep the key server-side and restrict it in Google Cloud where available. Open **Analytics** and select **Generate AI insight**, or enable Gemini explanations from **Integrations** for the advisory scheduling assistant. Laravel still performs the employee ranking; Gemini only explains the privacy-filtered result. If Gemini fails, normal charts, metrics, and manual scheduling remain available and the UI shows a warning.

## Configuration lifecycle

After changing `.env`, run:

```bash
php artisan optimize:clear
```

In production, use a secrets manager or protected environment configuration. Rotate any credential that has appeared in source control, chat, logs, or screenshots.

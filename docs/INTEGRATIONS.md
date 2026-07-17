# Gemini AI integration

Gemini is an optional AI adapter and is disabled by default. Timeouts, caught exceptions, privacy-filtered requests, and local event recording ensure an unavailable provider does not roll back or disable core HR transactions.

Integration attempts appear under **Integrations** and are stored in `integration_events`. Secrets are never stored there.

## Secure activation

Gemini receives only aggregate workforce metrics; employee names, email addresses, reasons, attachment data, and other identifying fields are excluded.

1. Create a dedicated Gemini authorization key in Google AI Studio. Restrict it to the Gemini API and do not reuse a key from another application.
2. Add the following values only to the local or server `.env`; never commit the real key:

```dotenv
GEMINI_ENABLED=true
GEMINI_API_KEY=your-server-side-key
GEMINI_MODEL=gemini-3.5-flash
GEMINI_BASE_URL=https://generativelanguage.googleapis.com/v1beta
```

3. Run `php artisan optimize:clear` after changing `.env`.
4. Sign in as a System Administrator, open **Integrations**, and select **Test Gemini connection**. The test validates the key and model without sending employee data or generating AI content.
5. On the same page, enable **AI Scheduling Assistant** and **Use Gemini explanations**, then save. Open **Analytics** and select **Generate AI insight** for an end-to-end content-generation test.

Keep the key server-side. Laravel still performs employee eligibility and ranking; Gemini only explains the privacy-filtered result. If Gemini fails, normal charts, metrics, and manual scheduling remain available and the UI shows a warning.

## Configuration lifecycle

After changing `.env`, run:

```bash
php artisan optimize:clear
```

In production, use a secrets manager or protected environment configuration. Rotate any credential that has appeared in source control, chat, logs, or screenshots.

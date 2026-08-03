# Two-factor authentication

The web sign-in flow requires an RFC 6238 TOTP code after password validation. Secrets are encrypted at rest; recovery codes are individually hashed and become unusable immediately after use.

Run `php artisan migrate --force` during deployment. Production must use HTTPS, set `APP_DEBUG=false`, and explicitly set `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax` (or `strict` if compatible with the deployment).

HR managers and system administrators must complete the organisation's identity-verification procedure before selecting the confirmation checkbox to reset an employee's 2FA. Resetting removes recovery codes, revokes database sessions and API tokens, and requires enrollment on the next sign-in.

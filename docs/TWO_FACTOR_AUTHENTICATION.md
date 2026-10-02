# Two-factor authentication

The web sign-in flow requires an RFC 6238 TOTP code after password validation. Secrets are encrypted at rest; recovery codes are individually hashed and become unusable immediately after use.

Run `php artisan migrate --force` during deployment. Production must use HTTPS, set `APP_DEBUG=false`, and explicitly set `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `SESSION_HTTP_ONLY=true`, and `SESSION_SAME_SITE=lax` (or `strict` if compatible with the deployment).

HR managers and system administrators must complete the organisation's identity-verification procedure before selecting the confirmation checkbox to reset an employee's 2FA. Resetting removes recovery codes, revokes database sessions and API tokens, and requires enrollment on the next sign-in.

## The phone that does not type a code

A handset that has set up the device app lock — the 6-digit PIN and optional
fingerprint in Settings, implemented in `resources/js/app-lock.js` — signs in with
a password and then that lock, instead of a password and an authenticator code.

The reasoning is that on a phone the code is the first factor asked twice. The PIN
or fingerprint stands in front of the app on every launch and every return from
the background, and the authenticator the code would be read out of is on the same
locked handset — so it proves nothing a second time, and it is asked of a nurse
one-handed at the start of a shift. A computer has no app lock to stand in for
anything and is untouched: the same account still types a code there.

### What the skip requires

Two things, both of which have to hold. Losing either brings the code back:

| | What it is | What destroys it |
|---|---|---|
| Device cookie | `hrms_device`, httpOnly, minted by the server and kept a year. Recorded as a SHA-256 hash. | Clearing cookies; a different browser or handset. |
| Trust token | 64 random characters the server issues once when the lock is set up, kept in that device's `localStorage`. Recorded as an HMAC-SHA-256 digest keyed on `APP_KEY`. | Removing the app lock; clearing site data; uninstalling the app. |

The cookie alone would survive a wiped app lock, so a handset with nothing left
guarding it would go on skipping the code. The token alone would travel: copied
into another browser, it would be enough. Requiring both means the skip lasts
exactly as long as the lock it stands for. The request must also come from a phone
or tablet, so the skip cannot be replayed from a desktop browser.

### What is not stored

No PIN, no PIN digest, no salt, and no WebAuthn credential. The app lock has no
server endpoint and must never acquire one; `tests/Feature/Pwa/DeviceLockTest.php`
asserts that, and `TrustedMobileDeviceTest` re-asserts it against the routes this
feature added. The arming request carries an empty body — the server already knows
who is asking from the session and which device from the cookie.

### When the trust ends

- The employee removes the app lock, or clears this browser's data.
- A system administrator resets the account's two-factor enrollment. That is the
  action taken when a phone is lost, so every trusted device on the account is
  dropped — otherwise the lost handset would be the one device still able to sign
  in without a code.
- `MOBILE_TRUST_DAYS` (default 180) elapses without a sign-in. The lock is what
  normally ends a trust and a phone in a drawer never removes its lock, so the
  clock is the only thing that will.

## Mobile is employee-only

The roles in `MOBILE_RESTRICTED_ROLES` (default: system administrator, HR manager,
department head) cannot use this app on a phone or tablet at all, and are never
offered the installable app. An account holding both `employee` and one of those
roles is refused: roles add reach rather than average it out.

An HR manager's session reaches every employee record in the hospital and a system
administrator's reaches the audit log. A device-local PIN is the right safeguard
for one employee's own records and the wrong one for the whole workforce, so those
accounts stay on a hospital computer. Staff who only ever see their own roster,
attendance and leave are unaffected on every device.

Enforced in four places, because no one of them is sufficient:

1. `LoginRequest` refuses the sign-in, after the password rather than before it —
   before it, a phone would become a way to ask "is this employee ID an HR
   manager's?" without knowing anything else.
2. `RestrictMobileAccessByRole` ends any session that reaches the app from a
   handset, which covers a session opened before this rule existed and one started
   on a computer whose cookies were carried to a phone.
3. Blade withholds `<link rel="manifest">` and the `apple-mobile-web-app-*` tags,
   which is the only thing that actually prevents an install: suppressing the
   app's own banner would leave Chromium's address-bar button and its menu entry
   in place, and both read the manifest directly.
4. `mobile-access.js` catches the one device the server cannot. Since iPadOS 13,
   Safari asks for the desktop site by default and sends a user agent identical to
   a Mac's; no header distinguishes them, so the browser — which can see the
   pointer type — signs the session out itself.

Setting `MOBILE_RESTRICTED_ROLES` to an empty value lifts the restriction, which
is how an administrator account is tested on a real handset.

The `/api/v1` routes are deliberately not covered: they authenticate with Sanctum
tokens rather than a browser session, and a mobile API client is a separate
decision from a mobile browser.

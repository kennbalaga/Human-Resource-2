# Biometric attendance integration

The HRMS is prepared for fingerprint attendance without depending on a hardware vendor. The current implementation stores attendance sources, device identities, enrollment mappings, and normalized scan events. It does not store fingerprint images or biometric templates.

## Capture modes

- `biometric_only`: accepts biometric events and blocks manual website check-in/out.
- `hybrid`: accepts both biometric events and manual website attendance. This is the migration-safe default so existing installations are not interrupted.
- `emergency_manual`: temporarily pauses biometric capture and allows manual website attendance. An administrator must supply an expiry and reason, and employees must explain each manual entry. After expiry, the effective mode fails closed to `biometric_only`.

Only a System Administrator can change the mode. Write-request auditing records every settings request.

## Adapter boundary

A future vendor adapter should authenticate the physical device, validate the vendor payload, and pass a normalized event to `App\Services\BiometricAttendanceGateway::receive()`:

- `device`: a configured active `BiometricDevice` mapped to one office location.
- `providerEventId`: a stable ID from the device. Retries must reuse the same ID.
- `externalUserId`: the device identity mapped through `BiometricEnrollment`.
- `eventType`: `check_in` or `check_out`.
- `capturedAt`: the timestamp recorded by the device, including the correct timezone.
- `metadata`: optional non-biometric operational details. The gateway retains only `match_score`, `verification_mode`, `offline_sync`, and `simulated`.

The gateway rejects inactive devices, unsupported events, timestamps more than five minutes in the future, unmatched/inactive employees, and events disallowed by the capture mode. The unique device/event constraint makes device retries idempotent.

## Local simulator

System Administrators can use **Account Settings → Biometric scanner simulator** in `local` and `testing` environments. The simulator creates a fake device and enrollment, then submits an event through the same gateway used by a future hardware adapter. The route returns 404 outside local/testing environments and cannot be enabled through a browser setting.

## ZKTeco bridge agent adapter

The first real adapter. The installed terminal (ZKTeco ZK3969, comm firmware
6.60) has no cloud-push firmware and sits behind hospital NAT, so it cannot
reach this application and this application cannot reach it. A Python agent on
a PC inside the hospital LAN polls the terminal over TCP 4370 and posts
batches here. See `docs/BIOMETRIC_HANDOFF.md` for the hardware findings and the
rationale for rejecting ADMS push.

```
[ZK3969] --TCP 4370--> [bridge PC] --HTTPS--> POST /api/biometric/punches
                                                      |
                                        VerifyBiometricBridgeSignature
                                        StoreBiometricPunchBatchRequest
                                        BiometricPunchIngestionService
                                                      |
                                         biometric_punches (raw, immutable)
                                                      |
                                        BiometricAttendanceGateway::receive()
```

### Endpoint

`POST /api/biometric/punches` — deliberately outside `/api/v1`. The agent is
hospital-side machine infrastructure rather than a client of the public API,
it authenticates by body signature rather than a Sanctum token, and the path
is already fixed by the deployed agent. It is not listed in `docs/API.md` for
those reasons.

| Header | Meaning |
|---|---|
| `X-Bridge-Signature` | HMAC-SHA256 of the raw request body, keyed with `BIOMETRIC_BRIDGE_SECRET` |
| `X-Bridge-Device` | Terminal serial number; must equal the body's `device_sn` |

```json
{
  "device_sn": "QME2261300147",
  "sent_at": "2026-10-01T14:45:00",
  "punches": [
    {
      "fingerprint": "sha256 hex ...",
      "pin": "1001",
      "punched_at": "2026-10-01 07:02:11",
      "punch_code": 0,
      "verify_mode": 1
    }
  ]
}
```

Responds `202` with a per-punch status once the batch is committed, `401` on a
missing or bad signature, `422` on an unregistered serial or a malformed
payload, `429` when rate limited, `503` when the secret is unset. The agent
retries anything that is not 2xx, so only a committed batch answers 2xx.

### Why ingestion is two phases

`BiometricPunchIngestionService` persists every raw punch first, in one
transaction, and only then derives attendance from each punch separately. A
derivation that can never succeed — an unmapped punch code, a PIN nobody has
enrolled — is recorded as a status on the punch row rather than returned as an
error, because a non-2xx would put the agent into a retry loop it could never
escape while the punch is already safely stored.

`processing_status` values: `pending`, `processed`, `unmatched` (no enrollment
for that PIN), `rejected` (the gateway declined it), `unsupported` (punch code
not mapped to a direction), `stale` (timestamp older than
`BIOMETRIC_BRIDGE_MAX_PUNCH_AGE_HOURS`).

### Recovering punches that never became attendance

```
php artisan biometric:replay --dry-run
php artisan biometric:replay
php artisan biometric:replay --status=stale --since=2026-10-01
```

Because a 2xx is what stops the agent retrying, a punch this application cannot
place is stored with a status rather than refused. `biometric:replay` is the
other half of that bargain: it reads those punches back and pushes them through
the same derivation the endpoint uses, now that whatever blocked them is fixed.
Nothing is fetched from the terminal, so a replay cannot invent a scan — at
worst it fails the same way twice.

Two cases it exists for. Enrollment runs ward by ward, so staff whose ward is
not done yet still try the terminal and their punches land `unmatched`; once
their enrollment exists, those days are recoverable only from here. And if the
`punch_codes` mapping turns out wrong, a run of check-outs lands `unsupported`
— correcting the config is one line, and the replay is what turns that back
into attendance instead of hand-keying it.

Default statuses are `unmatched`, `unsupported` and `pending`. `rejected` and
`stale` can be named explicitly with `--status`; `processed` never can.

**`stale` is deliberately not in the default set.** A stale punch means either a
device clock that has drifted — where replaying writes fiction into the
time-and-attendance record — or a genuine outage backlog, where replaying
recovers work people actually did. The row cannot tell you which, so naming it
has to be a decision somebody makes rather than something a sweep does for them.

The command is **not scheduled**, on purpose: a replay follows a fix, and
running one on a timer would re-attempt the same stuck punches nightly and
bury the signal that something still needs attention.

### Raw punches are separate from attendance

`biometric_punches` is what the device said; `attendance_records` is what the
system derived and HR then approved. Correcting or rejecting attendance never
rewrites the underlying punch. Templates are not stored — only the PIN, the
instant, the verify mode and the device serial (RA 10173; see
`docs/DATA_PRIVACY.md`).

### PIN reserved vs. fingerprint captured

Two states, and the page keeps them apart on purpose.

A **reserved PIN** is a `biometric_enrollments` row: the roster knows which
number to key in for this person. A **captured fingerprint** is `enrolled_at`
set: somebody stood at the terminal and took their finger. Reserving PINs for
everybody creates a row per active employee, so treating row existence as
"enrolled" would tell several hundred staff their finger was on a device that
has never seen them — and that card exists precisely so an unenrolled nurse
learns this before her shift rather than at the door.

`enrolled_at` is filled either by HR marking it on the roster, or on its own:
the first punch that resolves to a person is proof the template is genuinely on
the terminal, so the roster heals itself without anybody ticking a box. The
gateway does not check `enrolled_at`, so a finger that is on the device works
whether or not the roster has caught up.

The PIN is the employee's own record id. The terminal's PIN field is numeric and
short, so DJNRMHS employee numbers (`NUR-HEAD-OPD-2026-0009`) do not fit. One
consequence worth stating: the PIN space is dense, so a one-digit slip at the
terminal lands on another real enrolled colleague. There is no check digit. The
controls that remain are `enforce_published_shift`, the approval queue, and the
employee being able to read their own PIN off their dashboard.

### Creating the users with ZKTime

The roster page exports two files. The **enrolment sheet** is for a person to
carry to the terminal. The **ZKTime import file** is for ZKTime 5.0's
*Import data wizard*, which maps source columns onto its own Employee List
fields, so the headers are named exactly as that dialog names them:

| Column | Holds |
|---|---|
| `AC No.` | the device PIN — the employee's record id |
| `Name` | "Last, First", one column, because ZKTime holds a single name field |
| `No.` | the DJNRMHS employee number, which does not fit `AC No.` |
| `Title` | the position |
| `Department` | which node ZKTime files the user under |
| `Privilege` | always `User` |

Only employees who already hold an active enrollment here are in it. A terminal
must not know a user this roster has not reserved a PIN for: the two would
disagree, and that user's punches would arrive `unmatched` with nothing to
explain them.

ZKTime's Employee List carries many more columns — `AccGroup`, `Verify`,
`TimeZone1`–`3`, `ValidTimeBegin`/`End`, `IDCardNo`, `Password` and the rest.
They are deliberately not exported. They are access-control settings belonging
to the device rather than facts this application holds, and filling them would
be the HR roster quietly deciding who may pass a door and when.
`FingerCountV9` and `FingerCountV10.0` could not be supplied even in principle —
they count templates the device itself holds. The wizard maps columns, so the
ones it is not given are left alone.

`Privilege` is the exception, and is written on every row. Its other values make
somebody an administrator **of the terminal** — able to enroll, delete users and
open its menus. Nobody should acquire that by being imported from an HR list,
and the comm key on this unit is still the factory default.

**Create the departments in ZKTime first**, under Maintenance/Options →
Department List, with names matching the HRMS ones. `Department` is not
cosmetic: *From PC To Device* filters by department, so a user imported without
one is filed under no node and the upload finds nobody to send — the dialog
simply shows an empty user list, which reads as "nothing to upload" rather than
as an error. If they have already been imported without a department, select
them in the Employee List and move them with its `transfer` button.

**Check the device capacity before importing.** ZKTime shows it in the
*From PC To Device* device panel as `UserCount/Capacity`. On this unit it reads
`0/200`, which is fewer than the hospital's active headcount — so the terminal
cannot hold everybody and somebody has to decide who it holds, or a second
terminal is needed. This is not recorded in the hardware facts in
`docs/BIOMETRIC_HANDOFF.md` and should be.

**Two wizard settings to get right.** Set *Comma* to `,` and *Quote* to `"`.
The `Name` column contains a comma ("Dela Cruz, Juan"), so with the wrong quote
character every column shifts by one and `AC No.` silently takes the wrong
value — which is the failure that misfiles attendance. The wizard previews the
mapping before it executes: check that `AC No.` really holds the number before
pressing Execute.

Leave **IDCardNo as AC No.** unticked. The PIN must come from this roster.

The path is Employee List → Import → CSV, then **Machine → Upload user info and
FP** to push the users to the terminal. That creates the *user records* only —
**a fingerprint still has to be captured from the real finger at the device**.
What it removes is the typing of each PIN in front of each person, which is
where a mis-keyed PIN comes from and which the PIN scheme has no check digit to
catch. Worth doing for that reason alone.

A template that has been captured can afterwards be copied to another terminal
(**Download user info and Fp**, then upload to the second device), so a
replacement or a second door does not mean enrolling everybody again.

> **Do not use ZKTime's "Download attendance logs" while the bridge agent is
> running.** The agent never clears the device log and relies on punches staying
> there until it has collected them. Software that downloads and then clears
> would take punches the agent has not seen yet — and those are unrecoverable,
> because they never reached `biometric_punches` and so `biometric:replay`
> cannot bring them back. Use ZKTime for enrollment; leave attendance to the
> bridge.

### Before go-live

- Set `BIOMETRIC_BRIDGE_SECRET` on both sides (`openssl rand -hex 32`). Unset
  refuses every request; there is no unsigned fallback.
- Register the terminal at **Settings → Operational tools → Biometric
  terminals**, with `provider = zkteco`, the real serial and the office it
  stands in. An unregistered serial is refused — registering is the allowlist.
- Reserve PINs on that page, then capture each person's fingerprint at the
  terminal. "Reserve PINs for all active staff" does the roster in one go;
  export the CSV and take it to the device. Punches for a PIN with no
  enrollment land as `unmatched`; recover them with `biometric:replay` once the
  enrollment exists, which is what makes a ward-by-ward rollout safe.
- **Turn on `schedule_aware` and `enforce_published_shift`** once punches are
  flowing and matching. This is the strongest control there is against a
  mis-keyed PIN: a typo'd PIN almost always belongs to somebody not rostered
  for that hour, so the punch is refused as "No published shift covers this
  punch" instead of being filed against the wrong person. Not on day one,
  though — enabled before schedules are reliably published it rejects almost
  everything.
- **Confirm `punch_code` and `verify_mode` against the real unit.** No users
  were enrolled when the terminal was tested, so the values it emits for face
  versus fingerprint, and for in versus out, have never been observed. The
  defaults in `config/attendance.php` are the ZKTeco conventions, not measured
  facts. Enroll a test user, scan both ways, and correct
  `attendance.biometric_bridge.punch_codes` before trusting the derived
  records. Mismapped codes surface as `unsupported` punches, or — the case to
  watch for — as a terminal that reports `0` for every scan and so produces
  check-ins with no check-outs.
- Change the device comm key from the factory default `0`.
- Keep the terminal clock synchronized. The agent warns past 120 seconds of
  drift, and punches beyond the age window are held as `stale` rather than
  silently backdating attendance.

## Hardware integration checklist

Before implementing an adapter, confirm that the device supports:

- 1:N fingerprint identification;
- a documented SDK, API, or push protocol;
- stable event IDs and offline event replay;
- device authentication and secure LAN/server transport;
- employee identity mapping;
- correct clock and timezone synchronization;
- device serial number and office mapping;
- liveness/anti-spoof controls;
- template storage and deletion without exposing raw fingerprint images.

Do not put device credentials in the `configuration` JSON column. Store secrets using the deployment secret manager or environment-specific encrypted storage when the vendor is selected.

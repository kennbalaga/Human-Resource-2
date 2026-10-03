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

### Raw punches are separate from attendance

`biometric_punches` is what the device said; `attendance_records` is what the
system derived and HR then approved. Correcting or rejecting attendance never
rewrites the underlying punch. Templates are not stored — only the PIN, the
instant, the verify mode and the device serial (RA 10173; see
`docs/DATA_PRIVACY.md`).

### Before go-live

- Set `BIOMETRIC_BRIDGE_SECRET` on both sides (`openssl rand -hex 32`). Unset
  refuses every request; there is no unsigned fallback.
- Register the terminal as a `BiometricDevice` with `provider = zkteco`, the
  real `serial_number`, and the office location it stands in. An unregistered
  serial is refused — this is the allowlist.
- Enroll each person on the terminal and create the matching
  `BiometricEnrollment` with the device PIN as `external_user_id`. Punches for
  an unmapped PIN land as `unmatched` and can be replayed once the enrollment
  exists.
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

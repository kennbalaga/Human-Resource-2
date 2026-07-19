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

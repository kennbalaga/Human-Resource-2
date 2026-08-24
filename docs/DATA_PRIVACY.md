# Data privacy posture (RA 10173 — Data Privacy Act of 2012)

This document exists because an enterprise-readiness review of this codebase found no data-privacy or retention posture written down anywhere, despite the system storing employee personal data including geolocation. It is a starting point, not a finished compliance program: the "proposed" sections below are engineering proposals grounded in common Philippine HR/payroll practice, not legal advice, and should be confirmed with your organization's compliance officer or counsel before being treated as policy. Where a number needs sign-off, it's flagged.

## What this system actually stores

Confirmed by reading every migration, not assumed:

| Category | Fields | Table(s) |
|---|---|---|
| Identity | first/middle/last name, suffix | `employees` |
| Contact | phone number, address | `employees` |
| Location | check-in/out latitude, longitude | `attendance_records` |
| Location (config) | office latitude, longitude, geofence radius | `office_locations` |
| Network/device | check-in/out IP address, user agent | `attendance_records` |
| Network/device | request IP, user agent | `audit_logs`, `users` |
| Attendance credential | per-employee QR-signing secret (already `hidden` on the model — never serialized) | `employees.attendance_qr_secret` |
| Biometric linkage | vendor-issued `external_user_id`, enrollment/scan event metadata | `biometric_enrollments`, `biometric_scan_events` |

**What's deliberately *not* in this list because it isn't in the schema**: no government IDs (SSS, PhilHealth, TIN, Pag-IBIG), no emergency-contact fields, and — despite the "biometric" naming — **no raw fingerprint or face template data**. `biometric_enrollments`/`biometric_scan_events` store only a vendor-issued reference id and event metadata; the actual biometric template, if any, lives on the vendor's device/appliance, not in this application's database. This is a meaningfully smaller PII surface than a typical Philippine HR system, and worth knowing plainly rather than assuming the worst.

## Purpose and lawful basis

Every field above exists to serve one of two purposes this application is actually built for:

- **Attendance tracking**: geolocation, IP, and device fields exist to verify a punch happened at a real workplace at a real time, and to detect anomalous/fraudulent check-ins (the geofencing and duplicate-scan-window logic elsewhere in the app both depend on this data existing).
- **Workforce scheduling and payroll input**: names, contact info, and the employment relationship itself are the minimum needed to schedule someone and pay them correctly.

Nothing is collected for a purpose beyond these two. If a future feature wants to collect something new (emergency contacts, a government ID for payroll remittance, etc.), that's the moment to extend this table, not to add a column quietly.

## Proposed retention periods — confirm with your organization before treating as policy

| Data | Proposed retention | Basis |
|---|---|---|
| Attendance records (including geolocation/IP) | 3 years from the attendance date | General Labor Code employment-record retention expectation |
| Timesheet / payroll-adjacent data | 3 years from the pay period | Same, plus typical BIR record-retention practice for payroll-supporting documents |
| Leave requests and attachments | 3 years from the leave end date | Same Labor Code basis; attachments may contain medical information, so access stays restricted regardless of age until deletion |
| Audit logs (`audit_logs`, `schedule_assignment_audits`) | Longer than the above — proposed 5 years | These exist specifically to reconstruct what happened during a dispute or investigation; deleting them on the same clock as the records they audit defeats the purpose |
| Biometric enrollment/scan metadata | Tied to employment — proposed: deleted when the enrollment is deactivated, not kept indefinitely after | No ongoing purpose once an employee no longer uses the device |

None of these periods are enforced by the application today (see below) — they are a proposal to adopt, not a description of current behavior.

## Data-subject rights — not implemented

RA 10173 gives data subjects rights to access, correct, and in some cases request erasure of their personal data. **No self-service mechanism for any of this exists in the application today** — no "export my data," no "delete my account" flow, and no consent-tracking field or table anywhere in the schema.

Building a real erasure flow is deliberately **out of scope for this pass**, because it isn't a pure engineering problem: an employee's attendance and payroll records are simultaneously personal data *and* records the Labor Code expects an employer to retain for a period. "Erasure" for this system has to mean something more specific than "delete the row" — e.g., anonymizing the geolocation/IP fields while keeping the attendance fact itself, or handling the request differently depending on whether the employment relationship has ended. That's a policy decision this document can't make on your organization's behalf; it needs a decision before it can become a code change.

## What this pass actually adds

A retention *mechanism* — not a decision. `config/privacy.php` defines per-table retention windows, each read from an environment variable with **no default that enables anything**:

```php
'retention_days' => [
    'attendance_records' => env('PRIVACY_RETAIN_ATTENDANCE_DAYS'),   // null = disabled
    'leave_requests' => env('PRIVACY_RETAIN_LEAVE_DAYS'),            // null = disabled
    'audit_logs' => env('PRIVACY_RETAIN_AUDIT_LOGS_DAYS'),           // null = disabled
],
```

A new Artisan command, `records:prune-expired`, reads this config and deletes rows past the configured window for any table whose window is actually set — mirroring the existing `notifications:prune` command already in `routes/console.php`. **It is not scheduled.** Nothing in this application will delete a single row of attendance, leave, or audit data as a result of this change. Turning it on is a one-line addition to `routes/console.php` (`Schedule::command('records:prune-expired')->daily();`) — deliberately left for a human to do once your organization has actually confirmed the numbers in the table above, not assumed by this document.

## Cross-reference

`docs/SECURITY.md` already flags this gap in one sentence: "Audit and integration tables grow over time. Define an organization-approved retention policy and archive/delete old records through a reviewed scheduled command; do not silently purge legally required audit data." This document is that policy's first draft; the command above is that "reviewed scheduled command," unscheduled until it's actually reviewed.

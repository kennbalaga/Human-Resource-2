# Biometric Integration — Handoff Brief

Context for implementing the ZKTeco time & attendance integration
in `kennbalaga/Human-Resource-2`.

---

## 1. Hardware facts (verified on the physical device, 2026-10-01)

| Item | Value |
|---|---|
| Model | ZKTeco ZK3969 (MB20), face + fingerprint |
| Device name (reported) | `ZK3969` |
| Serial number | `QME2261300147` |
| Comm firmware | `Ver 6.60 Sep 27 2019` |
| Platform | `ZLM60_TFT` |
| MAC | `00:17:61:12:92:50` |
| IP (test network) | `192.168.1.201` — will change at hospital |
| Subnet | `255.255.255.0`, DHCP off |
| Port | `4370`, TCP confirmed open |
| Comm key | `0` (factory default — change before go-live) |
| Device ID | `1` |

Note: the terminal's on-screen menu displays `8.0.3.2-20230131`, but the
communication firmware reported over the protocol is `6.60`. The protocol
value is the one that governs capability.

---

## 2. Architecture decision: pull via bridge agent, NOT ADMS push

**Rejected: ADMS / Push SDK.** This device has no `Cloud Server Setting`
menu under `Comm.`, confirmed both by inspecting the device menu and by
its firmware generation. It cannot push to a server. Do not build
`/iclock/*` endpoints — they will never be called.

**Adopted: local bridge agent.** A Python agent runs on a PC inside the
hospital LAN. It polls the terminal over TCP 4370 using `pyzk`, and
forwards new punches to the Laravel app over HTTPS.

```
[ZK3969 terminal] --TCP 4370--> [bridge PC, hospital LAN]
                                        |
                                   HTTPS POST
                                        |
                                        v
                      [workforce.djnrmhs.com — Laravel]
                                        |
                           BiometricAttendanceGateway
                                        |
                              AttendanceService
                                        |
                        AttendanceRecord.approval_status
```

Rationale: the app is cloud-hosted (HostForge, MySQL on Railway) and the
terminal sits behind hospital NAT. The web server cannot reach the device
directly, and exposing a biometric terminal to the public internet is not
acceptable in a hospital setting or defensible to the ethics board.

---

## 3. The bridge agent (NOT written — corrected 2026-10-03)

**This section previously claimed `bridge_agent.py` and `bridge.env.example`
were complete. They do not exist.** Searched the project, Desktop, Documents,
Downloads and OneDrive on 2026-10-03: the only Python present is
`zk_test.py` in Downloads, which is a read-only diagnostic — it connects with
`pyzk`, prints device info, enrolled users and attendance logs, and sends
nothing anywhere.

Everything below is therefore the **specification** for an agent still to be
written, not a description of one that exists. It is accurate as a spec and the
Laravel side is built to meet it exactly. The agent must:

- polls the terminal on a configurable interval
- fingerprints each punch as `sha256(serial|pin|timestamp|punch_code)`
- tracks delivered punches in local SQLite, so restarts neither
  re-send nor lose data
- marks punches sent **only after** the server confirms, so failed
  uploads retry rather than vanish
- queues locally through internet outages and flushes on recovery
- signs every request with HMAC-SHA256 over the raw body
- warns when the device clock drifts more than 120 seconds
- never clears the device log

Useful groundwork that does exist: `zk_test.py` already proves the connection
approach, and carries the working device settings (`192.168.1.201:4370`, comm
key `0`, `ommit_ping=True`, `force_udp=False`) and a punch-code table to check
against reality.

Until the agent runs, **no attendance reaches the application at all**. The
terminal stores punches in its own log and cannot call out — that is the whole
reason this architecture was chosen over ADMS push. Verified end to end on
2026-10-03: a signed test batch posted by hand was accepted, stored and derived
correctly, so the server side is not what is missing.

---

## 4. What needs building (Laravel side)

### 4.1 Endpoint

`POST /api/biometric/punches`

Headers sent by the bridge:

| Header | Meaning |
|---|---|
| `X-Bridge-Signature` | HMAC-SHA256 of the raw request body |
| `X-Bridge-Device` | Terminal serial number |

Body:

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

Must return 2xx **only** when punches are durably stored. Any other
status causes the bridge to retry the same batch.

### 4.2 Required behaviour

- Verify the HMAC against `BIOMETRIC_BRIDGE_SECRET` using a
  timing-safe comparison. Reject with 401 on mismatch.
- Reject unknown `device_sn` values — keep an allowlist of registered
  terminals.
- Exclude this route from CSRF.
- Rate limit it.
- Treat the entire payload as untrusted input; validate every field.

### 4.3 Idempotency

Unique constraint on `fingerprint`. Upsert rather than insert. The
bridge may legitimately resend a batch if a response was lost in
transit — a duplicate punch must be a no-op, not a second record.

### 4.4 Integration point

The controller should do nothing but authenticate, validate, and hand
normalized punch events to the existing `BiometricAttendanceGateway`.
Everything downstream — `AttendanceService`, `AttendanceRecord`,
`approval_status`, `AttendanceApprovalController`, `TimesheetService` —
stays unchanged. Do not let protocol concerns leak past the gateway.

### 4.5 Suggested table

`biometric_punches`: `fingerprint` (unique), `device_sn`, `pin`,
`employee_id` (nullable FK), `punched_at`, `punch_code`,
`verify_mode`, `received_at`, `processed_at`, `processing_status`.

Keep the raw punch record separate from `AttendanceRecord`. Raw
punches are an immutable audit trail; attendance records are derived
and subject to HR approval. The ethics board will care about this
distinction.

---

## 5. Data privacy constraint (RA 10173)

**Fingerprint and face templates stay on the terminal.** They are never
pulled into the application database. The system stores only the
PIN-to-employee mapping and punch events (timestamp, verify mode,
device serial).

This is a hard requirement, not a preference. Biometric data is
sensitive personal information under the Data Privacy Act of 2012, and
"templates never leave the device" is the position already taken in the
IREB protocol.

---

## 6. Open questions — resolve before building

1. ~~**PIN to employee mapping.**~~ **RESOLVED (2026-10-03): the PIN is the
   employee's record id.** The preferred option here — reusing the DJNRMHS
   employee number — is not available: employee numbers read
   `NUR-HEAD-OPD-2026-0009` (position code, hire year, sequence; see
   `EmployeeNumberGenerator`), which is alphanumeric and around twenty
   characters, while the terminal's PIN field is numeric and short. So the
   mapping table is the answer, and `biometric_enrollments` already is one:
   `external_user_id` holds the PIN. Using `employees.id` as that PIN means no
   separate sequence to keep in step, uniqueness for free, and PINs that carry
   over unchanged if the terminal is ever replaced. Managed at Settings →
   Operational tools → Biometric terminals. The cost, recorded rather than
   glossed: the PIN space is dense and there is no check digit, so a mis-keyed
   PIN lands on a real colleague — see the controls listed in
   `docs/BIOMETRIC_ATTENDANCE.md`.
2. **Terminal capacity against headcount.** ZKTime reports this unit as
   `0/200` users. The hospital has roughly 300 active employees, so the
   terminal cannot hold them all. Either a second terminal is needed or
   somebody has to decide which staff it holds -- a hospital decision, not a
   technical one. Confirm the real figure on the device menu first: a face+
   fingerprint unit often quotes different limits for each, and `200` may be
   one of those rather than the user ceiling. This was not among the hardware
   facts recorded in section 1 and should have been.
3. **Which PC hosts the bridge**, and whether it stays powered on.
   Always-on allows a 60-second interval; otherwise use a longer
   interval via Task Scheduler.
4. **Terminal's IP on the hospital network** — `192.168.1.201` was the
   test network address and will likely differ at DJNRMHS.
5. **Punch code semantics.** No users were enrolled at time of testing,
   so the actual `punch_code` and `verify_mode` values this unit emits
   for face vs. fingerprint have not been observed. Enroll a test user,
   scan both ways, and confirm against real output before finalizing
   the mapping.

---

## 7. Scope note for the manuscript

This is device *integration*, not biometric hardware development. It
stays inside the stated out-of-scope boundary. Worth making explicit in
Chapter 3 so the distinction is not misread during defense.

Clock synchronization also deserves a line in the methodology — a
drifting terminal clock silently corrupts every timestamp, which bears
directly on the Q2 time-and-attendance accuracy claim.

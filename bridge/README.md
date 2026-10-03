# Biometric bridge agent

Forwards punches from the ZKTeco ZK3969 terminal to the WorkForce HRMS.

The terminal cannot reach the application: it has no cloud-push firmware and
sits behind hospital NAT, so it stores punches in its own log and waits to be
asked. This agent is what asks. **Until it runs, no attendance reaches the
application at all**, however many fingerprints are enrolled.

```
[ZK3969 terminal] --TCP 4370--> [this agent, hospital LAN PC]
                                          |
                                     HTTPS POST, signed
                                          |
                                          v
                      [workforce.djnrmhs.com /api/biometric/punches]
```

## Install

On a PC inside the hospital LAN that can reach the terminal:

```
pip install -r requirements.txt
cp bridge.env.example bridge.env
```

Then fill in `bridge.env`. The two that must be right:

- `BRIDGE_DEVICE_IP` — the terminal's address **on the hospital network**.
  `192.168.1.201` in the example was the test network and will differ.
- `BRIDGE_SECRET` — must equal `BIOMETRIC_BRIDGE_SECRET` in the application's
  `.env`, exactly. One character out and every batch comes back `401`.

## Run

```
python bridge_agent.py              # polls forever, for an always-on PC
python bridge_agent.py --once       # one cycle, for Task Scheduler
python bridge_agent.py --dry-run    # read and report, writing nothing
```

Start with `--dry-run`. It prints what the terminal is actually reporting
without touching the queue or the server, which is also the quickest way to
see the real `punch_code` and `verify_mode` values this unit emits.

**Close ZKTime first.** Only one program can hold the terminal's connection.

For a machine that is not always on, Task Scheduler running `--once` every few
minutes is the better shape than a long-lived process.

## What it guarantees

**Nothing is lost.** A punch is written to `bridge_state.sqlite3` the moment it
is read, before any network call. The device log is never cleared, so even
losing that file costs only a re-read.

**Nothing is sent twice.** Each punch is keyed by
`sha256(serial|pin|timestamp|punch_code)`. That key is the primary key locally
and carries a unique constraint on the server, so a duplicate is a no-op on
both sides.

**A punch is only finished when the server says so.** `delivered_at` is written
after a 2xx and not before. A refused signature, a validation error, a dead
link — all leave the row pending to go again next cycle.

**An outage is survivable.** Punches accumulate locally while the link is down
and flush in batches when it returns.

## What it will not do

It never clears the device log, never writes users and never changes device
settings. It only reads. ZKTime is the tool for enrolment.

## Reading the log

`unmatched`, `unsupported` or `stale` in a delivery summary means the punch was
**stored but not turned into attendance**. That is recoverable — fix the cause,
then on the server:

```
php artisan biometric:replay --dry-run
php artisan biometric:replay
```

| Status | Means | Fix |
|---|---|---|
| `unmatched` | no enrollment for that PIN | reserve the PIN in Settings → Biometric terminals, then replay |
| `unsupported` | punch code not mapped to a direction | correct `attendance.biometric_bridge.punch_codes`, then replay |
| `stale` | timestamp older than the server's window | check the terminal clock; replay with `--status=stale` only if the age is genuine |

## When something is refused

| Code | Meaning |
|---|---|
| `401` | `BRIDGE_SECRET` does not match the server's `BIOMETRIC_BRIDGE_SECRET` |
| `422` | the terminal is not registered in Settings → Biometric terminals, or its serial differs |
| `429` | rate limited — raise `BRIDGE_POLL_SECONDS` or lower `BRIDGE_BATCH_SIZE` |
| `503` | the server has no `BIOMETRIC_BRIDGE_SECRET` set and refuses everything |

The agent keeps retrying in every case, because only a 2xx means a punch is
safely stored. Nothing is lost while a problem is being fixed.

## Watch the device log size

Because the log is never cleared, it grows. The terminal holds a finite number
of records and will eventually overwrite the oldest. Two hundred staff punching
twice a day is roughly 400 records a day, so there is a long runway — but check
it periodically, and only clear the log once you have confirmed everything is
delivered (`SELECT COUNT(*) FROM punches WHERE delivered_at IS NULL` is zero).

## Not yet confirmed on this unit

The `punch_code` values the terminal emits for check-in versus check-out have
never been observed — no users were enrolled when it was tested. The server's
mapping is the ZKTeco convention, not a measurement. Run `--dry-run` after a
few real scans and check the codes against
`config/attendance.php` → `biometric_bridge.punch_codes`.

The case to watch for: a terminal that reports `0` for every scan, which
produces check-ins with no check-outs.

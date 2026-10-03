#!/usr/bin/env python3
"""
Biometric bridge agent — ZKTeco ZK3969 (MB20) → WorkForce HRMS.

The terminal cannot reach the application. It has no cloud-push firmware and
sits behind hospital NAT, so it stores punches in its own log and waits to be
asked. This agent is what asks: it runs on a PC inside the hospital LAN, polls
the terminal over TCP 4370, and forwards new punches to the HRMS over HTTPS.

Until this runs, no attendance reaches the application at all, however many
fingerprints are enrolled.

What it guarantees, and how
---------------------------

*Nothing is lost.* A punch is written to a local SQLite file the moment it is
read from the device, before any network call. The device log is never cleared,
so even losing that file only means re-reading what the terminal still holds.

*Nothing is sent twice.* Each punch is keyed by a digest of the terminal
serial, the PIN, the timestamp and the punch code. That key is the primary key
of the local table and is also what the server stores as `fingerprint` with a
unique constraint, so a duplicate is a no-op on both sides.

*A punch is only finished when the server says so.* `delivered_at` is written
after a 2xx and not before. Anything else — a refused signature, a validation
error, a dead link, a 500 — leaves the row pending and it goes again next
cycle. That is also why the server answers 2xx only once a batch is durably
stored.

*An outage is survivable.* Punches accumulate locally while the link is down
and flush in batches when it returns.

Read-only against the terminal, deliberately: this agent never clears the
device log, never writes users and never changes settings. ZKTime is the tool
for enrolment; this one only reads.

Usage
-----
    pip install -r requirements.txt
    cp bridge.env.example bridge.env     # then fill it in
    python bridge_agent.py               # runs until stopped
    python bridge_agent.py --once        # one cycle, for Task Scheduler
    python bridge_agent.py --dry-run     # read and report, write nothing

Only one program may talk to the terminal at a time. Close ZKTime before
running this.
"""

import argparse
import hashlib
import hmac
import json
import logging
import os
import sqlite3
import sys
import time
from datetime import datetime
from pathlib import Path

try:
    import requests
except ImportError:
    sys.exit("requests is not installed.  Run:  pip install -r requirements.txt")

try:
    from zk import ZK
except ImportError:
    sys.exit("pyzk is not installed.  Run:  pip install -r requirements.txt")


HERE = Path(__file__).resolve().parent
LOG = logging.getLogger("bridge")

# The server refuses a batch larger than its own configured ceiling
# (attendance.biometric_bridge.max_batch_size, 500 by default), so never offer
# one bigger than that however the config is set.
SERVER_MAX_BATCH = 500


# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------

def load_config():
    """
    Read bridge.env beside this script, with real environment variables taking
    precedence so a service wrapper can override without editing the file.

    Parsed by hand rather than with python-dotenv to keep the install on the
    hospital PC down to two packages. A bridge nobody can install is not a
    bridge.
    """
    values = {}
    env_file = HERE / "bridge.env"

    if env_file.exists():
        for raw in env_file.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line or line.startswith("#") or "=" not in line:
                continue
            key, _, value = line.partition("=")
            values[key.strip()] = value.strip().strip('"').strip("'")
    else:
        LOG.warning("No bridge.env found at %s - relying on environment only.", env_file)

    def get(key, default=None, required=False):
        value = os.environ.get(key, values.get(key, default))
        if required and (value is None or value == ""):
            sys.exit(f"{key} is not set. Copy bridge.env.example to bridge.env and fill it in.")
        return value

    config = {
        "device_ip": get("BRIDGE_DEVICE_IP", required=True),
        "device_port": int(get("BRIDGE_DEVICE_PORT", "4370")),
        "comm_key": int(get("BRIDGE_DEVICE_COMM_KEY", "0")),
        "serial": get("BRIDGE_DEVICE_SERIAL", required=True),
        "server_url": get("BRIDGE_SERVER_URL", required=True),
        "secret": get("BRIDGE_SECRET", required=True),
        "poll_seconds": int(get("BRIDGE_POLL_SECONDS", "60")),
        "batch_size": min(int(get("BRIDGE_BATCH_SIZE", "200")), SERVER_MAX_BATCH),
        "state_db": get("BRIDGE_STATE_DB", str(HERE / "bridge_state.sqlite3")),
        "drift_seconds": int(get("BRIDGE_CLOCK_DRIFT_SECONDS", "120")),
        "timeout": int(get("BRIDGE_TIMEOUT_SECONDS", "30")),
        "device_timeout": int(get("BRIDGE_DEVICE_TIMEOUT_SECONDS", "10")),
        "log_file": get("BRIDGE_LOG_FILE", str(HERE / "bridge_agent.log")),
        "verify_tls": get("BRIDGE_VERIFY_TLS", "true").lower() != "false",
    }

    if config["batch_size"] < 1:
        sys.exit("BRIDGE_BATCH_SIZE must be at least 1.")

    return config


def setup_logging(log_file):
    LOG.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s  %(levelname)-7s %(message)s", "%Y-%m-%d %H:%M:%S")

    stream = logging.StreamHandler(sys.stdout)
    stream.setFormatter(fmt)
    LOG.addHandler(stream)

    try:
        handler = logging.FileHandler(log_file, encoding="utf-8")
        handler.setFormatter(fmt)
        LOG.addHandler(handler)
    except OSError as exc:
        LOG.warning("Could not open log file %s (%s) - logging to screen only.", log_file, exc)


# ---------------------------------------------------------------------------
# Local queue
# ---------------------------------------------------------------------------

def open_state(path):
    """
    The queue, and the reason a restart neither loses nor repeats work.

    `fingerprint` is the primary key, so re-reading the device log — which this
    agent does on every single poll, because it never clears it — costs one
    ignored insert per punch rather than a duplicate.
    """
    connection = sqlite3.connect(path)
    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS punches (
            fingerprint  TEXT PRIMARY KEY,
            pin          TEXT NOT NULL,
            punched_at   TEXT NOT NULL,
            punch_code   INTEGER NOT NULL,
            verify_mode  INTEGER,
            seen_at      TEXT NOT NULL,
            delivered_at TEXT
        )
        """
    )
    # The only query the send loop makes.
    connection.execute(
        "CREATE INDEX IF NOT EXISTS punches_pending_idx ON punches (delivered_at, punched_at)"
    )
    connection.commit()
    return connection


def fingerprint_for(serial, pin, punched_at, punch_code):
    """
    sha256(serial|pin|timestamp|punch_code).

    The serial is in the digest so two terminals cannot collide, and the whole
    thing is deterministic so the same physical punch produces the same key on
    every poll, on every restart, forever. The server stores it under a unique
    constraint and treats a repeat as a no-op, which is what makes a resent
    batch harmless.
    """
    payload = "|".join([serial, str(pin), punched_at, str(punch_code)])
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


# ---------------------------------------------------------------------------
# Device
# ---------------------------------------------------------------------------

# What the last quiet line of each kind said, and when it was last allowed
# through regardless.
_said = {}

QUIET_HEARTBEAT_SECONDS = 300


def say_quietly(message, key, heartbeat=QUIET_HEARTBEAT_SECONDS):
    """
    Log a routine per-cycle line only when it changes, or every few minutes.

    At a twenty-second poll these two lines are six an hour and harmless. At
    five seconds they are twenty-four a minute -- around thirty thousand a day
    of "nothing happened", which buries the one line somebody is scrolling back
    to find. Repeating identical idle state is not information.

    The heartbeat still lets them through periodically, because a log that goes
    completely silent is indistinguishable from an agent that has died.
    """
    now = time.time()
    previous = _said.get(key)

    if previous is not None and previous[0] == message and now - previous[1] < heartbeat:
        return

    LOG.info(message)
    _said[key] = (message, now)


def read_device(config):
    """
    Connect, check the clock, read the log, disconnect.

    Held open for as little as possible: only one program may talk to the
    terminal at a time, and somebody standing at it to enrol a finger should
    not have to wait on a poll.
    """
    zk = ZK(
        config["device_ip"],
        port=config["device_port"],
        timeout=config["device_timeout"],
        password=config["comm_key"],
        force_udp=False,
        ommit_ping=True,
    )

    conn = None
    try:
        conn = zk.connect()

        reported = (conn.get_serialnumber() or "").strip()
        if reported != config["serial"]:
            # Refused rather than warned: the agent signs batches as a
            # particular terminal, and posting one terminal's punches under
            # another's serial would file a whole ward's attendance against the
            # wrong device.
            raise RuntimeError(
                f"Serial mismatch - configured {config['serial']!r}, device reports {reported!r}. "
                "Refusing to send."
            )

        check_clock(conn, config["drift_seconds"])

        # Never conn.clear_attendance(). The log is the only copy of anything
        # this agent has not yet delivered.
        records = conn.get_attendance() or []
        say_quietly("Device holds %d punch record(s)." % len(records), key="holds")
        return records

    finally:
        if conn:
            try:
                conn.disconnect()
            except Exception:  # noqa: BLE001 - a failed disconnect must not mask a real error
                LOG.warning("Disconnect from the terminal failed.", exc_info=True)


def check_clock(conn, tolerance):
    """
    A drifting terminal clock silently corrupts every timestamp it stamps, and
    those timestamps are what the whole time-and-attendance record rests on.
    Warned rather than enforced: the server has its own staleness guard, and an
    agent that refuses to collect because a clock is wrong would lose punches
    rather than protect them.
    """
    try:
        device_time = conn.get_time()
    except Exception:  # noqa: BLE001
        LOG.warning("Could not read the terminal clock.", exc_info=True)
        return

    drift = abs((device_time - datetime.now()).total_seconds())
    if drift > tolerance:
        LOG.warning(
            "TERMINAL CLOCK IS OFF BY %.0f SECONDS (device %s, this PC %s). "
            "Attendance recorded now will carry the wrong time — correct the device clock.",
            drift,
            device_time.strftime("%Y-%m-%d %H:%M:%S"),
            datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        )
    else:
        # Rounded, because the raw figure wanders a second either side between
        # polls and a message that changes every cycle defeats the suppressor
        # it is passing through. The exact drift matters only when it crosses
        # the tolerance, and that path logs it in full above.
        say_quietly(
            "Terminal clock is within about %ds of this PC." % (round(drift / 10) * 10),
            key="clock",
        )


def store(state, serial, records):
    """Write everything read from the device into the queue. Returns new rows."""
    seen_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    added = 0

    for record in records:
        # The device reports the time on its own face, with no timezone. It is
        # sent exactly as read: the server parses it against the office's
        # timezone, so converting it here would move it twice.
        punched_at = record.timestamp.strftime("%Y-%m-%d %H:%M:%S")
        punch_code = int(getattr(record, "punch", 0) or 0)
        # pyzk calls the verification state `status`; the server calls the same
        # value verify_mode.
        verify_mode = getattr(record, "status", None)
        pin = str(record.user_id).strip()

        cursor = state.execute(
            "INSERT OR IGNORE INTO punches "
            "(fingerprint, pin, punched_at, punch_code, verify_mode, seen_at, delivered_at) "
            "VALUES (?, ?, ?, ?, ?, ?, NULL)",
            (
                fingerprint_for(serial, pin, punched_at, punch_code),
                pin,
                punched_at,
                punch_code,
                int(verify_mode) if verify_mode is not None else None,
                seen_at,
            ),
        )
        added += cursor.rowcount

    state.commit()
    return added


# ---------------------------------------------------------------------------
# Delivery
# ---------------------------------------------------------------------------

def pending(state, limit):
    rows = state.execute(
        "SELECT fingerprint, pin, punched_at, punch_code, verify_mode "
        "FROM punches WHERE delivered_at IS NULL ORDER BY punched_at LIMIT ?",
        (limit,),
    ).fetchall()

    return [
        {
            "fingerprint": row[0],
            "pin": row[1],
            "punched_at": row[2],
            "punch_code": row[3],
            "verify_mode": row[4],
        }
        for row in rows
    ]


def deliver(config, state, batch):
    """
    Post one batch. Returns True only when the server confirmed it.

    The signature covers the exact bytes on the wire, so the body is serialised
    once and those same bytes are both signed and sent. Handing `requests` a
    dict to encode itself would re-serialise it, and any difference at all --
    a space, a key order -- makes the signature fail.
    """
    payload = {
        "device_sn": config["serial"],
        "sent_at": datetime.now().strftime("%Y-%m-%dT%H:%M:%S"),
        "punches": batch,
    }
    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    signature = hmac.new(config["secret"].encode("utf-8"), body, hashlib.sha256).hexdigest()

    try:
        response = requests.post(
            config["server_url"],
            data=body,
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "X-Bridge-Signature": signature,
                "X-Bridge-Device": config["serial"],
            },
            timeout=config["timeout"],
            verify=config["verify_tls"],
        )
    except requests.RequestException as exc:
        # The ordinary case: the hospital link is down. Nothing is marked, the
        # batch waits, and it goes again next cycle.
        LOG.warning("Could not reach the server (%s). %d punch(es) stay queued.", exc, len(batch))
        return False

    if 200 <= response.status_code < 300:
        mark_delivered(state, batch)
        summarise(response, len(batch))
        return True

    explain_refusal(response, len(batch))
    return False


def mark_delivered(state, batch):
    delivered_at = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    state.executemany(
        "UPDATE punches SET delivered_at = ? WHERE fingerprint = ?",
        [(delivered_at, punch["fingerprint"]) for punch in batch],
    )
    state.commit()


def summarise(response, count):
    """
    Report what the server made of each punch, not just that it took them.

    A batch can be accepted in full and still contain punches that became no
    attendance -- an unenrolled PIN, a punch code that is not mapped. Those are
    recoverable with `php artisan biometric:replay` once the cause is fixed,
    but only if somebody knows they happened.
    """
    try:
        results = response.json().get("results", [])
    except ValueError:
        LOG.info("Delivered %d punch(es).", count)
        return

    tally = {}
    for result in results:
        status = result.get("status", "unknown")
        tally[status] = tally.get(status, 0) + 1

    LOG.info("Delivered %d punch(es): %s", count, ", ".join(f"{k}={v}" for k, v in sorted(tally.items())))

    for status in ("unmatched", "unsupported", "stale", "pending"):
        if tally.get(status):
            LOG.warning(
                "%d punch(es) came back '%s' - stored but not turned into attendance. "
                "Fix the cause, then run: php artisan biometric:replay",
                tally[status],
                status,
            )


def explain_refusal(response, count):
    """Say what to do about it, since the agent will keep retrying regardless."""
    body = (response.text or "")[:400]
    code = response.status_code

    advice = {
        401: "The signature was refused. BRIDGE_SECRET here must match BIOMETRIC_BRIDGE_SECRET on the server exactly.",
        403: "The request was refused outright. Check the URL is the punch endpoint and not behind a login.",
        404: "That URL is not the punch endpoint. Check BRIDGE_SERVER_URL.",
        413: "The batch was too large. Lower BRIDGE_BATCH_SIZE.",
        422: "The server rejected the batch. Usually the terminal is not registered in Settings → Biometric terminals, or its serial differs.",
        429: "Rate limited. Raise BRIDGE_POLL_SECONDS or lower BRIDGE_BATCH_SIZE.",
        503: "The server has no BIOMETRIC_BRIDGE_SECRET set, so it refuses everything.",
    }.get(code, "Unexpected response.")

    LOG.error("Server answered %s - %d punch(es) stay queued. %s", code, count, advice)
    LOG.error("Response body: %s", body)


# ---------------------------------------------------------------------------
# Cycle
# ---------------------------------------------------------------------------

def cycle(config, state, dry_run=False):
    try:
        records = read_device(config)
    except Exception as exc:  # noqa: BLE001 - one bad poll must not stop the agent
        LOG.error("Could not read the terminal: %s", exc)
        LOG.info("Nothing was lost - the device keeps its log and this will try again.")
        return

    if dry_run:
        LOG.info("DRY RUN - %d record(s) read, nothing stored and nothing sent.", len(records))
        for record in records[:10]:
            LOG.info(
                "  pin=%s at=%s punch_code=%s verify_mode=%s",
                record.user_id,
                record.timestamp.strftime("%Y-%m-%d %H:%M:%S"),
                getattr(record, "punch", None),
                getattr(record, "status", None),
            )
        if len(records) > 10:
            LOG.info("  ... and %d more", len(records) - 10)
        return

    added = store(state, config["serial"], records)
    if added:
        LOG.info("%d new punch(es) queued.", added)

    while True:
        batch = pending(state, config["batch_size"])
        if not batch:
            break
        if not deliver(config, state, batch):
            break   # leave the rest queued; next cycle tries again

    remaining = state.execute(
        "SELECT COUNT(*) FROM punches WHERE delivered_at IS NULL"
    ).fetchone()[0]
    if remaining:
        LOG.warning("%d punch(es) still waiting to be delivered.", remaining)


def main():
    parser = argparse.ArgumentParser(description="Forward ZKTeco punches to the WorkForce HRMS.")
    parser.add_argument("--once", action="store_true", help="Run a single cycle and exit (for Task Scheduler).")
    parser.add_argument("--dry-run", action="store_true", help="Read the device and report, writing nothing.")
    args = parser.parse_args()

    config = load_config()
    setup_logging(config["log_file"])

    LOG.info("=" * 70)
    LOG.info("Bridge agent starting - terminal %s at %s:%s",
             config["serial"], config["device_ip"], config["device_port"])
    LOG.info("Server: %s", config["server_url"])
    if not config["verify_tls"]:
        LOG.warning("TLS verification is OFF. Only acceptable on a trusted LAN with a self-signed certificate.")
    LOG.info("=" * 70)

    state = None if args.dry_run else open_state(config["state_db"])

    try:
        while True:
            cycle(config, state, dry_run=args.dry_run)
            if args.once or args.dry_run:
                break
            time.sleep(config["poll_seconds"])
    except KeyboardInterrupt:
        LOG.info("Stopped. Anything undelivered is still queued and will go on the next run.")
    finally:
        if state:
            state.close()


if __name__ == "__main__":
    main()

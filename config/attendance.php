<?php

return [
    'default_location' => [
        'name' => env('ATTENDANCE_OFFICE_NAME', 'Main Hospital'),
        'address' => env('ATTENDANCE_OFFICE_ADDRESS', env('BRAND_ORGANIZATION', 'Memorial Hospital & Sanitarium')),
        'timezone' => 'Asia/Manila',
        'work_start_time' => env('ATTENDANCE_WORK_START', '08:00'),
        'work_end_time' => env('ATTENDANCE_WORK_END', '17:00'),
        'grace_period_minutes' => (int) env('ATTENDANCE_GRACE_MINUTES', 15),
        'break_minutes' => (int) env('ATTENDANCE_BREAK_MINUTES', 60),
    ],

    'report_max_days' => 92,

    /*
    |--------------------------------------------------------------------------
    | Biometric bridge agent
    |--------------------------------------------------------------------------
    |
    | The ZKTeco terminal cannot reach this application: it sits behind
    | hospital NAT and has no cloud-push firmware, so punches arrive from a
    | bridge agent polling the device on the hospital LAN and POSTing batches
    | here. See docs/BIOMETRIC_HANDOFF.md for the hardware findings.
    |
    */

    'biometric_bridge' => [
        // Shared with the bridge agent's own BRIDGE_SECRET. Requests are
        // signed with it; an unset secret refuses every request rather than
        // signing with an empty string.
        'secret' => env('BIOMETRIC_BRIDGE_SECRET'),

        // How far a punch timestamp may trail real time and still be accepted.
        // The agent queues through outages and flushes on recovery, so a batch
        // can legitimately be hours old; anything older than this is a device
        // clock that has come loose from reality.
        'max_punch_age_hours' => (int) env('BIOMETRIC_BRIDGE_MAX_PUNCH_AGE_HOURS', 72),

        // Largest batch the agent may send in one request.
        'max_batch_size' => (int) env('BIOMETRIC_BRIDGE_MAX_BATCH_SIZE', 500),

        // Longest device PIN this terminal can hold. A PIN is the employee's
        // own id, so this is only ever reached by an installation with very
        // high id values -- but it is the one thing standing between a 10-digit
        // id and a PIN the device silently truncates, which would map two
        // people onto one identity. Nine digits is the ZKTeco user-id
        // convention for this firmware generation and is, like the punch codes
        // above, unconfirmed on this unit.
        'max_pin_length' => (int) env('BIOMETRIC_BRIDGE_MAX_PIN_LENGTH', 9),

        // Requests per minute allowed before the signature is checked (keyed
        // to the caller's address) and after it (keyed to the proven terminal
        // serial). See the limiters in AppServiceProvider.
        'requests_per_minute' => (int) env('BIOMETRIC_BRIDGE_REQUESTS_PER_MINUTE', 120),
        'device_requests_per_minute' => (int) env('BIOMETRIC_BRIDGE_DEVICE_REQUESTS_PER_MINUTE', 60),

        // Device punch_code -> attendance direction.
        //
        // These are the ZKTeco defaults. They are NOT yet confirmed against
        // this unit: no users were enrolled when the terminal was tested, so
        // the codes it actually emits have never been observed (open question
        // 4 in the handoff brief). A code missing from this map is stored as a
        // raw punch and left unprocessed rather than guessed at, so correcting
        // the mapping after enrolling a test user is a change to this array
        // and a `biometric:replay` -- not lost attendance.
        'punch_codes' => [
            0 => 'check_in',
            1 => 'check_out',
            4 => 'check_in',  // overtime in
            5 => 'check_out', // overtime out

            // CONFIRMED on the installed ZK3969, 2026-10-03: it reports 255
            // for every scan, on every verify mode. 255 is the terminal saying
            // it has no in/out state configured at all -- each punch means
            // "somebody was recognised" and nothing more.
            //
            // 'auto' hands the decision to the application: a scan is a
            // check-out when that person has a day open, and a check-in
            // otherwise. That is a real policy choice and not a reading of the
            // device, so it is written here where it can be seen rather than
            // buried in code. If the terminal is ever configured with function
            // keys for in and out, map those codes above and this line stops
            // being reached.
            255 => 'auto',
        ],

        // How close two scans of the same PIN may be before the second is
        // treated as a repeat rather than the opposite direction. Only applies
        // to 'auto': without it, somebody who taps twice because they did not
        // hear the beep is checked straight back out and the record shows a
        // ten-second shift.
        'min_punch_interval_minutes' => (int) env('BIOMETRIC_BRIDGE_MIN_PUNCH_INTERVAL_MINUTES', 2),

        // Device verify_mode -> label stored on the scan event's metadata.
        // Same caveat: unconfirmed on this unit. An unmapped value is recorded
        // as-is, since this is descriptive only and never decides anything.
        'verify_modes' => [
            0 => 'password',
            1 => 'fingerprint',
            2 => 'card',
            15 => 'face',
        ],
    ],
];

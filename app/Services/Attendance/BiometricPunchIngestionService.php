<?php

namespace App\Services\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricPunch;
use App\Models\BiometricScanEvent;
use App\Services\AttendanceService;
use App\Services\BiometricAttendanceGateway;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turns a batch of raw terminal punches into attendance.
 *
 * This is the only class that knows anything about the ZKTeco protocol --
 * punch codes, verify modes, the device's local wall clock. Past this point
 * events are normalised and BiometricAttendanceGateway treats them exactly as
 * it treats a scan from any other source.
 *
 * Ingestion runs in two distinct phases, and the order matters:
 *
 *   1. Every punch in the batch is persisted as a raw row. This phase either
 *      commits whole or throws, and the HTTP layer only answers 2xx once it
 *      has committed -- because a 2xx is what tells the bridge agent it may
 *      stop retrying that batch.
 *
 *   2. Each stored punch is then derived into attendance independently. A
 *      failure here is recorded on the punch row and does not fail the
 *      request: the punch is already safe, and a punch that can never derive
 *      -- an unmapped punch code, a PIN nobody enrolled -- must not put the
 *      bridge into a retry loop it can never escape.
 *
 * What that buys: nothing is lost, nothing is counted twice, and a mapping
 * mistake is a `biometric:replay` rather than a hole in the attendance record.
 */
class BiometricPunchIngestionService
{
    public function __construct(
        private readonly BiometricAttendanceGateway $gateway,
        private readonly BiometricEnrollmentService $enrollments,
        private readonly AttendanceService $attendance,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $punches
     * @return array<string, mixed>
     */
    public function ingest(BiometricDevice $device, array $punches): array
    {
        $stored = $this->storeRawPunches($device, $punches);

        $results = [];

        foreach ($stored as $punch) {
            $results[] = [
                'fingerprint' => $punch->fingerprint,
                'status' => $this->derive($device, $punch),
            ];
        }

        return [
            'accepted' => count($results),
            'results' => $results,
        ];
    }

    /**
     * Statuses a replay re-attempts when nobody names any.
     *
     * Each one is a punch the device genuinely reported and this application
     * then failed to place, for a reason outside the punch itself: no
     * enrolment for that PIN yet, a punch code not mapped to a direction, or a
     * transient failure mid-derivation. All three are fixed by a change here
     * rather than at the terminal, which is what makes re-attempting them
     * meaningful.
     *
     * Absent on purpose: 'processed' (already placed), 'rejected' (a rule
     * refused it, not a gap -- nameable explicitly for the config-fixable
     * cases like a disabled capture mode), and 'stale'. Stale is the one that
     * must never be swept in by default: it means either a device clock that
     * has drifted, where replaying writes fiction into the attendance record,
     * or a genuine outage backlog, where replaying recovers real work. Only a
     * person who knows which can make that call, so they have to ask for it
     * by name.
     *
     * @var list<string>
     */
    public const REPLAYABLE_STATUSES = ['unmatched', 'unsupported', 'pending'];

    /**
     * The punches a replay would re-attempt, without re-attempting them.
     *
     * Asked for separately so a dry run cannot describe a different set from
     * the real run.
     *
     * @param  list<string>  $statuses
     * @return Collection<int, BiometricPunch>
     */
    public function replayable(array $statuses = self::REPLAYABLE_STATUSES, ?string $since = null): Collection
    {
        return BiometricPunch::query()
            ->whereIn('processing_status', $statuses)
            ->when($since !== null, fn ($query) => $query->where('punched_at', '>=', $since))
            ->orderBy('punched_at')
            ->get();
    }

    /**
     * Re-attempt stored punches that never became attendance.
     *
     * The raw punches have been on file since the moment they arrived -- this
     * reads them back and pushes them through the same derivation the bridge
     * endpoint uses, now that whatever blocked them has been fixed. Nothing is
     * fetched from the terminal, so a replay cannot invent a punch; the worst
     * it can do is fail the same way twice.
     *
     * @param  list<string>  $statuses
     * @return Collection<int, BiometricPunch>
     */
    public function replay(array $statuses = self::REPLAYABLE_STATUSES, ?string $since = null): Collection
    {
        $punches = $this->replayable($statuses, $since);

        // One lookup for the whole run rather than one per punch, and keyed on
        // the serial because that is all a raw punch carries. A punch whose
        // terminal has since been retired or deleted cannot be placed against
        // any device, so it is left exactly as it was rather than guessed at.
        $devices = BiometricDevice::query()
            ->with('officeLocation')
            ->whereIn('serial_number', $punches->pluck('device_sn')->unique()->all())
            ->where('is_active', true)
            ->get()
            ->keyBy('serial_number');

        foreach ($punches as $punch) {
            $device = $devices->get($punch->device_sn);

            if ($device === null) {
                $punch->setAttribute('replay_outcome', 'skipped');
                $punch->setAttribute('replay_reason', 'No active terminal is registered with serial '.$punch->device_sn.'.');

                continue;
            }

            $before = $punch->processing_status;

            $this->reopen($punch);
            $after = $this->derive($device, $punch);

            $punch->setAttribute('replay_outcome', $after === $before ? 'unchanged' : $after);
        }

        return $punches;
    }

    /**
     * Put a punch back into the state derivation expects, including the scan
     * event it already produced.
     *
     * The event reset is the part that is easy to miss and silently fatal
     * without. BiometricAttendanceGateway::receive() is idempotent by design:
     * it looks the event up by (device, provider_event_id) and returns early
     * unless the status is still 'received'. So a punch that failed as
     * 'unmatched' already owns an event stamped 'unmatched', and re-deriving
     * it without this would short-circuit inside the gateway and report the
     * same failure while doing no work at all -- a replay that looks like it
     * ran and changed nothing.
     *
     * Resetting is sound because the scan event is derived data, not evidence.
     * The evidence is the raw punch, and that is never rewritten. 'unsupported'
     * and 'stale' punches own no event at all, since those are settled before
     * the gateway is ever called.
     */
    private function reopen(BiometricPunch $punch): void
    {
        if ($punch->biometric_scan_event_id !== null) {
            BiometricScanEvent::query()
                ->whereKey($punch->biometric_scan_event_id)
                ->where('status', '!=', 'processed')
                ->update(['status' => 'received', 'failure_reason' => null, 'updated_at' => now()]);
        }

        $punch->forceFill([
            'processing_status' => 'pending',
            'failure_reason' => null,
            'processed_at' => null,
        ])->save();
    }

    /**
     * Phase one: persist the batch exactly as the terminal reported it.
     *
     * insertOrIgnore is what makes a resent batch a no-op. A plain insert
     * would raise on the unique fingerprint and lose the rest of the batch
     * with it, and a true update-on-conflict would let a replayed request
     * rewrite evidence that is supposed to be immutable. Ignoring the
     * conflict and reading the rows back gives idempotency without ever
     * modifying a punch already on file.
     *
     * @param  array<int, array<string, mixed>>  $punches
     * @return Collection<int, BiometricPunch>
     */
    private function storeRawPunches(BiometricDevice $device, array $punches): Collection
    {
        $now = now();
        $timezone = $device->officeLocation?->timezone ?: (string) config('workforce.timezone', 'Asia/Manila');

        $rows = [];
        $fingerprints = [];

        foreach ($punches as $punch) {
            $fingerprint = (string) $punch['fingerprint'];
            $fingerprints[] = $fingerprint;

            $rows[] = [
                'fingerprint' => $fingerprint,
                'device_sn' => (string) $device->serial_number,
                'pin' => (string) $punch['pin'],
                // The terminal has no notion of a timezone: it reports the time
                // on its own face. That is read as the office's local time and
                // stored UTC, like every other instant in this database.
                'punched_at' => Carbon::parse((string) $punch['punched_at'], $timezone)->utc(),
                'punch_code' => (int) $punch['punch_code'],
                'verify_mode' => isset($punch['verify_mode']) ? (int) $punch['verify_mode'] : null,
                'received_at' => $now,
                'processing_status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                BiometricPunch::query()->insertOrIgnore($chunk);
            }
        });

        // Scoped to this terminal as well as to the digests just sent. The
        // digest already covers the serial, so a cross-device collision is not
        // realistic -- but without the scope a caller who holds the shared
        // secret could name another terminal's punch and have it derived
        // against the device it signed as.
        return BiometricPunch::query()
            ->where('device_sn', (string) $device->serial_number)
            ->whereIn('fingerprint', array_unique($fingerprints))
            // Chronological, explicitly. Direction is decided from what came
            // before, so deriving a batch out of order would resolve a
            // check-out against a check-in that has not happened yet. Insert
            // order usually gives this for free; usually is not a guarantee.
            ->orderBy('punched_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Phase two: derive one stored punch into attendance.
     *
     * Returns the status left on the punch row, which the bridge agent gets
     * back for its own log. Every exit here is a recorded outcome rather than
     * a thrown one, deliberately -- see the class docblock.
     */
    private function derive(BiometricDevice $device, BiometricPunch $punch): string
    {
        // Already settled by an earlier delivery of the same batch. This is the
        // duplicate case from the handoff brief, and it is a no-op by design:
        // the attendance was recorded the first time around.
        if ($punch->processing_status !== 'pending') {
            return $punch->processing_status;
        }

        $eventType = $this->eventTypeFor($punch->punch_code);

        if ($eventType === null) {
            return $this->settle($punch, 'unsupported', sprintf(
                'Punch code %d is not mapped to an attendance direction.',
                $punch->punch_code,
            ));
        }

        if ($eventType === 'auto') {
            $eventType = $this->resolveDirection($device, $punch);

            if ($eventType === null) {
                return $this->settle($punch, 'duplicate', sprintf(
                    'Another scan was already recorded for PIN %s within %d minute(s).',
                    $punch->pin,
                    $this->minimumInterval(),
                ));
            }
        }

        $maxAgeHours = max(1, (int) config('attendance.biometric_bridge.max_punch_age_hours', 72));

        // A terminal whose clock has drifted badly, or a batch recovered from a
        // queue far too old to be meaningful. Kept as evidence, not turned into
        // attendance -- a wrong timestamp silently corrupts the time-and-
        // attendance figures that depend on it.
        if ($punch->punched_at->lessThan(now()->subHours($maxAgeHours))) {
            return $this->settle($punch, 'stale', sprintf(
                'The punch timestamp is more than %d hours old.',
                $maxAgeHours,
            ));
        }

        try {
            $event = $this->gateway->receive(
                $device,
                $punch->fingerprint,
                $punch->pin,
                $eventType,
                $punch->punched_at,
                [
                    'verification_mode' => $this->verifyModeFor($punch->verify_mode),
                    'offline_sync' => $punch->punched_at->lessThan($punch->received_at->copy()->subMinutes(5)),
                ],
            );
        } catch (Throwable $exception) {
            // The raw punch is already committed, so this is recoverable: the
            // row stays pending and `biometric:replay` picks it up again.
            Log::error('A biometric punch could not be derived into attendance.', [
                'event' => 'biometric.punch.derivation_failed',
                'fingerprint' => $punch->fingerprint,
                'device_sn' => $punch->device_sn,
                'error' => $exception->getMessage(),
            ]);

            $punch->forceFill(['failure_reason' => 'Derivation failed and will be retried.'])->save();

            return 'pending';
        }

        $punch->forceFill([
            'employee_id' => $event->employee_id,
            'biometric_scan_event_id' => $event->id,
            'processing_status' => $event->status,
            'failure_reason' => $event->failure_reason,
            'processed_at' => now(),
        ])->save();

        // A punch that resolved to a person is the only proof available that
        // the template is genuinely on the terminal, so the enrolment roster
        // heals itself and nobody has to remember to tick a box after walking
        // a ward through enrolment.
        //
        // Keyed on employee_id rather than on a 'processed' status,
        // deliberately. The gateway sets employee_id the moment the device
        // identity matches an active enrolment, before AttendanceService runs.
        // A punch it matched and then refused -- "already checked in today",
        // "no published shift covers this punch" -- is still a finger the
        // device recognised, and is the commonest real case. The early
        // rejections (inactive device, capture mode off, future timestamp) and
        // 'unmatched' all leave employee_id null, so an unknown PIN cannot mark
        // anybody enrolled. The simulator calls the gateway directly and never
        // reaches ingestion, so dev traffic cannot either.
        //
        // After the write-back above, so a failure here cannot cost the punch
        // its recorded status.
        if ($event->employee_id !== null) {
            $this->enrollments->confirmCapturedFromPunch($device, $punch->pin);
        }

        return $event->status;
    }

    private function settle(BiometricPunch $punch, string $status, string $reason): string
    {
        $punch->forceFill([
            'processing_status' => $status,
            'failure_reason' => $reason,
            'processed_at' => now(),
        ])->save();

        return $status;
    }

    /**
     * The mapping is configuration rather than a match expression here because
     * the codes this unit emits have not been observed yet (open question 4 in
     * the handoff brief). An unmapped code returns null and the punch is stored
     * unprocessed, so correcting the map later recovers the attendance instead
     * of having guessed a direction and written the wrong one.
     */
    private function eventTypeFor(int $punchCode): ?string
    {
        $mapped = config('attendance.biometric_bridge.punch_codes')[$punchCode] ?? null;

        return in_array($mapped, ['check_in', 'check_out', 'auto'], true) ? $mapped : null;
    }

    /**
     * Decide the direction when the terminal does not state one.
     *
     * The installed ZK3969 reports punch_code 255 for every scan: it has no
     * in/out state configured, so each punch means "somebody was recognised"
     * and nothing more. Something has to decide which way it went, and with no
     * key for staff to press, the only honest source left is whether this
     * person currently has a day open.
     *
     * AttendanceService::recordToClose() is what answers that, reused rather
     * than reimplemented -- it already understands the night shift, where a
     * nurse checks in at 22:00 and out at 06:00 the following day, and a second
     * rule written here would eventually disagree with it.
     *
     * Returns null when the scan lands inside the minimum interval. Without
     * that guard, somebody who taps twice because they did not hear the beep is
     * checked straight back out, and the record shows a ten-second shift. The
     * guard looks at punches already processed rather than at the attendance
     * record, so a repeat is caught even before the first one has derived.
     */
    private function resolveDirection(BiometricDevice $device, BiometricPunch $punch): ?string
    {
        // Any earlier punch in the window counts, whatever became of it.
        //
        // This used to require the previous one to have reached 'processed',
        // which made the guard depend on a second thing having gone right. A
        // rapid repeat is a rapid repeat regardless of how its predecessor was
        // filed -- and a guard that quietly stops guarding when something
        // upstream fails is worse than no guard, because the record it lets
        // through looks deliberate. 'duplicate' itself is excluded so a burst
        // of taps cannot chain off one another.
        $recent = BiometricPunch::query()
            ->where('device_sn', $punch->device_sn)
            ->where('pin', $punch->pin)
            ->where('processing_status', '!=', 'duplicate')
            ->whereKeyNot($punch->id)
            ->where('punched_at', '<', $punch->punched_at)
            ->where('punched_at', '>=', $punch->punched_at->copy()->subMinutes($this->minimumInterval()))
            ->exists();

        if ($recent) {
            return null;
        }

        $employee = BiometricEnrollment::query()
            ->where('biometric_device_id', $device->id)
            ->where('external_user_id', $punch->pin)
            ->where('is_active', true)
            ->first()?->employee;

        // Nobody is enrolled under this PIN. Handed to the gateway as a
        // check-in so that it is the one place that decides what an unknown
        // identity means -- it answers 'unmatched', which is the right record
        // and keeps that judgement in a single class.
        if ($employee === null) {
            return 'check_in';
        }

        $timezone = $device->officeLocation?->timezone
            ?: (string) config('workforce.timezone', 'Asia/Manila');

        $open = $this->attendance->recordToClose($employee, $timezone, $punch->punched_at);

        return $open !== null && $open->check_in_at !== null && $open->check_out_at === null
            ? 'check_out'
            : 'check_in';
    }

    private function minimumInterval(): int
    {
        return max(0, (int) config('attendance.biometric_bridge.min_punch_interval_minutes', 2));
    }

    private function verifyModeFor(?int $verifyMode): string
    {
        if ($verifyMode === null) {
            return 'unknown';
        }

        return (string) (config('attendance.biometric_bridge.verify_modes')[$verifyMode] ?? 'mode-'.$verifyMode);
    }
}

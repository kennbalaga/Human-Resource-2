<?php

namespace App\Services\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
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
 * mistake is a replay rather than a hole in the attendance record.
 */
class BiometricPunchIngestionService
{
    public function __construct(
        private readonly BiometricAttendanceGateway $gateway,
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
            // row stays pending and the replay path picks it up again.
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

        return in_array($mapped, ['check_in', 'check_out'], true) ? $mapped : null;
    }

    private function verifyModeFor(?int $verifyMode): string
    {
        if ($verifyMode === null) {
            return 'unknown';
        }

        return (string) (config('attendance.biometric_bridge.verify_modes')[$verifyMode] ?? 'mode-'.$verifyMode);
    }
}

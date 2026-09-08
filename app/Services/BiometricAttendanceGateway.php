<?php

namespace App\Services;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricScanEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BiometricAttendanceGateway
{
    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly AttendanceCaptureSettings $captureSettings,
    ) {}

    /**
     * Accept a normalized event from a vendor adapter. Never pass fingerprint images or templates here.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function receive(
        BiometricDevice $device,
        string $providerEventId,
        string $externalUserId,
        string $eventType,
        CarbonInterface $capturedAt,
        array $metadata = [],
    ): BiometricScanEvent {
        $event = $this->findOrCreateEvent(
            $device,
            $providerEventId,
            $externalUserId,
            $eventType,
            $capturedAt,
            $metadata,
        );

        if ($event->status !== 'received') {
            return $event;
        }

        return DB::transaction(function () use ($device, $event): BiometricScanEvent {
            $lockedEvent = BiometricScanEvent::query()->lockForUpdate()->findOrFail($event->id);

            return $lockedEvent->status === 'received'
                ? $this->processReceivedEvent($device, $lockedEvent)
                : $lockedEvent;
        });
    }

    private function processReceivedEvent(BiometricDevice $device, BiometricScanEvent $event): BiometricScanEvent
    {
        $device->forceFill(['last_seen_at' => now()])->save();

        if (! $device->is_active) {
            return $this->reject($event, 'The biometric device is inactive.');
        }

        if (! $device->officeLocation?->is_active) {
            return $this->reject($event, 'The biometric device is not assigned to an active office.');
        }

        if (! $this->captureSettings->biometricAllowed()) {
            return $this->reject($event, 'Biometric capture is disabled by the current attendance mode.');
        }

        if (! in_array($event->event_type, ['check_in', 'check_out'], true)) {
            return $this->reject($event, 'Unsupported biometric event type.');
        }

        if ($event->captured_at->greaterThan(now()->addMinutes(5))) {
            return $this->reject($event, 'The device timestamp is too far in the future.');
        }

        $enrollment = BiometricEnrollment::query()
            ->with('employee')
            ->where('biometric_device_id', $device->id)
            ->where('external_user_id', $event->external_user_id)
            ->where('is_active', true)
            ->first();

        // Archived counts the same as not active here: the enrolment sits on
        // the device until somebody removes it, so the record is what decides.
        if ($enrollment === null
            || $enrollment->employee?->employment_status !== 'active'
            || $enrollment->employee->isArchived()) {
            $event->update([
                'status' => 'unmatched',
                'failure_reason' => 'No active employee enrollment matched the device identity.',
            ]);

            return $event->refresh();
        }

        $event->update(['employee_id' => $enrollment->employee_id]);

        try {
            $record = $event->event_type === 'check_in'
                ? $this->attendanceService->checkIn(
                    $enrollment->employee,
                    $device->officeLocation,
                    null,
                    null,
                    'biometric-device/'.$device->code,
                    'biometric',
                    $device,
                    $event->captured_at,
                )
                : $this->attendanceService->checkOut(
                    $enrollment->employee,
                    $device->officeLocation,
                    null,
                    null,
                    'biometric-device/'.$device->code,
                    'biometric',
                    $device,
                    $event->captured_at,
                );
        } catch (ValidationException $exception) {
            return $this->reject($event, $exception->validator->errors()->first());
        }

        $event->update([
            'attendance_record_id' => $record->id,
            'status' => 'processed',
            'failure_reason' => null,
        ]);

        return $event->refresh();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function findOrCreateEvent(
        BiometricDevice $device,
        string $providerEventId,
        string $externalUserId,
        string $eventType,
        CarbonInterface $capturedAt,
        array $metadata,
    ): BiometricScanEvent {
        try {
            return BiometricScanEvent::query()->firstOrCreate(
                [
                    'biometric_device_id' => $device->id,
                    'provider_event_id' => $providerEventId,
                ],
                [
                    'external_user_id' => $externalUserId,
                    'event_type' => $eventType,
                    'captured_at' => $capturedAt,
                    'received_at' => now(),
                    'status' => 'received',
                    'metadata' => Arr::only($metadata, ['match_score', 'verification_mode', 'offline_sync', 'simulated']),
                ],
            );
        } catch (QueryException $exception) {
            $event = BiometricScanEvent::query()
                ->where('biometric_device_id', $device->id)
                ->where('provider_event_id', $providerEventId)
                ->first();

            if ($event !== null) {
                return $event;
            }

            throw $exception;
        }
    }

    private function reject(BiometricScanEvent $event, string $reason): BiometricScanEvent
    {
        $event->update([
            'status' => 'rejected',
            'failure_reason' => $reason,
        ]);

        return $event->refresh();
    }
}

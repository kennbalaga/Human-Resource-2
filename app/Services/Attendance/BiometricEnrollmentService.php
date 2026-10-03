<?php

namespace App\Services\Attendance;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issues the PIN a terminal knows a person by, and records whether their
 * fingerprint has actually been captured on the device.
 *
 * Two states, deliberately not one. A row here means "this person's PIN is
 * reserved and the roster knows which number to key in at the terminal".
 * `enrolled_at` means "somebody stood at the terminal and captured their
 * finger". Conflating them is the failure this class exists to prevent: assign
 * PINs to every active employee and, keyed off row existence, several hundred
 * people would be told their fingerprint was on a device that has never seen
 * them. An unenrolled nurse needs to know that before her shift starts, not
 * after her finger is refused at the door -- which is the whole point of the
 * card in StaffDashboardService::biometricLink().
 *
 * The PIN is the employee's own id. The terminal's PIN field is numeric and
 * short, so DJNRMHS employee numbers (NUR-HEAD-OPD-2026-0009) do not fit, and
 * inventing a separate sequence would be new state to keep in step with
 * nothing. The id is already unique, already short, already the foreign key
 * attendance is written against, and already visible to the employee on their
 * own dashboard -- which is what lets them report a mis-keyed PIN.
 *
 * What that choice costs, stated plainly: the PIN space is dense, so a
 * one-digit slip at the terminal usually lands on another real enrolled
 * colleague rather than on nothing. A check digit would catch that and this
 * scheme has none. The controls that remain are `enforce_published_shift`
 * (a typo'd PIN belongs to somebody not rostered for that hour, so the punch
 * is refused), the approval queue, and the employee seeing their own PIN.
 *
 * Templates are never read into this database (RA 10173; docs/DATA_PRIVACY.md).
 * Nothing here touches the device either: enrolling a finger is a physical act
 * at the terminal, and this class only records that it happened.
 */
class BiometricEnrollmentService
{
    /** The PIN rule, defined exactly once. */
    public function pinFor(Employee $employee): string
    {
        return (string) $employee->id;
    }

    public function maxPinLength(): int
    {
        return max(1, (int) config('attendance.biometric_bridge.max_pin_length', 9));
    }

    /**
     * Reserve this employee's PIN on this terminal.
     *
     * Keyed on (device, employee) rather than on the PIN, which is the key
     * BiometricSimulatorController already uses and the one that lets a
     * previously retired enrolment be taken up again instead of fighting
     * biometric_enrollments_device_employee_unique.
     *
     * @throws ValidationException
     */
    public function assign(BiometricDevice $device, Employee $employee): BiometricEnrollment
    {
        $this->guardAssignable($employee);

        return BiometricEnrollment::query()->updateOrCreate(
            ['biometric_device_id' => $device->id, 'employee_id' => $employee->id],
            [
                'external_user_id' => $this->pinFor($employee),
                'is_active' => true,
            ],
            // enrolled_at is absent on purpose. Re-assigning somebody already
            // captured must not silently un-capture them, and must not claim a
            // capture that never happened -- a new row takes the column's null.
        );
    }

    /**
     * Reserve a PIN for every active employee on this terminal.
     *
     * @return array{assigned: int, captured: int, total: int}
     *
     * @throws ValidationException
     */
    public function assignAllActive(BiometricDevice $device): array
    {
        // Pre-flight before the upsert, not after. With PIN = employees.id both
        // unique indexes key on the same logical pair, so one upsert on
        // (device, external_user_id) is idempotent -- but only while every row
        // already obeys that rule. A hand-inserted row that gave employee 7 the
        // PIN '4417' would make the upsert insert a *second* row for employee 7
        // and detonate biometric_enrollments_device_employee_unique mid-batch.
        // Named rather than crashed on.
        $conflicts = $this->nonConformingPins($device);

        if ($conflicts->isNotEmpty()) {
            throw ValidationException::withMessages([
                'biometric_device_id' => 'These enrolments hold a PIN that is not the employee id, so a bulk assign would collide: '
                    .$conflicts
                        ->map(fn (BiometricEnrollment $enrollment) => ($enrollment->employee?->full_name ?? 'Employee #'.$enrollment->employee_id)
                            .' (PIN '.$enrollment->external_user_id.')')
                        ->implode('; ')
                    .'. Retire or correct them first.',
            ]);
        }

        $before = $this->activeCountFor($device);
        $now = now();

        DB::transaction(function () use ($device, $now): void {
            Employee::query()
                ->where('employment_status', 'active')
                ->notArchived()
                ->select(['id'])
                ->orderBy('id')
                // chunkById rather than get(): several hundred employees is a
                // handful of statements this way, not one oversized packet and
                // not a round trip per person.
                ->chunkById(200, function (Collection $employees) use ($device, $now): void {
                    BiometricEnrollment::query()->upsert(
                        $employees->map(fn (Employee $employee) => [
                            'biometric_device_id' => $device->id,
                            'employee_id' => $employee->id,
                            'external_user_id' => $this->pinFor($employee),
                            'is_active' => true,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ])->all(),
                        ['biometric_device_id', 'external_user_id'],
                        // enrolled_at is NOT in this list. Running a bulk assign
                        // a second time must reactivate rows and nothing else:
                        // a captured template stays captured, and a reserved PIN
                        // stays reserved.
                        ['is_active', 'updated_at'],
                    );
                });
        });

        $after = $this->activeCountFor($device);

        return [
            'assigned' => max(0, $after - $before),
            'captured' => BiometricEnrollment::query()
                ->where('biometric_device_id', $device->id)
                ->where('is_active', true)
                ->whereNotNull('enrolled_at')
                ->count(),
            'total' => $after,
        ];
    }

    /** Somebody stood at the terminal and captured this person's finger. */
    public function markCaptured(BiometricEnrollment $enrollment): BiometricEnrollment
    {
        if ($enrollment->enrolled_at === null) {
            $enrollment->forceFill(['enrolled_at' => now()])->save();
        }

        return $enrollment->refresh();
    }

    /**
     * The template is gone from the device but the PIN stays reserved.
     *
     * Used for a re-enrolment: a failed or injured finger has to be captured
     * again, and the person should reappear in the "awaiting capture" list
     * without their PIN being reissued or the row being deleted.
     */
    public function markNotCaptured(BiometricEnrollment $enrollment): BiometricEnrollment
    {
        $enrollment->forceFill(['enrolled_at' => null])->save();

        return $enrollment->refresh();
    }

    /**
     * Retire the enrolment, keeping the row.
     *
     * Never a delete: the row is the only record that this PIN was once issued
     * to this person, and biometric_enrollments is what a disputed punch is
     * traced through. Keeping it also leaves the unique index free to be taken
     * up again by assign().
     */
    public function deactivate(BiometricEnrollment $enrollment): BiometricEnrollment
    {
        $enrollment->forceFill(['is_active' => false])->save();

        return $enrollment->refresh();
    }

    /** @throws ValidationException */
    public function reactivate(BiometricEnrollment $enrollment): BiometricEnrollment
    {
        $enrollment->loadMissing('employee');

        if ($enrollment->employee !== null) {
            $this->guardAssignable($enrollment->employee);
        }

        $enrollment->forceFill([
            'is_active' => true,
            'external_user_id' => $enrollment->employee !== null
                ? $this->pinFor($enrollment->employee)
                : $enrollment->external_user_id,
        ])->save();

        return $enrollment->refresh();
    }

    /**
     * Record a capture because the device itself just proved one.
     *
     * A punch that resolved to a person is the only evidence available that the
     * template is genuinely on the terminal, so the roster heals itself and
     * nobody has to remember to tick a box after walking a ward through
     * enrolment.
     *
     * Scoped on (device, external_user_id) rather than on the employee: someone
     * may hold a reservation on two terminals, and only the one that actually
     * saw the finger is proven. One conditional UPDATE rather than
     * read-then-write, so two punches in the same batch cannot race, and
     * whereNull means a later punch never moves an existing capture date.
     */
    public function confirmCapturedFromPunch(BiometricDevice $device, string $externalUserId): void
    {
        BiometricEnrollment::query()
            ->where('biometric_device_id', $device->id)
            ->where('external_user_id', $externalUserId)
            ->whereNull('enrolled_at')
            ->update(['enrolled_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Captured templates belonging to people who have left.
     *
     * BiometricAttendanceGateway already refuses these punches as unmatched --
     * it checks employment_status and isArchived at the point of the lookup --
     * so this is not a security gap and the row does not need deactivating to
     * be safe. What it is, is outstanding *physical* work: the template is
     * still on the terminal, and flipping is_active here would do nothing to
     * the device while hiding the fact that somebody has to go and delete that
     * user from it. So this is a worklist, and deactivation is what HR records
     * once they have actually done it.
     *
     * @return Collection<int, BiometricEnrollment>
     */
    public function templatesToRemove(BiometricDevice $device): Collection
    {
        return BiometricEnrollment::query()
            ->with('employee')
            ->where('biometric_device_id', $device->id)
            ->where('is_active', true)
            ->whereNotNull('enrolled_at')
            ->whereHas('employee', fn (Builder $query) => $query
                ->where('employment_status', '!=', 'active')
                ->orWhereNotNull('archived_at'))
            ->get();
    }

    /**
     * Active enrolments whose PIN is not the employee id.
     *
     * Only hand-written rows from before this page existed can be in here. They
     * are what would make a bulk assign collide, so the roster names them and
     * the bulk action refuses until they are settled.
     *
     * @return Collection<int, BiometricEnrollment>
     */
    public function nonConformingPins(BiometricDevice $device): Collection
    {
        return BiometricEnrollment::query()
            ->with('employee')
            ->where('biometric_device_id', $device->id)
            ->where('is_active', true)
            ->whereColumn('external_user_id', '!=', 'employee_id')
            ->get();
    }

    private function activeCountFor(BiometricDevice $device): int
    {
        return BiometricEnrollment::query()
            ->where('biometric_device_id', $device->id)
            ->where('is_active', true)
            ->count();
    }

    /** @throws ValidationException */
    private function guardAssignable(Employee $employee): void
    {
        if ($employee->employment_status !== 'active') {
            throw ValidationException::withMessages([
                'employee_id' => $employee->full_name.' is not an active employee.',
            ]);
        }

        // A terminal does not know a record has been filed away. Without this,
        // the one person HR has archived is the one whose finger still opens
        // the day.
        if ($employee->isArchived()) {
            throw ValidationException::withMessages([
                'employee_id' => $employee->full_name."'s record is archived. Restore it before enrolling them.",
            ]);
        }

        if (mb_strlen($this->pinFor($employee)) > $this->maxPinLength()) {
            throw ValidationException::withMessages([
                'employee_id' => 'This employee id is longer than the '.$this->maxPinLength()
                    .' digits the terminal can hold, so it cannot be used as a PIN.',
            ]);
        }
    }
}

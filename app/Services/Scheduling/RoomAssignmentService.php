<?php

namespace App\Services\Scheduling;

use App\Models\Employee;
use App\Models\Room;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Places an already-rostered shift in a room, and refuses the placements a ward
 * could not honour.
 *
 * The boundary matters more than anything else here: this service never creates
 * duty and never removes it. Who works Tuesday morning is the roster's decision,
 * made by RosterDraftService and reviewed by the nursing office; all this does
 * is answer where that person stands once the decision is made. Every method
 * below therefore starts from an existing `schedule_assignments` row and only
 * ever writes `room_id` on it — which is also why the room board cannot be used
 * to sneak somebody onto a shift they were never rostered for.
 *
 * One consequence is worth stating plainly, because it removes a rule the design
 * expected to need: `schedule_assignments` is already unique on
 * (employee, shift, work_date), so a person has exactly one row per shift and
 * therefore exactly one room. Being in two rooms at once is not caught here — it
 * is unrepresentable.
 */
class RoomAssignmentService
{
    public const REASON_NOT_ROSTERED = 'Not rostered on this shift';

    public const REASON_ALREADY_PLACED = 'Already in this room';

    public const REASON_IN_ANOTHER_ROOM = 'Already placed in another room';

    public const REASON_ROOM_UNAVAILABLE = 'This room is not available';

    public const REASON_ROOM_NOT_OPERATED = 'This room does not run this shift';

    public const REASON_ROOM_AT_CAPACITY = 'This room is full';

    public function __construct(
        private readonly StaffingRequirementService $staffingRequirements,
        private readonly ScheduleLockService $locks,
    ) {}

    /**
     * Put a rostered employee in a room.
     *
     * @throws ValidationException
     */
    public function assign(Room $room, Shift $shift, Carbon|string $workDate, Employee $employee, User $actor): ScheduleAssignment
    {
        $date = $this->asDate($workDate);

        $this->assertRoomOpen($room, $shift);
        $this->assertDatePlaceable($date);

        $assignment = $this->rosteredAssignment($employee, $shift, $date);

        if ($assignment === null) {
            throw ValidationException::withMessages([
                'employee_id' => "{$employee->full_name} is not rostered on the {$shift->name} for {$date->format('M j, Y')}. Roster the shift first, then give it a room.",
            ]);
        }

        if ($assignment->room_id === $room->id) {
            return $assignment;
        }

        // The employee's own unit holds the lock, not the room's: a locked ward
        // means that ward's people are settled, wherever they happen to stand.
        if ($employee->department !== null) {
            $this->locks->assertUnlocked($employee->department, $date);
        }

        $occupants = $this->occupants($room, $shift, $date)
            ->reject(fn (ScheduleAssignment $row) => $row->id === $assignment->id);

        if ($room->max_staff !== null && $occupants->count() >= $room->max_staff) {
            throw ValidationException::withMessages([
                'room_id' => "{$room->code} holds {$room->max_staff}. Take somebody out before adding another.",
            ]);
        }

        return RosterWriteContext::allow($actor, function () use ($assignment, $room, $employee) {
            $assignment->update([
                'room_id' => $room->id,
                'cross_unit' => $employee->department_id !== $room->department_id,
            ]);

            return $assignment->refresh();
        });
    }

    /**
     * Take a shift out of its room. The duty itself stays exactly where it was.
     *
     * @throws ValidationException
     */
    public function unassign(ScheduleAssignment $assignment, User $actor): ScheduleAssignment
    {
        $this->assertDatePlaceable($this->asDate($assignment->work_date));

        $employee = $assignment->employee ?? $assignment->employee()->with('department')->first();

        if ($employee?->department !== null) {
            $this->locks->assertUnlocked($employee->department, $this->asDate($assignment->work_date));
        }

        return RosterWriteContext::allow($actor, function () use ($assignment) {
            $assignment->update(['room_id' => null, 'cross_unit' => false]);

            return $assignment->refresh();
        });
    }

    /**
     * Whether substituting one person for another in an occupied slot would
     * leave the room without the cover it must have.
     *
     * A shift swap exchanges who fills a slot, and the room stays with the slot,
     * so the incoming person inherits it. That is the right semantic — the room
     * belongs to the duty, not to the nurse — but it means a swap can quietly
     * strip a theatre of its only charge nurse. This is the check that stops it.
     */
    public function substitutionBlockReason(ScheduleAssignment $assignment, Employee $incoming): ?string
    {
        if ($assignment->room_id === null) {
            return null;
        }

        $room = $assignment->room ?? Room::query()->find($assignment->room_id);

        if ($room === null || $room->min_seniority_rank <= 1) {
            return null;
        }

        $shift = $assignment->shift ?? Shift::query()->find($assignment->shift_id);

        if ($shift === null) {
            return null;
        }

        $required = $this->staffingRequirements->forRoomShift($room, $shift)['senior'];

        if ($required < 1) {
            return null;
        }

        $incomingRank = (int) ($incoming->position?->seniority_rank ?? $incoming->loadMissing('position')->position?->seniority_rank ?? 1);

        // Everybody else in the room keeps their place; only this slot changes
        // hands, so the question is whether the room still clears its charge
        // requirement once the outgoing person is replaced.
        $others = $this->occupants($room, $shift, $assignment->work_date)
            ->reject(fn (ScheduleAssignment $row) => $row->id === $assignment->id)
            ->filter(fn (ScheduleAssignment $row) => (int) ($row->employee?->position?->seniority_rank ?? 1) >= $room->min_seniority_rank)
            ->count();

        if ($others + ($incomingRank >= $room->min_seniority_rank ? 1 : 0) >= $required) {
            return null;
        }

        return "{$room->code} would be left without anyone at rank {$room->min_seniority_rank} or above";
    }

    /**
     * Recompute whether an assignment's occupant is borrowed from another unit.
     * Called after a swap has changed who stands in the slot.
     */
    public function refreshCrossUnitFlag(ScheduleAssignment $assignment, Employee $occupant): void
    {
        $room = $assignment->room_id === null
            ? null
            : ($assignment->room ?? Room::query()->find($assignment->room_id));

        $crossUnit = $room !== null && $occupant->department_id !== $room->department_id;

        if ((bool) $assignment->cross_unit !== $crossUnit) {
            $assignment->update(['cross_unit' => $crossUnit]);
        }
    }

    /**
     * Everyone rostered on this shift and date, with the reason each one cannot
     * take this room — or null where they can.
     *
     * Building the list this way round is deliberate. A picker that lists the
     * whole workforce and then refuses most of it wastes the charge nurse's
     * time; a picker that silently hides the ineligible leaves them wondering
     * where a colleague went. Everybody rostered is shown, and each refusal says
     * why.
     *
     * @return Collection<int, array{assignment: ScheduleAssignment, employee: Employee, reason: string|null, cross_unit: bool, is_charge: bool}>
     */
    public function candidates(Room $room, Shift $shift, Carbon|string $workDate): Collection
    {
        $date = $this->asDate($workDate);

        $rostered = ScheduleAssignment::query()
            ->with(['employee.position', 'employee.department', 'room'])
            ->where('shift_id', $shift->id)
            ->whereDate('work_date', $date->toDateString())
            ->where('status', 'scheduled')
            ->get();

        $occupied = $rostered->where('room_id', $room->id)->count();
        $full = $room->max_staff !== null && $occupied >= $room->max_staff;

        return $rostered
            ->filter(fn (ScheduleAssignment $row) => $row->employee !== null)
            ->map(function (ScheduleAssignment $row) use ($room, $full): array {
                $employee = $row->employee;
                $rank = (int) ($employee->position?->seniority_rank ?? 1);

                $reason = match (true) {
                    $row->room_id === $room->id => self::REASON_ALREADY_PLACED,
                    $row->room_id !== null => self::REASON_IN_ANOTHER_ROOM.' ('.($row->room?->code ?? 'unknown').')',
                    $full => self::REASON_ROOM_AT_CAPACITY,
                    default => null,
                };

                return [
                    'assignment' => $row,
                    'employee' => $employee,
                    'reason' => $reason,
                    'cross_unit' => $employee->department_id !== $room->department_id,
                    'is_charge' => $rank >= $room->min_seniority_rank,
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => ($a['reason'] === null ? 0 : 1) <=> ($b['reason'] === null ? 0 : 1),
                fn (array $a, array $b) => ($a['cross_unit'] ? 1 : 0) <=> ($b['cross_unit'] ? 1 : 0),
                fn (array $a, array $b) => strcmp($a['employee']->full_name, $b['employee']->full_name),
            ])
            ->values();
    }

    /**
     * Who is standing in this room on this shift and date.
     *
     * @return Collection<int, ScheduleAssignment>
     */
    public function occupants(Room $room, Shift $shift, Carbon|string $workDate): Collection
    {
        return ScheduleAssignment::query()
            ->with(['employee.position'])
            ->where('room_id', $room->id)
            ->where('shift_id', $shift->id)
            ->whereDate('work_date', $this->asDate($workDate)->toDateString())
            ->where('status', 'scheduled')
            ->get();
    }

    /**
     * @throws ValidationException
     */
    private function assertRoomOpen(Room $room, Shift $shift): void
    {
        if (! $room->isUsable()) {
            throw ValidationException::withMessages([
                'room_id' => "{$room->code} is {$room->status_label}. Nobody can be rostered into it until it reopens.",
            ]);
        }

        if (! $this->staffingRequirements->forRoomShift($room, $shift)['operates']) {
            throw ValidationException::withMessages([
                'room_id' => "{$room->code} does not run the {$shift->name}.",
            ]);
        }
    }

    /**
     * Room placement deliberately does not use ScheduleService's edit window.
     *
     * That window starts tomorrow, because rewriting *duty* in the past moves
     * hours that attendance and payroll have already counted. A room carries no
     * hours: moving a nurse from Ward A to Isolation at ten this morning changes
     * where she is, not what she is owed, and a charge nurse does exactly that
     * several times a shift. So today is open and yesterday is not.
     *
     * @throws ValidationException
     */
    private function assertDatePlaceable(Carbon $date): void
    {
        if ($date->lessThan(now(config('schedule.timezone'))->startOfDay())) {
            throw ValidationException::withMessages([
                'work_date' => 'Room placement cannot be changed for a date that has already passed.',
            ]);
        }
    }

    private function rosteredAssignment(Employee $employee, Shift $shift, Carbon $date): ?ScheduleAssignment
    {
        return ScheduleAssignment::query()
            ->where('employee_id', $employee->id)
            ->where('shift_id', $shift->id)
            ->whereDate('work_date', $date->toDateString())
            ->where('status', 'scheduled')
            ->first();
    }

    private function asDate(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon
            ? $date->copy()->timezone(config('schedule.timezone'))->startOfDay()
            : Carbon::parse($date, config('schedule.timezone'))->startOfDay();
    }
}

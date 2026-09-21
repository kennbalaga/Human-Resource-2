<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\RoomShiftRequirement;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Services\ReferenceDataCache;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The room board for one unit on one day, and the findings it is graded against.
 *
 * Two things are kept apart here on purpose. The board says what is, room by
 * room and shift by shift; the findings say what is wrong with it. Keeping the
 * grading out of the rendering is what lets the same rules answer the screen, a
 * publish gate and a report without three of them drifting apart.
 *
 * A note on what is *not* graded. Somebody standing in two rooms at once would
 * be the obvious first rule to write, and there is no rule for it, because
 * `schedule_assignments` is unique on (employee, shift, work_date): one row per
 * shift, one `room_id` on that row. The condition cannot be represented, so
 * checking for it would only cost a pass over the data to always answer no.
 */
class RoomRosterService
{
    public const BLOCKER = 'blocker';

    public const WARNING = 'warning';

    public function __construct(
        private readonly StaffingRequirementService $staffingRequirements,
        private readonly ReferenceDataCache $reference,
        private readonly RoomBookingService $bookings,
    ) {}

    /**
     * Everything one day of one unit's rooms needs, in a handful of queries.
     *
     * @return array<string, mixed>
     */
    public function board(Department $department, Carbon|string $workDate): array
    {
        $date = $this->asDate($workDate);
        $shifts = $this->shifts();

        $rooms = $this->reference->rooms()
            ->where('department_id', $department->id)
            ->where('is_active', true)
            ->sortBy([['room_type', 'asc'], ['code', 'asc']])
            ->values();

        // Requirements, placements and leave: three queries for the whole board,
        // rather than one per cell. On a remote database each round trip is the
        // better part of a tenth of a second, so a per-cell lookup would cost
        // more than the page.
        $requirements = $this->requirementsFor($rooms, $shifts);
        $assignments = $this->assignmentsFor($department, $rooms, $date);
        $onLeave = $this->employeesOnLeave($assignments->pluck('employee_id')->filter()->unique()->all(), $date);

        $placed = $assignments->whereNotNull('room_id')->groupBy(fn (ScheduleAssignment $row) => $row->room_id.'|'.$row->shift_id);
        $findings = collect();

        // Theatre lists for the day, and who is scrubbed to each. A case with
        // nobody on it is the single most useful thing this board can say about
        // a theatre, and it is invisible from head counts alone.
        $lists = $this->bookings->listForRooms($rooms, $date);
        $scrubbed = $assignments->whereNotNull('room_booking_id')->countBy('room_booking_id');

        $roomRows = $rooms->map(function (Room $room) use ($shifts, $requirements, $placed, $onLeave, $findings, $date, $lists, $scrubbed): array {
            $cells = $shifts->mapWithKeys(function (Shift $shift) use ($room, $requirements, $placed, $onLeave, $findings, $date): array {
                $requirement = $requirements->get($room->id.'|'.$shift->id);
                $occupants = collect($placed->get($room->id.'|'.$shift->id, collect()))
                    ->map(fn (ScheduleAssignment $row) => $this->occupantRow($row, $room, $onLeave))
                    ->sortByDesc('rank')
                    ->values();

                $cellFindings = $this->gradeCell($room, $shift, $date, $requirement, $occupants);
                $cellFindings->each(fn (array $finding) => $findings->push($finding));

                return [$shift->id => [
                    'shift_id' => $shift->id,
                    'operates' => $requirement['operates'],
                    'dark' => ! $room->isUsable() || ! $requirement['operates'],
                    'occupants' => $occupants->all(),
                    'count' => $occupants->count(),
                    'required' => $requirement['staff'],
                    'senior_required' => $requirement['senior'],
                    'requirement_source' => $requirement['source'],
                    'capacity' => $room->max_staff,
                    'state' => $this->cellState($room, $requirement, $cellFindings),
                ]];
            });

            $bookings = collect($lists->get($room->id, collect()))
                ->map(fn (RoomBooking $booking): array => [
                    'id' => $booking->id,
                    'window' => $booking->window,
                    'purpose' => $booking->purpose,
                    'lead' => $booking->lead?->full_name,
                    'status' => $booking->status,
                    'scrubbed' => (int) ($scrubbed[$booking->id] ?? 0),
                ])
                ->values();

            foreach ($bookings->where('scrubbed', 0) as $booking) {
                $findings->push([
                    'level' => self::WARNING,
                    'code' => 'case_unstaffed',
                    'room_id' => $room->id,
                    'room_code' => $room->code,
                    'shift_id' => null,
                    'message' => "{$room->code} holds {$booking['window']} for {$booking['purpose']} with nobody scrubbed",
                    'detail' => 'The room is booked; the team is not named yet.',
                ]);
            }

            return ['room' => $room, 'cells' => $cells->all(), 'bookings' => $bookings->all()];
        })->values();

        // Rostered for this unit today and standing nowhere in particular. This
        // is the board's real working list: the charge nurse is not looking for
        // errors, she is looking for the people still to be placed.
        $unplaced = $assignments
            ->whereNull('room_id')
            ->filter(fn (ScheduleAssignment $row) => $row->employee?->department_id === $department->id)
            ->groupBy('shift_id')
            ->map(fn (Collection $rows) => $rows
                ->map(fn (ScheduleAssignment $row) => $this->unplacedRow($row, $onLeave))
                ->sortBy('name')
                ->values()
                ->all());

        $unplacedCount = collect($unplaced)->sum(fn (array $rows) => count($rows));

        if ($unplacedCount > 0) {
            $findings->push([
                'level' => self::WARNING,
                'code' => 'unplaced_staff',
                'room_id' => null,
                'shift_id' => null,
                'message' => $unplacedCount === 1
                    ? '1 person rostered today has no room'
                    : "{$unplacedCount} people rostered today have no room",
                'detail' => 'They are on duty either way — the board just does not say where.',
            ]);
        }

        $blockers = $findings->where('level', self::BLOCKER);
        $warnings = $findings->where('level', self::WARNING);

        return [
            'date' => $date->toDateString(),
            'department' => $department,
            'shifts' => $shifts->values()->all(),
            'rooms' => $roomRows->all(),
            'unplaced' => $unplaced->all(),
            'findings' => $findings
                ->sortBy(fn (array $finding) => $finding['level'] === self::BLOCKER ? 0 : 1)
                ->values()
                ->all(),
            'summary' => [
                'rooms' => $rooms->count(),
                'live_cells' => $roomRows->sum(fn (array $row) => collect($row['cells'])->reject(fn (array $cell) => $cell['dark'])->count()),
                'staffed_cells' => $roomRows->sum(fn (array $row) => collect($row['cells'])->reject(fn (array $cell) => $cell['dark'])->where('count', '>', 0)->count()),
                'placed' => $assignments->whereNotNull('room_id')->count(),
                'unplaced' => $unplacedCount,
                'bookings' => $roomRows->sum(fn (array $row) => count($row['bookings'])),
                'blockers' => $blockers->count(),
                'warnings' => $warnings->count(),
                'publishable' => $blockers->isEmpty(),
            ],
        ];
    }

    /**
     * Just the findings, for a caller that is grading rather than drawing —
     * a publish gate, a report, a nightly check.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function findings(Department $department, Carbon|string $workDate): Collection
    {
        return collect($this->board($department, $workDate)['findings']);
    }

    /**
     * Grade one room on one shift.
     *
     * @param  array{staff: int, senior: int, source: string, operates: bool}  $requirement
     * @param  Collection<int, array<string, mixed>>  $occupants
     * @return Collection<int, array<string, mixed>>
     */
    private function gradeCell(Room $room, Shift $shift, Carbon $date, array $requirement, Collection $occupants): Collection
    {
        $findings = collect();
        $where = "{$room->code} · {$shift->name}";

        // A closed room and a dark shift are the same shape of problem: nobody
        // should be in there. Both stay silent when the room is empty, which is
        // the whole reason `operates` is recorded rather than assumed — an
        // outpatient clinic that is dark every night must not file a finding
        // every night.
        if (! $room->isUsable()) {
            if ($occupants->isNotEmpty()) {
                $findings->push($this->finding(self::BLOCKER, 'room_unavailable', $room, $shift,
                    "{$room->code} is {$room->status_label} but has {$occupants->count()} rostered into it",
                    $where));
            }

            return $findings;
        }

        if (! $requirement['operates']) {
            if ($occupants->isNotEmpty()) {
                $findings->push($this->finding(self::BLOCKER, 'room_not_operated', $room, $shift,
                    "{$room->code} does not run the {$shift->name} but has {$occupants->count()} rostered into it",
                    $where));
            }

            return $findings;
        }

        if ($room->max_staff !== null && $occupants->count() > $room->max_staff) {
            $findings->push($this->finding(self::BLOCKER, 'room_at_capacity', $room, $shift,
                "{$room->code} holds {$room->max_staff}; {$occupants->count()} are rostered into it",
                $where));
        }

        foreach ($occupants->where('on_leave', true) as $occupant) {
            $findings->push($this->finding(self::BLOCKER, 'on_leave', $room, $shift,
                "{$occupant['name']} is on approved leave on this date",
                $where.' · take the shift off the roster, not just out of the room'));
        }

        foreach ($occupants->where('cross_unit', true) as $occupant) {
            $findings->push($this->finding(self::WARNING, 'cross_unit', $room, $shift,
                "{$occupant['name']} is borrowed from {$occupant['department']}",
                $where.' · allowed, and recorded on the assignment'));
        }

        if ($occupants->isEmpty()) {
            $findings->push($this->finding(self::WARNING, 'room_unstaffed', $room, $shift,
                "{$room->code} has nobody on the {$shift->name}",
                "Needs {$requirement['staff']} · {$requirement['source']}"));

            return $findings;
        }

        if ($occupants->count() < $requirement['staff']) {
            $short = $requirement['staff'] - $occupants->count();
            $findings->push($this->finding(self::WARNING, 'room_shortfall', $room, $shift,
                "{$room->code} is {$short} short of its standard",
                "{$occupants->count()} of {$requirement['staff']} on duty · {$requirement['source']}"));
        }

        // Skill mix is a blocker rather than a warning, and that is the one
        // grading decision here worth arguing with. A theatre or a delivery room
        // covered entirely by entry-level staff is not a thin roster, it is an
        // unsafe one, and the fix is never "publish it and explain later".
        $charge = $occupants->where('is_charge', true)->count();

        if ($charge < $requirement['senior']) {
            $findings->push($this->finding(self::BLOCKER, 'no_charge_cover', $room, $shift,
                "{$room->code} has no one at rank {$room->min_seniority_rank} or above",
                $where." · needs {$requirement['senior']} charge-level, has {$charge}"));
        }

        return $findings;
    }

    /** @return array<string, mixed> */
    private function finding(string $level, string $code, Room $room, Shift $shift, string $message, string $detail): array
    {
        return [
            'level' => $level,
            'code' => $code,
            'room_id' => $room->id,
            'room_code' => $room->code,
            'shift_id' => $shift->id,
            'message' => $message,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array{staff: int, senior: int, source: string, operates: bool}  $requirement
     * @param  Collection<int, array<string, mixed>>  $findings
     */
    private function cellState(Room $room, array $requirement, Collection $findings): string
    {
        if (! $room->isUsable() || ! $requirement['operates']) {
            return 'dark';
        }

        if ($findings->contains(fn (array $finding) => $finding['level'] === self::BLOCKER)) {
            return 'blocked';
        }

        return $findings->isEmpty() ? 'ok' : 'short';
    }

    /**
     * @param  array<int, int>  $onLeave
     * @return array<string, mixed>
     */
    private function occupantRow(ScheduleAssignment $row, Room $room, array $onLeave): array
    {
        $employee = $row->employee;
        $rank = (int) ($employee?->position?->seniority_rank ?? 1);

        return [
            'assignment_id' => $row->id,
            'employee_id' => $row->employee_id,
            'name' => $employee?->full_name ?? 'Unknown',
            'position' => $employee?->position?->title,
            'department' => $employee?->department?->name,
            'rank' => $rank,
            'is_charge' => $rank >= $room->min_seniority_rank,
            'cross_unit' => (bool) $row->cross_unit,
            'on_leave' => in_array($row->employee_id, $onLeave, true),
        ];
    }

    /**
     * @param  array<int, int>  $onLeave
     * @return array<string, mixed>
     */
    private function unplacedRow(ScheduleAssignment $row, array $onLeave): array
    {
        $employee = $row->employee;
        $rank = (int) ($employee?->position?->seniority_rank ?? 1);

        return [
            'assignment_id' => $row->id,
            'employee_id' => $row->employee_id,
            'shift_id' => $row->shift_id,
            'name' => $employee?->full_name ?? 'Unknown',
            'position' => $employee?->position?->title,
            'rank' => $rank,
            'on_leave' => in_array($row->employee_id, $onLeave, true),
        ];
    }

    /**
     * @param  Collection<int, Room>  $rooms
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<string, array{staff: int, senior: int, source: string, operates: bool}>
     */
    private function requirementsFor(Collection $rooms, Collection $shifts): Collection
    {
        $recorded = $rooms->isEmpty()
            ? collect()
            : RoomShiftRequirement::query()
                ->whereIn('room_id', $rooms->pluck('id')->all())
                ->get()
                ->groupBy('room_id');

        return $rooms->reduce(function (Collection $carry, Room $room) use ($shifts, $recorded): Collection {
            $room->setRelation('shiftRequirements', $recorded->get($room->id, collect()));
            $room->setRelation('department', $this->reference->departments()->get($room->department_id));

            foreach ($this->staffingRequirements->forRoomShifts($room, $shifts) as $shiftId => $requirement) {
                $carry->put($room->id.'|'.$shiftId, $requirement);
            }

            return $carry;
        }, collect());
    }

    /**
     * Today's duty for this unit — both the people it employs and anybody
     * borrowed into one of its rooms, since the board has to show a borrowed
     * nurse standing in the theatre she was lent to.
     *
     * @param  Collection<int, Room>  $rooms
     * @return Collection<int, ScheduleAssignment>
     */
    private function assignmentsFor(Department $department, Collection $rooms, Carbon $date): Collection
    {
        $roomIds = $rooms->pluck('id')->all();

        $assignments = ScheduleAssignment::query()
            ->with('employee')
            ->whereDate('work_date', $date->toDateString())
            ->where('status', 'scheduled')
            ->where(function (Builder $query) use ($department, $roomIds): void {
                $query->whereHas('employee', fn (Builder $employee) => $employee->where('department_id', $department->id));

                if ($roomIds !== []) {
                    $query->orWhereIn('room_id', $roomIds);
                }
            })
            ->get();

        $employees = $assignments->pluck('employee')->filter();
        $this->reference->attach($employees, 'position', 'position_id', Position::class);
        $this->reference->attach($employees, 'department', 'department_id', Department::class);

        return $assignments;
    }

    /**
     * @param  array<int, int>  $employeeIds
     * @return array<int, int>
     */
    private function employeesOnLeave(array $employeeIds, Carbon $date): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->pluck('employee_id')
            ->unique()
            ->values()
            ->all();
    }

    /** @return Collection<int, Shift> */
    private function shifts(): Collection
    {
        return $this->reference->shifts()
            ->where('is_active', true)
            ->sortBy('start_time')
            ->values();
    }

    private function asDate(Carbon|string $date): Carbon
    {
        return $date instanceof Carbon
            ? $date->copy()->timezone(config('schedule.timezone'))->startOfDay()
            : Carbon::parse($date, config('schedule.timezone'))->startOfDay();
    }
}

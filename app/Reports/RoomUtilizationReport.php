<?php

namespace App\Reports;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which rooms were staffed, by whom, and how much of it was borrowed.
 *
 * The room board answers today. This answers the month, which is the question
 * management actually asks: whether a second theatre earns its list, how often
 * one unit lends staff to another, and how much duty is still going unplaced.
 *
 * Rows are placements, not rooms -- one per shift worked in a room. Counting
 * rooms instead would hide exactly what makes the report worth running, which
 * is how unevenly they are used.
 */
class RoomUtilizationReport extends Report
{
    public function key(): string
    {
        return 'room-utilization';
    }

    public function label(): string
    {
        return 'Room use';
    }

    public function description(): string
    {
        return 'Every shift worked in a theatre, ward or clinic room, including staff borrowed between units.';
    }

    public function icon(): string
    {
        return 'hospital';
    }

    public function heading(): string
    {
        return 'Room Utilization Reports';
    }

    public function filters(): array
    {
        return ['department_id', 'employee_id'];
    }

    public function rangeLabel(): string
    {
        return 'Shifts worked between';
    }

    public function columns(): array
    {
        return [
            ReportColumn::make('Date', fn (ScheduleAssignment $r) => $r->work_date->toDateString()),
            ReportColumn::make('Room', fn (ScheduleAssignment $r) => $r->room?->code),
            ReportColumn::make('Room Name', fn (ScheduleAssignment $r) => $r->room?->name)->wide(),
            ReportColumn::make('Type', fn (ScheduleAssignment $r) => $r->room?->type_label),
            ReportColumn::make('Shift', fn (ScheduleAssignment $r) => $r->shift?->name),
            ReportColumn::make('Employee ID', fn (ScheduleAssignment $r) => $r->employee?->employee_number),
            ReportColumn::make('Employee', fn (ScheduleAssignment $r) => $r->employee?->full_name),
            ReportColumn::make('Position', fn (ScheduleAssignment $r) => $r->employee?->position?->title)->wide(),
            ReportColumn::make('Home Unit', fn (ScheduleAssignment $r) => $r->employee?->department?->name)->wide(),
            ReportColumn::make('Borrowed', fn (ScheduleAssignment $r) => $r->cross_unit ? 'Yes' : 'No'),
        ];
    }

    public function query(array $filters): Builder
    {
        return ScheduleAssignment::query()
            ->with(['employee.department', 'employee.position', 'shift', 'room'])
            ->whereNotNull('room_id')
            ->where('status', 'scheduled')
            ->whereBetween('work_date', [$this->rangeStart($filters), $this->rangeEnd($filters)])
            // The unit filter asks about the room's unit, not the employee's.
            // A theatre's use is a fact about the theatre; filtering by the
            // staff's home unit would drop every borrowed nurse from exactly
            // the report that exists to count them.
            ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                ->whereHas('room', fn (Builder $room) => $room->where('department_id', $departmentId)))
            ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                ->where('employee_id', $employeeId))
            ->orderByDesc('work_date')
            ->orderBy('room_id');
    }

    public function applyScope(Builder $query, ?User $user): Builder
    {
        return Employee::constrainRelatedQuery($query, $user);
    }

    public function summary(array $filters, ?User $user): array
    {
        $totals = $this->scopedQuery($filters, $user)
            ->reorder()
            ->toBase()
            ->selectRaw('count(*) as placements')
            ->selectRaw('count(distinct room_id) as rooms')
            ->selectRaw('count(distinct employee_id) as employees')
            ->selectRaw('sum(case when cross_unit = 1 then 1 else 0 end) as borrowed')
            ->first();

        // Duty in the same window that never got a room. Counted from the same
        // scoped range so the two figures answer the same question.
        $unplaced = Employee::constrainRelatedQuery(
            ScheduleAssignment::query()
                ->whereNull('room_id')
                ->where('status', 'scheduled')
                ->whereBetween('work_date', [$this->rangeStart($filters), $this->rangeEnd($filters)]),
            $user,
        )->toBase()->count();

        $placements = (int) ($totals->placements ?? 0);

        return [
            AttendanceReport::tile('Placements', number_format($placements), 'hospital', 'blue'),
            AttendanceReport::tile('Rooms used', number_format((int) ($totals->rooms ?? 0)), 'layers', 'violet'),
            AttendanceReport::tile('Staff placed', number_format((int) ($totals->employees ?? 0)), 'users', 'blue'),
            AttendanceReport::tile('Borrowed shifts', number_format((int) ($totals->borrowed ?? 0)), 'swap', 'amber'),
            AttendanceReport::tile('No room recorded', number_format($unplaced), 'circle', $unplaced > 0 ? 'amber' : 'green'),
            AttendanceReport::tile(
                'Placed',
                $placements + $unplaced === 0 ? '—' : round($placements / ($placements + $unplaced) * 100).'%',
                'check-circle',
                'green',
            ),
        ];
    }
}

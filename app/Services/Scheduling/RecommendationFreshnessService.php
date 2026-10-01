<?php

namespace App\Services\Scheduling;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Services\ScheduleService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;

class RecommendationFreshnessService
{
    public function __construct(private readonly ScheduleService $scheduleService) {}

    public function fingerprint(Department $department, Position $position, Shift $shift, string $workDate): string
    {
        $date = Carbon::parse($workDate, config('schedule.timezone'))->startOfDay();
        [$shiftStart, $shiftEnd] = $this->scheduleService->intervalFor($shift, $workDate);
        $employeeIds = Employee::query()->where('department_id', $department->id)->orderBy('id')->pluck('id');
        $rangeStart = $date->copy()->subDays(config('ai_workforce_scheduling.history_days'));
        $rangeEnd = $date->copy()->addDays(7);
        $assignments = ScheduleAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->orderBy('id')
            ->get(['id', 'employee_id', 'shift_id', 'work_date', 'status', 'updated_at']);
        $weekStart = ScheduleWeek::start($date);
        $weekEnd = ScheduleWeek::end($date);

        $snapshot = [
            'target' => [
                'department' => $this->modelState($department, ['is_active']),
                'position' => $this->modelState($position, ['department_id', 'is_active']),
                'shift' => $this->modelState($shift, ['start_time', 'end_time', 'break_minutes', 'is_active']),
                'work_date' => $date->toDateString(),
            ],
            'employees' => Employee::query()
                ->whereIn('id', $employeeIds)
                ->orderBy('id')
                ->get(['id', 'department_id', 'position_id', 'employment_status', 'updated_at'])
                ->map->attributesToArray()
                ->all(),
            'assignments' => $assignments->map->attributesToArray()->all(),
            'assignment_shifts' => Shift::query()
                ->whereIn('id', $assignments->pluck('shift_id')->push($shift->id)->unique())
                ->orderBy('id')
                ->get(['id', 'start_time', 'end_time', 'break_minutes', 'is_active', 'updated_at'])
                ->map->attributesToArray()
                ->all(),
            'approved_leaves' => LeaveRequest::query()
                ->whereIn('employee_id', $employeeIds)
                ->where('status', 'approved')
                ->whereDate('start_date', '<=', $shiftEnd->toDateString())
                ->whereDate('end_date', '>=', $shiftStart->toDateString())
                ->orderBy('id')
                ->get(['id', 'employee_id', 'start_date', 'end_date', 'status', 'updated_at'])
                ->map->attributesToArray()
                ->all(),
            'attendance' => AttendanceRecord::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereBetween('attendance_date', [$weekStart->toDateString(), $weekEnd->toDateString()])
                ->orderBy('id')
                ->get(['id', 'employee_id', 'attendance_date', 'approval_status', 'worked_minutes', 'overtime_minutes', 'updated_at'])
                ->map->attributesToArray()
                ->all(),
        ];

        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, string> $attributes @return array<string, mixed> */
    private function modelState(object $model, array $attributes): array
    {
        return collect(['id', ...$attributes, 'updated_at'])
            ->mapWithKeys(fn (string $attribute) => [$attribute => $model->{$attribute}])
            ->all();
    }
}

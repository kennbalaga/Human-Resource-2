<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleAssignmentRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleComplianceReview;
use App\Models\ScheduleDayOff;
use App\Models\ScheduleLock;
use App\Models\Shift;
use App\Services\ReferenceDataCache;
use App\Services\ScheduleService;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ScheduleCalendarController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(Request $request, AiSchedulingFeatureSettings $aiSettings, ReferenceDataCache $reference): View
    {
        $view = in_array($request->query('view'), ['month', 'week', 'list'], true)
            ? $request->query('view')
            : 'month';
        $focusDate = $this->focusDate($request->query('date'));
        [$rangeStart, $rangeEnd, $previousDate, $nextDate] = $this->calendarRange($view, $focusDate);
        $canManage = $this->canManage($request);
        $currentEmployee = $request->user()->employee;
        abort_if($currentEmployee === null, 403, 'Your account is not linked to an employee profile.');

        $filters = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        // Department, position and shift are filled from the reference cache
        // after each fetch below. They are a handful of rows between them, and
        // eager loading each one was a round trip of its own on a page that
        // already makes several.
        $assignmentsQuery = ScheduleAssignment::query()
            ->with(['employee', 'recurringSchedule'])
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()])
            ->where('status', 'scheduled');

        if (! $canManage) {
            $assignmentsQuery->where('employee_id', $currentEmployee->id);
        } else {
            // The supervised-department constraint goes on before the request's
            // own department filter, so a head can narrow their view but never
            // widen it past their unit.
            Employee::constrainRelatedQuery($assignmentsQuery, $request->user());
            $assignmentsQuery
                ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                    ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
                ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query
                    ->where('employee_id', $employeeId));
        }

        $assignments = $assignmentsQuery
            ->orderBy('work_date')
            ->get();

        // Filled before the sort below, which reads through to the shift.
        $reference->attach($assignments, 'shift', 'shift_id', Shift::class);
        $assignmentEmployees = $assignments->pluck('employee')->filter();
        $reference->attach($assignmentEmployees, 'department', 'department_id', Department::class);
        $reference->attach($assignmentEmployees, 'position', 'position_id', Position::class);

        $assignments = $assignments
            ->sortBy(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString().' '.$assignment->shift->start_time)
            ->values();
        $assignmentsByDate = $assignments->groupBy(fn (ScheduleAssignment $assignment) => $assignment->work_date->toDateString());

        $dayOffQuery = ScheduleDayOff::query()
            ->with('employee')
            ->whereBetween('work_date', [$rangeStart->toDateString(), $rangeEnd->toDateString()]);
        if (! $canManage) {
            $dayOffQuery->where('employee_id', $currentEmployee->id);
        } else {
            Employee::constrainRelatedQuery($dayOffQuery, $request->user());
            $dayOffQuery
                ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query
                    ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
                ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query->where('employee_id', $employeeId));
        }
        $dayOffs = $dayOffQuery->orderBy('work_date')->get();
        $reference->attach($dayOffs->pluck('employee')->filter(), 'department', 'department_id', Department::class);
        $dayOffsByDate = $dayOffs->groupBy(fn (ScheduleDayOff $dayOff) => $dayOff->work_date->toDateString());

        $leaveQuery = LeaveRequest::query()
            ->with('employee')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $rangeEnd->toDateString())
            ->whereDate('end_date', '>=', $rangeStart->toDateString());
        if (! $canManage) {
            $leaveQuery->where('employee_id', $currentEmployee->id);
        } else {
            Employee::constrainRelatedQuery($leaveQuery, $request->user());
            $leaveQuery
                ->when($filters['department_id'] ?? null, fn (Builder $query, $departmentId) => $query->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $departmentId)))
                ->when($filters['employee_id'] ?? null, fn (Builder $query, $employeeId) => $query->where('employee_id', $employeeId));
        }
        $leaves = $leaveQuery->get();
        $reference->attach($leaves, 'leaveType', 'leave_type_id', LeaveType::class);
        $reference->attach($leaves->pluck('employee')->filter(), 'department', 'department_id', Department::class);

        $leavesByDate = collect();
        foreach ($leaves as $leave) {
            foreach (CarbonPeriod::create($leave->start_date, $leave->end_date) as $leaveDate) {
                $date = Carbon::instance($leaveDate);
                if ($date->isWeekend() || $date->lt($rangeStart) || $date->gt($rangeEnd)) {
                    continue;
                }
                $key = $date->toDateString();
                $leavesByDate->put($key, $leavesByDate->get($key, collect())->push($leave));
            }
        }

        $calendarDays = collect(CarbonPeriod::create($rangeStart, $rangeEnd))
            ->map(fn ($date) => [
                'date' => Carbon::instance($date),
                'is_current_month' => Carbon::instance($date)->month === $focusDate->month,
                'is_today' => Carbon::instance($date)->isToday(),
                'assignments' => $assignmentsByDate->get(Carbon::instance($date)->toDateString(), collect()),
                'day_offs' => $dayOffsByDate->get(Carbon::instance($date)->toDateString(), collect()),
                'leaves' => $leavesByDate->get(Carbon::instance($date)->toDateString(), collect()),
            ]);

        $activeSeries = $canManage
            ? RecurringSchedule::query()
                ->with('employee')
                ->tap(fn (Builder $query) => Employee::constrainRelatedQuery($query, $request->user()))
                ->where('status', 'active')
                ->whereDate('end_date', '>=', now(config('schedule.timezone'))->toDateString())
                ->latest()
                ->limit(8)
                ->get()
            : collect();

        $reference->attach($activeSeries, 'shift', 'shift_id', Shift::class);
        $reference->attach($activeSeries->pluck('employee')->filter(), 'department', 'department_id', Department::class);

        // The pickers below are whole reference tables, so they are filtered and
        // sorted in PHP off the cached copy rather than re-queried.
        $activeShifts = $reference->shifts()
            ->where('is_active', true)
            ->sortBy('start_time')
            ->values();
        $activePositions = $reference->positions()
            ->where('is_active', true)
            ->sortBy('title')
            ->values();

        $pickerEmployees = $canManage
            ? Employee::query()
                ->visibleTo($request->user())
                ->where('employment_status', 'active')
                ->orderBy('last_name')
                ->get()
            : collect([$currentEmployee]);

        $reference->attach($pickerEmployees, 'department', 'department_id', Department::class);
        $reference->attach($pickerEmployees, 'position', 'position_id', Position::class);

        return view('schedules.index', [
            // Rank 1 is entry level, so it can never satisfy a "must have a senior
            // on duty" rule and is left out of the threshold choices.
            'seniorRankOptions' => collect(Position::SENIORITY_RANK_LABELS)
                ->filter(fn (string $label, int $rank) => $rank >= 2)
                ->all(),
            'defaultSeniorRank' => ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD,
            'calendarView' => $view,
            'focusDate' => $focusDate,
            'rangeStart' => $rangeStart,
            'rangeEnd' => $rangeEnd,
            'previousDate' => $previousDate,
            'nextDate' => $nextDate,
            'calendarDays' => $calendarDays,
            'assignments' => $assignments,
            'assignmentsByDate' => $assignmentsByDate,
            'dayOffsByDate' => $dayOffsByDate,
            'employees' => $pickerEmployees,
            'departments' => $this->selectableDepartments($request),
            'positions' => $canManage ? $activePositions : collect(),
            'shifts' => $activeShifts,
            'aiSchedulingEnabled' => $canManage && $aiSettings->assistantEnabled(),
            'aiPositions' => $canManage && $aiSettings->assistantEnabled()
                ? Position::query()->where('is_active', true)->whereHas('department', fn (Builder $query) => $query->where('is_active', true))->orderBy('title')->get()
                : collect(),
            'activeSeries' => $activeSeries,
            'filters' => $filters,
            'canManage' => $canManage,
            'canManageData' => $canManage && $request->user()->canManageData(),
            'canLockSchedule' => Gate::forUser($request->user())->allows('hr.manage'),
            'activeLocks' => Gate::forUser($request->user())->allows('hr.manage')
                ? ScheduleLock::query()->with('department')->whereNull('unlocked_at')->orderBy('start_date')->get()
                : collect(),
            'recentComplianceReviews' => Gate::forUser($request->user())->allows('hr.manage')
                ? ScheduleComplianceReview::query()->with('department')->latest('created_at')->limit(10)->get()
                : collect(),
            'stats' => [
                'assignments' => $assignments->count(),
                'employees' => $assignments->pluck('employee_id')->unique()->count(),
                'hours' => round($assignments->sum(fn ($assignment) => $assignment->shift->duration_minutes) / 60, 1),
                'overnight' => $assignments->filter(fn ($assignment) => $assignment->shift->crosses_midnight)->count(),
            ],
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
        ]);
    }

    public function events(Request $request, ScheduleService $scheduleService): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['required', 'date'],
            'end' => ['required', 'date', 'after_or_equal:start'],
            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);
        $canManage = $this->canManage($request);
        $employee = $request->user()->employee;
        abort_if($employee === null, 403);

        $assignments = ScheduleAssignment::query()
            ->with(['employee.department', 'shift'])
            ->whereBetween('work_date', [$validated['start'], $validated['end']])
            ->where('status', 'scheduled')
            ->when($canManage, fn (Builder $query) => Employee::constrainRelatedQuery($query, $request->user()))
            ->when(! $canManage, fn (Builder $query) => $query->where('employee_id', $employee->id))
            ->when($canManage && ! empty($validated['employee_id']), fn (Builder $query) => $query->where('employee_id', $validated['employee_id']))
            ->when($canManage && ! empty($validated['department_id']), fn (Builder $query) => $query
                ->whereHas('employee', fn (Builder $employeeQuery) => $employeeQuery->where('department_id', $validated['department_id'])))
            ->get();

        return response()->json($assignments->map(function (ScheduleAssignment $assignment) use ($scheduleService) {
            [$start, $end] = $scheduleService->intervalFor($assignment->shift, $assignment->work_date->toDateString());

            return [
                'id' => $assignment->id,
                'title' => $assignment->employee->full_name.' · '.$assignment->shift->name,
                'start' => $start->toIso8601String(),
                'end' => $end->toIso8601String(),
                'color' => $assignment->shift->color,
                'employee_id' => $assignment->employee_id,
                'department' => $assignment->employee->department?->name,
                'recurring' => $assignment->recurring_schedule_id !== null,
            ];
        }));
    }

    public function conflicts(ScheduleAssignmentRequest $request, ScheduleService $scheduleService): JsonResponse
    {
        $data = $request->validated();
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $shift = Shift::query()->findOrFail($data['shift_id']);
        $conflicts = $scheduleService->conflictsFor(
            $employee,
            $shift,
            $data['work_date'],
            $data['exclude_assignment_id'] ?? null,
        );
        $dayOff = $scheduleService->dayOffFor($employee, $data['work_date']);
        $restConflicts = $scheduleService->restConflictsFor(
            $employee,
            $shift,
            $data['work_date'],
            $data['exclude_assignment_id'] ?? null,
        );

        return response()->json([
            'has_conflicts' => $conflicts->isNotEmpty() || $restConflicts->isNotEmpty() || $dayOff !== null,
            'day_off' => $dayOff ? ['date' => $dayOff->work_date->toDateString()] : null,
            'rest_conflicts' => $restConflicts->map(fn (ScheduleAssignment $assignment) => [
                'id' => $assignment->id,
                'date' => $assignment->work_date->toDateString(),
                'shift' => $assignment->shift->name,
                'time' => $assignment->shift->formatted_time,
            ]),
            'conflicts' => $conflicts->map(fn (ScheduleAssignment $assignment) => [
                'id' => $assignment->id,
                'date' => $assignment->work_date->toDateString(),
                'shift' => $assignment->shift->name,
                'time' => $assignment->shift->formatted_time,
            ]),
        ]);
    }

    private function canManage(Request $request): bool
    {
        return Gate::forUser($request->user())->allows('workforce.view');
    }

    private function focusDate(?string $date): Carbon
    {
        try {
            return $date
                ? Carbon::parse($date, config('schedule.timezone'))->startOfDay()
                : now(config('schedule.timezone'))->startOfDay();
        } catch (\Throwable) {
            return now(config('schedule.timezone'))->startOfDay();
        }
    }

    /**
     * @return array{Carbon, Carbon, Carbon, Carbon}
     */
    private function calendarRange(string $view, Carbon $focusDate): array
    {
        if ($view === 'week') {
            return [
                $focusDate->copy()->startOfWeek(Carbon::MONDAY),
                $focusDate->copy()->endOfWeek(Carbon::SUNDAY),
                $focusDate->copy()->subWeek(),
                $focusDate->copy()->addWeek(),
            ];
        }

        $rangeStart = $focusDate->copy()->startOfMonth();
        $rangeEnd = $focusDate->copy()->endOfMonth();

        if ($view === 'month') {
            $rangeStart->startOfWeek(Carbon::MONDAY);
            $rangeEnd->endOfWeek(Carbon::SUNDAY);
        }

        return [$rangeStart, $rangeEnd, $focusDate->copy()->subMonth(), $focusDate->copy()->addMonth()];
    }
}

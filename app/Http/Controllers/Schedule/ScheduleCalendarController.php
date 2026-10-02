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
use App\Services\Schedule\MyScheduleService;
use App\Services\ScheduleService;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use App\Services\Scheduling\StaffingRequirementService;
use App\Support\ScheduleWeek;
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

    public function index(
        Request $request,
        AiSchedulingFeatureSettings $aiSettings,
        ReferenceDataCache $reference,
        MyScheduleService $mySchedule,
    ): View {
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
                ->notArchived()
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
            /* The phone's own read of this page: one day at a time, from the
               reader's own roster. Built here rather than in the view so the
               grid below and the strip above cannot drift apart on the date
               they are focused on. */
            'myPhoneSchedule' => $mySchedule->forEmployee($currentEmployee, $request->query('date')),
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

    /**
     * The full published roster for one date, grouped by department —
     * everything a manager sees when they click a shift, replacing the old
     * single-assignment popup with the whole day's coverage at once.
     */
    public function dayRoster(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request) && $request->user()->canManageData(), 403);

        $validated = $request->validate(['date' => ['required', 'date']]);
        $today = now(config('schedule.timezone'))->startOfDay();
        $isEditable = Carbon::parse($validated['date'], config('schedule.timezone'))->startOfDay()->greaterThan($today);

        $assignments = ScheduleAssignment::query()
            ->with(['employee.department', 'employee.position', 'shift'])
            ->where('work_date', $validated['date'])
            ->where('status', 'scheduled')
            ->tap(fn (Builder $query) => Employee::constrainRelatedQuery($query, $request->user()))
            ->get();

        $rows = $assignments->map(function (ScheduleAssignment $assignment) use ($isEditable) {
            $hour = (int) Carbon::parse($assignment->shift->start_time)->format('G');
            $shiftType = match (true) {
                $hour >= 6 && $hour < 14 => 'day',
                $hour >= 14 && $hour < 18 => 'evening',
                default => 'night',
            };

            return [
                'id' => $assignment->id,
                'employee_id' => $assignment->employee_id,
                'shift_id' => $assignment->shift_id,
                'date' => $assignment->work_date->toDateString(),
                'department' => $assignment->employee->department?->name ?? 'Unassigned',
                'department_id' => $assignment->employee->department_id,
                'position' => $assignment->employee->position?->title ?? '—',
                'employee' => $assignment->employee->full_name,
                'employee_number' => $assignment->employee->employee_number,
                'shift' => $assignment->shift->name,
                'shift_type' => $shiftType,
                'time' => $assignment->shift->formatted_time,
                'notes' => $assignment->notes,
                'recurring' => $assignment->recurring_schedule_id !== null,
                'editable' => $isEditable,
            ];
        });

        $departments = $rows
            ->groupBy('department')
            ->map(fn ($group, $name) => [
                'name' => $name,
                'rows' => $group->sortBy('employee')->values(),
            ])
            ->sortBy('name')
            ->values();

        return response()->json([
            'date' => $validated['date'],
            'formatted_date' => Carbon::parse($validated['date'], config('schedule.timezone'))->format('l, F j, Y'),
            'departments' => $departments,
            'summary' => [
                'total_staff' => $rows->count(),
                'departments_active' => $departments->count(),
                'coverage' => [
                    'day' => $rows->where('shift_type', 'day')->count(),
                    'evening' => $rows->where('shift_type', 'evening')->count(),
                    'night' => $rows->where('shift_type', 'night')->count(),
                ],
            ],
        ]);
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
        $leave = $scheduleService->approvedLeaveFor($employee, $shift, $data['work_date']);
        $restConflicts = $scheduleService->restConflictsFor(
            $employee,
            $shift,
            $data['work_date'],
            $data['exclude_assignment_id'] ?? null,
        );

        $scheduledMinutes = $scheduleService->weeklyPaidMinutes($employee, $data['work_date'], $data['exclude_assignment_id'] ?? null);
        $weekStart = ScheduleWeek::start(Carbon::parse($data['work_date'], config('schedule.timezone')));

        return response()->json([
            'has_conflicts' => $conflicts->isNotEmpty() || $restConflicts->isNotEmpty() || $dayOff !== null || $leave !== null,
            // Advisory: a hand-made assignment may run into paid overtime, so
            // the weekly limit is shown, not enforced, on this form.
            'week' => [
                'start' => $weekStart->toDateString(),
                'end' => ScheduleWeek::end($weekStart)->toDateString(),
                'scheduled_hours' => round($scheduledMinutes / 60, 1),
                'shift_hours' => round($shift->duration_minutes / 60, 1),
                'after_hours' => round(($scheduledMinutes + $shift->duration_minutes) / 60, 1),
                'limit' => (int) config('schedule.compliance.max_hours_per_week'),
            ],
            'day_off' => $dayOff ? ['date' => $dayOff->work_date->toDateString()] : null,
            'leave' => $leave ? [
                'start_date' => $leave->start_date->toDateString(),
                'end_date' => $leave->end_date->toDateString(),
            ] : null,
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

    /**
     * Who already stands on one shift on one date in a department, against the
     * unit's own staffing standard — the "1 of 1 already staffed" line on the
     * assignment form, shown before anyone is picked.
     */
    public function coverage(Request $request, StaffingRequirementService $staffing): JsonResponse
    {
        abort_unless($this->canManage($request), 403);
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
            'exclude_assignment_id' => ['nullable', 'integer'],
        ]);
        $allowed = $this->supervisedDepartmentIds($request);
        abort_if($allowed !== null && ! in_array((int) $data['department_id'], $allowed, true), 403);

        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);
        $shift = Shift::query()->findOrFail($data['shift_id']);
        $standing = ScheduleAssignment::query()
            ->with('employee')
            ->where('shift_id', $shift->id)
            ->where('status', 'scheduled')
            ->whereDate('work_date', $data['work_date'])
            ->whereHas('employee', fn (Builder $query) => $query->where('department_id', $department->id))
            ->when($data['exclude_assignment_id'] ?? null, fn (Builder $query, mixed $id) => $query->where('id', '!=', (int) $id))
            ->get();
        $requirement = $staffing->forShift($department, $shift);

        return response()->json([
            'department' => $department->name,
            'shift' => $shift->name,
            'staffed' => $standing->count(),
            'required' => (int) $requirement['staff'],
            'source' => $requirement['source'],
            'names' => $standing->map(fn (ScheduleAssignment $assignment) => $assignment->employee?->full_name)->filter()->values(),
        ]);
    }

    /**
     * What each employee in a department already carries over a period: the
     * shifts a previous run or a hand-made assignment put on them, and the
     * days approved leave already covers. This is the "This period" column on
     * Step 1, read before anyone is ticked rather than discovered on the board.
     */
    public function staffLoad(Request $request): JsonResponse
    {
        abort_unless($this->canManage($request), 403);
        $data = $request->validate([
            'department_id' => ['required', 'integer', 'exists:departments,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
        ]);
        $allowed = $this->supervisedDepartmentIds($request);
        abort_if($allowed !== null && ! in_array((int) $data['department_id'], $allowed, true), 403);

        // Normalised once: the rest of this method compares plain ISO dates,
        // which only holds if that is what they actually are.
        $timezone = config('schedule.timezone');
        $data['start_date'] = Carbon::parse($data['start_date'], $timezone)->toDateString();
        $data['end_date'] = Carbon::parse($data['end_date'], $timezone)->toDateString();

        $employeeIds = Employee::query()
            ->where('department_id', $data['department_id'])
            ->notArchived()
            ->where('employment_status', '!=', 'terminated')
            ->pluck('id');
        $range = [$data['start_date'], $data['end_date']];

        $shifts = ScheduleAssignment::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'scheduled')
            ->whereBetween('work_date', $range)
            ->selectRaw('employee_id, count(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');
        $daysOff = ScheduleDayOff::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', $range)
            ->selectRaw('employee_id, count(*) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id');
        // Counted as days inside the period, not as whole requests: a leave
        // running past the period only blocks the part that overlaps it.
        $leaveDays = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $data['end_date'])
            ->whereDate('end_date', '>=', $data['start_date'])
            ->get()
            ->groupBy('employee_id')
            // Compared as plain dates: a leave and a period parsed in different
            // zones are hours apart, and whole days is the only unit here.
            ->map(fn ($requests) => $requests->sum(function (LeaveRequest $leave) use ($data): int {
                $tz = config('schedule.timezone');
                $from = Carbon::parse(max($leave->start_date->toDateString(), $data['start_date']), $tz);
                $to = Carbon::parse(min($leave->end_date->toDateString(), $data['end_date']), $tz);

                return (int) round($from->diffInDays($to)) + 1;
            }));

        return response()->json([
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'employees' => $employeeIds->mapWithKeys(fn (int $id) => [$id => [
                'shifts' => (int) ($shifts[$id] ?? 0),
                'days_off' => (int) ($daysOff[$id] ?? 0),
                'leave_days' => (int) ($leaveDays[$id] ?? 0),
            ]]),
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
                ScheduleWeek::start($focusDate),
                ScheduleWeek::end($focusDate),
                $focusDate->copy()->subWeek(),
                $focusDate->copy()->addWeek(),
            ];
        }

        $rangeStart = $focusDate->copy()->startOfMonth();
        $rangeEnd = $focusDate->copy()->endOfMonth();

        if ($view === 'month') {
            $rangeStart = ScheduleWeek::start($rangeStart);
            $rangeEnd = ScheduleWeek::end($rangeEnd);
        }

        return [$rangeStart, $rangeEnd, $focusDate->copy()->subMonth(), $focusDate->copy()->addMonth()];
    }
}

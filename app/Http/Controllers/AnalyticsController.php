<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\Timesheet;
use App\Services\Integrations\GeminiAnalyticsService;
use App\Support\SpreadsheetExport;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnalyticsController extends Controller
{
    use ScopesWorkforceAccess;

    public function index(AnalyticsRequest $request): View
    {
        $data = $this->cachedAnalytics($request->validated(), $this->supervisedDepartmentIds($request));

        return view('analytics.index', $data + [
            'filters' => $request->validated(),
            'departments' => $this->selectableDepartments($request),
            'canManageData' => $request->user()->canManageData(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'notifications' => collect(),
            'aiInsight' => Cache::get($this->insightCacheKey($request->user()->id, $request->validated())),
        ]);
    }

    public function aiInsights(AnalyticsRequest $request, GeminiAnalyticsService $service): RedirectResponse
    {
        $filters = $request->validated();
        $analytics = $this->cachedAnalytics($filters, $this->supervisedDepartmentIds($request));
        $result = $service->generateInsights([
            'period' => ['from' => $filters['date_from'], 'to' => $filters['date_to']],
            'metrics' => $analytics['metrics'],
            'departments' => $analytics['departmentMetrics']->values()->all(),
        ]);

        if ($result->success) {
            Cache::put($this->insightCacheKey($request->user()->id, $filters), $result->data['insight'], now()->addMinutes(30));
        }

        return back()->with($result->success ? 'success' : 'warning', $result->message);
    }

    public function export(AnalyticsRequest $request): StreamedResponse
    {
        $filters = $request->validated();
        $data = $this->cachedAnalytics($filters, $this->supervisedDepartmentIds($request));

        return response()->streamDownload(function () use ($data, $filters): void {
            $output = fopen('php://output', 'w');
            SpreadsheetExport::writeCsvRow($output, ['Workforce Analytics Report']);
            SpreadsheetExport::writeCsvRow($output, ['Period', $filters['date_from'].' to '.$filters['date_to']]);
            SpreadsheetExport::writeCsvRow($output, []);
            SpreadsheetExport::writeCsvRow($output, ['Metric', 'Value']);
            foreach ($data['metrics'] as $label => $value) {
                SpreadsheetExport::writeCsvRow($output, [str($label)->headline()->toString(), $value]);
            }
            SpreadsheetExport::writeCsvRow($output, []);
            SpreadsheetExport::writeCsvRow($output, ['Department', 'Active Employees', 'Attendance Records', 'Attendance Rate', 'Average Worked Hours', 'Approved Leave Days']);
            foreach ($data['departmentMetrics'] as $department) {
                SpreadsheetExport::writeCsvRow($output, [$department['name'], $department['employees'], $department['attendance'], $department['attendance_rate'], $department['average_hours'], $department['leave_days']]);
            }
            SpreadsheetExport::writeCsvRow($output, []);
            SpreadsheetExport::writeCsvRow($output, ['Date', 'Attendance Records', 'Late Records', 'Worked Hours']);
            foreach ($data['attendanceTrend'] as $day) {
                SpreadsheetExport::writeCsvRow($output, [$day['date'], $day['records'], $day['late'], $day['hours']]);
            }
            fclose($output);
        }, 'workforce-analytics-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>|null  $departmentIds  null for the org-wide roles
     * @return array<string, mixed>
     */
    private function analytics(array $filters, ?array $departmentIds): array
    {
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);
        $departmentId = $filters['department_id'] ?? null;
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereIn('department_id', $departmentIds ?? []))
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->get();
        $employeeIds = $employees->pluck('id');
        $attendance = AttendanceRecord::query()
            ->whereDate('attendance_date', '>=', $from->toDateString())
            ->whereDate('attendance_date', '<=', $to->toDateString())
            ->whereIn('employee_id', $employeeIds)
            ->get();
        $leaves = LeaveRequest::query()
            ->with('leaveType')
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $to->toDateString())
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereIn('employee_id', $employeeIds)
            ->get();
        $leaves->each(fn (LeaveRequest $leave) => $leave->setAttribute('analytics_days', $this->leaveDaysWithin($leave, $from, $to)));
        $timesheets = Timesheet::query()
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereIn('employee_id', $employeeIds)
            ->get();
        $scheduled = ScheduleAssignment::query()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->where('status', 'scheduled')
            ->whereIn('employee_id', $employeeIds)
            ->count();
        $workdays = collect(CarbonPeriod::create($from, $to))->reject(fn ($date) => Carbon::instance($date)->isWeekend())->count();
        $possibleAttendance = max(1, $employees->count() * $workdays);
        $attendanceRate = round(min(100, $attendance->count() / $possibleAttendance * 100), 1);

        $departments = Department::query()
            ->where('is_active', true)
            ->when($departmentIds !== null, fn (Builder $query) => $query->whereIn('id', $departmentIds ?? []))
            ->when($departmentId, fn (Builder $query) => $query->whereKey($departmentId))
            ->orderBy('name')
            ->get();
        // The active employees are already in memory from the query above, so the
        // per-department roster is grouped here rather than re-queried once per
        // department -- that loop was one round trip per row of this table.
        $employeeIdsByDepartment = $employees->groupBy('department_id')->map->pluck('id');

        $departmentMetrics = $departments->map(function (Department $department) use ($attendance, $leaves, $workdays, $employeeIdsByDepartment) {
            $departmentEmployeeIds = $employeeIdsByDepartment->get($department->id) ?? collect();
            $departmentAttendance = $attendance->whereIn('employee_id', $departmentEmployeeIds);
            $possible = max(1, $departmentEmployeeIds->count() * $workdays);

            return [
                'name' => $department->name,
                'code' => $department->code,
                'employees' => $departmentEmployeeIds->count(),
                'attendance' => $departmentAttendance->count(),
                'attendance_rate' => round(min(100, $departmentAttendance->count() / $possible * 100), 1),
                'average_hours' => $departmentAttendance->count() ? round($departmentAttendance->avg('worked_minutes') / 60, 1) : 0,
                'leave_days' => round((float) $leaves->whereIn('employee_id', $departmentEmployeeIds)->sum('analytics_days'), 1),
            ];
        });
        $attendanceByDate = $attendance->groupBy(fn (AttendanceRecord $record) => $record->attendance_date->toDateString());
        $attendanceTrend = collect(CarbonPeriod::create($from, $to))->map(function ($date) use ($attendanceByDate) {
            $dateString = Carbon::instance($date)->toDateString();
            $records = $attendanceByDate->get($dateString, collect());

            return [
                'date' => $dateString,
                'label' => Carbon::instance($date)->format('M j'),
                'records' => $records->count(),
                'late' => $records->where('status', 'late')->count(),
                'hours' => round($records->sum('worked_minutes') / 60, 1),
            ];
        });
        $leaveMix = $leaves->groupBy('leave_type_id')->map(function (Collection $requests) {
            $type = $requests->first()->leaveType;

            return ['name' => $type->name, 'color' => $type->color, 'days' => round((float) $requests->sum('analytics_days'), 1)];
        })->values();
        $timesheetStatuses = collect(['draft', 'submitted', 'approved', 'rejected'])->map(fn ($status) => [
            'status' => $status,
            'count' => $timesheets->where('status', $status)->count(),
        ]);

        return [
            'metrics' => [
                'active_headcount' => $employees->count(),
                'attendance_rate' => $attendanceRate,
                'worked_hours' => round($attendance->sum('worked_minutes') / 60, 1),
                'overtime_hours' => round($attendance->sum('overtime_minutes') / 60, 1),
                'late_events' => $attendance->where('status', 'late')->count(),
                'approved_leave_days' => round((float) $leaves->sum('analytics_days'), 1),
                'scheduled_shifts' => $scheduled,
                'approved_timesheets' => $timesheets->where('status', 'approved')->count(),
                ...$this->adherenceMetrics($attendance),
            ],
            'departmentMetrics' => $departmentMetrics,
            'attendanceTrend' => $attendanceTrend,
            'leaveMix' => $leaveMix,
            'timesheetStatuses' => $timesheetStatuses,
            'chartMax' => max(1, (int) $attendanceTrend->max('records')),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    /**
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>|null  $departmentIds  null for the org-wide roles
     * @return array<string, mixed>
     */
    private function cachedAnalytics(array $filters, ?array $departmentIds): array
    {
        // The reach is part of the key, not just the filters. Without it the
        // first head to load a period would populate a cache entry that the
        // next head -- or a plain HR request for the whole hospital -- would
        // then be served out of.
        $key = 'analytics.dataset.v3.'.hash('sha256', json_encode([$filters, $departmentIds]));
        $resolver = function () use ($filters, $departmentIds): array {
            $data = $this->analytics($filters, $departmentIds);

            foreach (['departmentMetrics', 'attendanceTrend', 'leaveMix', 'timesheetStatuses'] as $field) {
                $data[$field] = $data[$field]->values()->all();
            }

            return $data;
        };
        $data = Cache::remember($key, now()->addMinutes(2), $resolver);

        if (! $this->validCachedAnalytics($data)) {
            Cache::forget($key);
            $data = $resolver();
            Cache::put($key, $data, now()->addMinutes(2));
        }

        foreach (['departmentMetrics', 'attendanceTrend', 'leaveMix', 'timesheetStatuses'] as $field) {
            $data[$field] = collect($data[$field]);
        }

        return $data;
    }

    private function validCachedAnalytics(mixed $data): bool
    {
        if (! is_array($data) || ! is_array($data['metrics'] ?? null)) {
            return false;
        }

        foreach (['departmentMetrics', 'attendanceTrend', 'leaveMix', 'timesheetStatuses'] as $field) {
            if (! is_array($data[$field] ?? null)) {
                return false;
            }
        }

        return isset($data['chartMax']);
    }

    /**
     * Schedule-adherence figures, computed from columns AttendanceService
     * writes on every check-in (schedule_status, binding_source,
     * shift_start_at/shift_end_at, override_authorised_by) — no extra query,
     * just reductions over the $attendance collection already loaded above.
     *
     * Caveat: records written before schedule-aware attendance shipped hold
     * those columns at their migration default (binding_source='scheduled',
     * schedule_status='on_shift') rather than a real resolution outcome, so a
     * date range reaching back before that rollout will read as misleadingly
     * perfect here. Not backfillable — there's no way to know what an old
     * punch would have resolved to.
     *
     * @param  Collection<int, AttendanceRecord>  $attendance
     * @return array<string, float>
     */
    private function adherenceMetrics(Collection $attendance): array
    {
        $bound = $attendance->where('binding_source', 'scheduled');
        $onShift = $bound->where('schedule_status', 'on_shift');
        $offShift = $attendance->where('binding_source', 'override');
        $overridden = $attendance->whereNotNull('override_authorised_by');

        $variances = $bound
            ->filter(fn (AttendanceRecord $record) => $record->shift_start_at !== null && $record->shift_end_at !== null)
            ->map(fn (AttendanceRecord $record) => $record->worked_minutes - $record->shift_start_at->diffInMinutes($record->shift_end_at));

        return [
            'schedule_adherence_rate' => $bound->isNotEmpty() ? round($onShift->count() / $bound->count() * 100, 1) : 0.0,
            'off_shift_rate' => $attendance->isNotEmpty() ? round($offShift->count() / $attendance->count() * 100, 1) : 0.0,
            'override_rate' => $attendance->isNotEmpty() ? round($overridden->count() / $attendance->count() * 100, 1) : 0.0,
            'plan_vs_actual_variance_hours' => $variances->isNotEmpty() ? round($variances->avg() / 60, 1) : 0.0,
        ];
    }

    private function leaveDaysWithin(LeaveRequest $leave, Carbon $from, Carbon $to): float
    {
        $start = $leave->start_date->greaterThan($from) ? $leave->start_date : $from;
        $end = $leave->end_date->lessThan($to) ? $leave->end_date : $to;

        return (float) collect(CarbonPeriod::create($start, $end))
            ->reject(fn ($date) => Carbon::instance($date)->isWeekend())
            ->count();
    }

    private function insightCacheKey(int $userId, array $filters): string
    {
        return 'analytics.ai.'.hash('sha256', $userId.'|'.json_encode($filters));
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\Analytics\AnalyticsRequest;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\Timesheet;
use App\Services\Integrations\GeminiAnalyticsService;
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
    public function index(AnalyticsRequest $request): View
    {
        $data = $this->cachedAnalytics($request->validated());

        return view('analytics.index', $data + [
            'filters' => $request->validated(),
            'departments' => Department::query()->where('is_active', true)->orderBy('name')->get(),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
            'notifications' => collect(),
            'aiInsight' => Cache::get($this->insightCacheKey($request->user()->id, $request->validated())),
        ]);
    }

    public function aiInsights(AnalyticsRequest $request, GeminiAnalyticsService $service): RedirectResponse
    {
        $filters = $request->validated();
        $analytics = $this->cachedAnalytics($filters);
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
        $data = $this->cachedAnalytics($filters);

        return response()->streamDownload(function () use ($data, $filters): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Workforce Analytics Report']);
            fputcsv($output, ['Period', $filters['date_from'].' to '.$filters['date_to']]);
            fputcsv($output, []);
            fputcsv($output, ['Metric', 'Value']);
            foreach ($data['metrics'] as $label => $value) {
                fputcsv($output, [str($label)->headline()->toString(), $value]);
            }
            fputcsv($output, []);
            fputcsv($output, ['Department', 'Active Employees', 'Attendance Records', 'Attendance Rate', 'Average Worked Hours', 'Approved Leave Days']);
            foreach ($data['departmentMetrics'] as $department) {
                fputcsv($output, [$department['name'], $department['employees'], $department['attendance'], $department['attendance_rate'], $department['average_hours'], $department['leave_days']]);
            }
            fputcsv($output, []);
            fputcsv($output, ['Date', 'Attendance Records', 'Late Records', 'Worked Hours']);
            foreach ($data['attendanceTrend'] as $day) {
                fputcsv($output, [$day['date'], $day['records'], $day['late'], $day['hours']]);
            }
            fclose($output);
        }, 'workforce-analytics-'.$filters['date_from'].'-to-'.$filters['date_to'].'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function analytics(array $filters): array
    {
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);
        $departmentId = $filters['department_id'] ?? null;
        $employees = Employee::query()
            ->where('employment_status', 'active')
            ->when($departmentId, fn (Builder $query) => $query->where('department_id', $departmentId))
            ->get();
        $employeeIds = $employees->pluck('id');
        $attendance = AttendanceRecord::query()
            ->whereBetween('attendance_date', [$from->toDateString(), $to->toDateString()])
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
            ->when($departmentId, fn (Builder $query) => $query->whereKey($departmentId))
            ->orderBy('name')
            ->get();
        $departmentMetrics = $departments->map(function (Department $department) use ($attendance, $leaves, $workdays) {
            $departmentEmployeeIds = Employee::query()->where('department_id', $department->id)->where('employment_status', 'active')->pluck('id');
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
            ],
            'departmentMetrics' => $departmentMetrics,
            'attendanceTrend' => $attendanceTrend,
            'leaveMix' => $leaveMix,
            'timesheetStatuses' => $timesheetStatuses,
            'chartMax' => max(1, (int) $attendanceTrend->max('records')),
        ];
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    private function cachedAnalytics(array $filters): array
    {
        $key = 'analytics.dataset.'.hash('sha256', json_encode($filters));

        return Cache::remember($key, now()->addMinutes(2), fn () => $this->analytics($filters));
    }

    private function leaveDaysWithin(LeaveRequest $leave, Carbon $from, Carbon $to): float
    {
        $start = $leave->start_date->greaterThan($from) ? $leave->start_date : $from;
        $end = $leave->end_date->lessThan($to) ? $leave->end_date : $to;

        return (float) collect(CarbonPeriod::create($start, $end))
            ->reject(fn ($date) => Carbon::instance($date)->isWeekend())
            ->count();
    }

    /** @param array<string, mixed> $filters */
    private function insightCacheKey(int $userId, array $filters): string
    {
        return 'analytics.ai.'.hash('sha256', $userId.'|'.json_encode($filters));
    }
}

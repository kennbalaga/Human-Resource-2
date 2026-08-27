<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AttendanceOverviewService;
use App\Services\ShiftOverviewService;
use App\Services\StaffDashboardService;
use App\Services\WorkforceAnalyticsPreviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        AttendanceOverviewService $attendanceOverview,
        ShiftOverviewService $shiftOverview,
        WorkforceAnalyticsPreviewService $analyticsPreview,
        StaffDashboardService $staffDashboard,
    ): View {
        $canManageWorkforce = $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty();

        // Staff open their own day, not the hospital's. The org-wide dashboard below
        // is a management tool -- headcounts, department shares, the full roster --
        // and none of it answers the questions a nurse signs in with. An account
        // without a workforce profile has no "own day" to show, so it keeps the
        // overview rather than landing on nine empty widgets.
        $employee = $request->user()->employee;

        if (! $canManageWorkforce && $employee !== null) {
            return view('dashboard.staff', [
                'dashboard' => $staffDashboard->forEmployee($employee),
                'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
                'notifications' => collect(),
            ]);
        }

        $counts = $this->summaryCounts($request->user());
        $activeEmployees = $counts['active_employees'];

        $stats = [
            'employees' => $counts['employees'],
            'active_employees' => $activeEmployees,
            'departments' => $counts['departments'],
            'positions' => $counts['positions'],
            'new_this_month' => $counts['new_this_month'],
        ];

        // The employee total is already known from the summary query above, so it
        // is handed to the paginator rather than paying for a second COUNT.
        $recentEmployees = Employee::query()
            ->visibleTo($request->user())
            ->with(['user', 'department', 'position'])
            // Newest first, with the id breaking ties. Staff taken on in one
            // batch — an import, or a unit opening — share a created_at to the
            // second, and ordering on that alone leaves those rows in whatever
            // sequence the database happens to return. Each page is its own
            // query, so a tie can list the same employee twice or drop one
            // between page 1 and page 2.
            ->latest()
            ->latest('id')
            ->paginate(5, ['*'], 'employees_page', null, $counts['employees'])
            ->withQueryString()
            ->fragment('employee-overview');

        $departments = Department::query()
            ->withCount([
                'employees as active_employees_count' => fn ($query) => $query
                    ->where('employment_status', 'active'),
            ])
            ->where('is_active', true)
            ->orderByDesc('active_employees_count')
            ->orderBy('name')
            ->limit(6)
            ->get();

        $notifications = collect([
            [
                'tone' => 'success',
                'icon' => 'check-circle',
                'title' => 'Workforce modules are connected',
                'message' => 'Schedules, attendance, timesheets, leave, and analytics are available.',
                'time' => 'Today',
            ],
            [
                'tone' => 'primary',
                'icon' => 'users',
                'title' => $activeEmployees.' active employees',
                'message' => 'Employee profiles are available in the workforce database.',
                'time' => 'Today',
            ],
            [
                'tone' => 'warning',
                'icon' => 'clock',
                'title' => $counts['submitted_timesheets'].' timesheets awaiting review',
                'message' => $counts['pending_leave_requests'].' leave requests are also pending approval.',
                'time' => 'Action required',
            ],
        ]);

        return view('welcome', [
            'stats' => $stats,
            'recentEmployees' => $recentEmployees,
            'departments' => $departments,
            'canManageWorkforce' => $canManageWorkforce,
            'notifications' => $notifications,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'attendanceOverview' => $attendanceOverview->forRange(
                (int) $request->integer('attendance_days', AttendanceOverviewService::RANGES[0]),
            ),
            'shiftOverview' => $shiftOverview->forToday(),
            // The analytics module is closed to everyone outside these roles, so the
            // preview is neither built nor rendered for a viewer who cannot open it.
            'analyticsPreview' => $canManageWorkforce ? $analyticsPreview->forCurrentMonth() : null,
        ]);
    }

    /**
     * Collect every dashboard headline figure in one round trip. Each figure was
     * previously its own COUNT query, which is costly against a remote database
     * where latency, not query weight, dominates the page load.
     *
     * The people-counting figures are narrowed to the departments this account
     * supervises, so a head's headline numbers describe their own unit rather
     * than the hospital. Department and position counts stay whole: they
     * describe the shape of the organisation, not anybody's records.
     *
     * @return array<string, int>
     */
    private function summaryCounts(?User $user): array
    {
        $subCounts = [
            'employees' => Employee::query()->visibleTo($user),
            'active_employees' => Employee::query()->visibleTo($user)->where('employment_status', 'active'),
            'departments' => Department::query()->where('is_active', true),
            'positions' => Position::query()->where('is_active', true),
            'new_this_month' => Employee::query()->visibleTo($user)
                ->whereBetween('hire_date', [now()->startOfMonth(), now()->endOfMonth()]),
            'submitted_timesheets' => Employee::constrainRelatedQuery(Timesheet::query()->where('status', 'submitted'), $user),
            'pending_leave_requests' => Employee::constrainRelatedQuery(LeaveRequest::query()->where('status', 'pending'), $user),
        ];

        $query = DB::query();

        foreach ($subCounts as $alias => $subQuery) {
            $query->selectSub($subQuery->selectRaw('count(*)'), $alias);
        }

        $row = (array) $query->first();

        return array_map(static fn ($value): int => (int) $value, $row);
    }
}

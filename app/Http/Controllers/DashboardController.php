<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $canManageWorkforce = $request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty();

        $counts = $this->summaryCounts();
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
            ->with(['user', 'department', 'position'])
            ->latest()
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
        ]);
    }

    /**
     * Collect every dashboard headline figure in one round trip. Each figure was
     * previously its own COUNT query, which is costly against a remote database
     * where latency, not query weight, dominates the page load.
     *
     * @return array<string, int>
     */
    private function summaryCounts(): array
    {
        $subCounts = [
            'employees' => Employee::query(),
            'active_employees' => Employee::query()->where('employment_status', 'active'),
            'departments' => Department::query()->where('is_active', true),
            'positions' => Position::query()->where('is_active', true),
            'new_this_month' => Employee::query()
                ->whereBetween('hire_date', [now()->startOfMonth(), now()->endOfMonth()]),
            'submitted_timesheets' => Timesheet::query()->where('status', 'submitted'),
            'pending_leave_requests' => LeaveRequest::query()->where('status', 'pending'),
        ];

        $query = DB::query();

        foreach ($subCounts as $alias => $subQuery) {
            $query->selectSub($subQuery->selectRaw('count(*)'), $alias);
        }

        $row = (array) $query->first();

        return array_map(static fn ($value): int => (int) $value, $row);
    }
}

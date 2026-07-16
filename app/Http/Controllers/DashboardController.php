<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $canManageWorkforce = $request->user()->roles()
            ->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])
            ->exists();
        $activeEmployees = Employee::query()
            ->where('employment_status', 'active')
            ->count();

        $stats = [
            'employees' => Employee::query()->count(),
            'active_employees' => $activeEmployees,
            'departments' => Department::query()->where('is_active', true)->count(),
            'positions' => Position::query()->where('is_active', true)->count(),
            'new_this_month' => Employee::query()
                ->whereBetween('hire_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
        ];

        $recentEmployees = Employee::query()
            ->with(['user', 'department', 'position'])
            ->latest()
            ->limit(6)
            ->get();

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
                'title' => Timesheet::query()->where('status', 'submitted')->count().' timesheets awaiting review',
                'message' => LeaveRequest::query()->where('status', 'pending')->count().' leave requests are also pending approval.',
                'time' => 'Action required',
            ],
        ]);

        return view('welcome', [
            'stats' => $stats,
            'recentEmployees' => $recentEmployees,
            'departments' => $departments,
            'canManageWorkforce' => $canManageWorkforce,
            'notifications' => $notifications,
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
    }
}

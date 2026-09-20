<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\User;
use App\Services\ApprovalQueueService;
use App\Services\AttendanceOverviewService;
use App\Services\Burnout\BurnoutRiskService;
use App\Services\Burnout\BurnoutWatchlistService;
use App\Services\DailyExceptionsService;
use App\Services\RecentActivityService;
use App\Services\ReferenceDataCache;
use App\Services\ScheduleCalendarService;
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
        ApprovalQueueService $approvalQueue,
        DailyExceptionsService $dailyExceptions,
        ScheduleCalendarService $scheduleCalendar,
        RecentActivityService $recentActivity,
        ReferenceDataCache $reference,
        BurnoutRiskService $burnoutRisk,
        BurnoutWatchlistService $burnoutWatchlist,
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
            // Carried so the headcount card can point its arrow at a real
            // comparison. A card that always points up is not reporting a trend.
            'new_last_month' => $counts['new_last_month'],
            // Share of the workforce that is actually available for duty. The card
            // used to fill this slot with the fixed words "Ready for duty", which
            // said nothing the title had not already said.
            'active_share' => $counts['employees'] > 0
                ? (int) round($activeEmployees / $counts['employees'] * 100)
                : 0,
        ];

        // The employee total is already known from the summary query above, so it
        // is handed to the paginator rather than paying for a second COUNT.
        $recentEmployees = Employee::query()
            ->visibleTo($request->user())
            // Department and position are filled from the reference cache below
            // rather than eager loaded: five rows point at two departments and
            // two positions, and fetching those four names was costing two round
            // trips of their own.
            ->with('user')
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

        $reference->attach($recentEmployees, 'department', 'department_id', Department::class);
        $reference->attach($recentEmployees, 'position', 'position_id', Position::class);

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

        return view('welcome', [
            'stats' => $stats,
            'recentEmployees' => $recentEmployees,
            'departments' => $departments,
            'canManageWorkforce' => $canManageWorkforce,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'attendanceOverview' => $attendanceOverview->forRange(
                (int) $request->integer('attendance_days', AttendanceOverviewService::RANGES[0]),
            ),
            'shiftOverview' => $shiftOverview->forToday(),
            'scheduleCalendar' => $scheduleCalendar->forCurrentWeek(),
            // The audit trail names who did what to whose record, so it is shown
            // only to the role allowed to open Audit Logs, where its link leads.
            'activity' => $request->user()->hasRole('system-administrator') ? $recentActivity->latest() : null,
            // The two action panels lead the page, and both are closed to a viewer
            // who cannot approve or investigate anything. Neither is built for
            // them: an empty approvals queue shown to somebody with no authority
            // to clear it is noise, and the exceptions panel names individuals.
            'approvals' => $canManageWorkforce ? $approvalQueue->forUser($request->user()) : null,
            'exceptions' => $canManageWorkforce ? $dailyExceptions->forToday($request->user()) : null,
            // Managers and administrators get the same "My workload & rest"
            // card staff do: they can burn out too, and this dashboard is the
            // only one they land on.
            'myBurnout' => $employee !== null ? $burnoutRisk->card($employee) : null,
            // Names people, so only for HR and department heads -- not a
            // system administrator, who sees only their own card above.
            'burnoutWatchlist' => $request->user()->can('burnout.view-workforce')
                ? $burnoutWatchlist->forUser($request->user())
                : null,
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
            // The baseline the headcount card's arrow is measured against. Last
            // month is the only comparison available that does not need a history
            // table: hire dates are recorded, headcount at a past date is not.
            'new_last_month' => Employee::query()->visibleTo($user)
                ->whereBetween('hire_date', [
                    now()->subMonthNoOverflow()->startOfMonth(),
                    now()->subMonthNoOverflow()->endOfMonth(),
                ]),
        ];

        $query = DB::query();

        foreach ($subCounts as $alias => $subQuery) {
            $query->selectSub($subQuery->selectRaw('count(*)'), $alias);
        }

        $row = (array) $query->first();

        return array_map(static fn ($value): int => (int) $value, $row);
    }
}

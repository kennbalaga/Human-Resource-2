<?php

namespace App\Http\Controllers;

use App\Services\StaffDashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An employee's own patterns, on a page of their own.
 *
 * These four readings — burnout risk, the personal analytics, the month's
 * attendance tally and its overtime — used to exist only as cards near the
 * bottom of the staff dashboard. That was fine when the dashboard was the
 * whole app on a desktop; on a phone it meant the first screen carried both
 * "clock in now" and "how have the last eight weeks treated you", which are
 * not the same kind of question and do not belong in the same fold.
 *
 * The analytics module proper is closed to everyone outside the supervisory
 * roles, and stays closed. This is not that: it is the employee's own record,
 * the same figures the dashboard already showed them, and it is reached only
 * through their own account.
 */
class MyInsightsController extends Controller
{
    public function __invoke(Request $request, StaffDashboardService $staffDashboard): View
    {
        $employee = $request->user()->employee;
        abort_if($employee === null, 403, 'Your user account is not linked to an employee profile.');

        // The same builder the dashboard uses, so a figure can never disagree
        // between the two pages.
        $dashboard = $staffDashboard->forEmployee($employee);

        return view('insights.mine', [
            'burnout' => $dashboard['burnout'],
            'analytics' => $dashboard['analytics'],
            'summary' => $dashboard['attendance_summary'],
            'overtime' => $dashboard['overtime'],
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Timesheet;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The phone's fifth tab: everything read once a fortnight or once a year.
 *
 * It exists because four tabs cover what an employee does on a shift — the
 * punch, the roster, and asking for something — and the rest still has to be
 * reachable. Putting the rest behind a list rather than a fifth, sixth and
 * seventh tab is what keeps the bar at five, which is the ceiling a thumb can
 * hit without looking.
 *
 * Desktop never sees this page: the rail already lists all of it. The route is
 * open to anyone signed in rather than gated, because every destination on it
 * is one the reader could already reach — this only gathers them.
 */
class MoreController extends Controller
{
    public function __invoke(Request $request): View
    {
        $employee = $request->user()->employee;

        /*
         * The one count worth carrying here. A draft timesheet is the only item
         * in this list that goes stale if it is not acted on — the profile and
         * work patterns keep. Counted for this employee alone; the rail's badge is a
         * supervisor's review queue and means something different.
         */
        $timesheetsToSubmit = $employee === null ? 0 : Timesheet::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'draft')
            ->count();

        return view('more.index', [
            'employee' => $employee,
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'timesheetsToSubmit' => $timesheetsToSubmit,
        ]);
    }
}

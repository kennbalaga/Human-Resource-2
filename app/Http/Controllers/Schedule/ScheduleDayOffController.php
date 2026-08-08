<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Models\ScheduleDayOff;
use App\Services\Scheduling\ScheduleLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleDayOffController extends Controller
{
    public function destroy(Request $request, ScheduleDayOff $scheduleDayOff, ScheduleLockService $locks): RedirectResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);
        $scheduleDayOff->loadMissing('employee.department');
        if ($scheduleDayOff->employee->department !== null) {
            $locks->assertUnlocked($scheduleDayOff->employee->department, $scheduleDayOff->work_date);
        }
        $scheduleDayOff->delete();

        return back()->with('success', 'Day off removed.');
    }
}

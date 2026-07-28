<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Models\ScheduleDayOff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScheduleDayOffController extends Controller
{
    public function destroy(Request $request, ScheduleDayOff $scheduleDayOff): RedirectResponse
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists(), 403);
        $scheduleDayOff->delete();

        return back()->with('success', 'Day off removed.');
    }
}

<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleAssignmentRequest;
use App\Models\ScheduleAssignment;
use App\Services\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ScheduleAssignmentController extends Controller
{
    public function store(ScheduleAssignmentRequest $request, ScheduleService $scheduleService): RedirectResponse
    {
        $assignment = $scheduleService->createAssignment($request->validated(), $request->user());

        return back()->with('success', "{$assignment->employee->full_name} was assigned successfully.");
    }

    public function update(
        ScheduleAssignmentRequest $request,
        ScheduleAssignment $scheduleAssignment,
        ScheduleService $scheduleService,
    ): RedirectResponse {
        $scheduleService->updateAssignment($scheduleAssignment, $request->validated());

        return back()->with('success', 'Schedule assignment updated successfully.');
    }

    public function destroy(Request $request, ScheduleAssignment $scheduleAssignment): RedirectResponse
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists(), 403);

        $scheduleAssignment->delete();

        return back()->with('success', 'Schedule assignment removed.');
    }
}

<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RecurringScheduleRequest;
use App\Models\RecurringSchedule;
use App\Services\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringScheduleController extends Controller
{
    public function store(RecurringScheduleRequest $request, ScheduleService $scheduleService): RedirectResponse
    {
        $series = $scheduleService->createRecurringSchedule($request->validated(), $request->user());

        return back()->with('success', "Recurring schedule created with {$series->assignments_count} assignments.");
    }

    public function destroy(Request $request, RecurringSchedule $recurringSchedule): RedirectResponse
    {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists(), 403);

        DB::transaction(function () use ($recurringSchedule): void {
            $recurringSchedule->assignments()
                ->whereDate('work_date', '>=', now(config('schedule.timezone'))->toDateString())
                ->delete();
            $recurringSchedule->update(['status' => 'cancelled']);
        });

        return back()->with('success', 'Recurring series cancelled. Future assignments were removed.');
    }
}

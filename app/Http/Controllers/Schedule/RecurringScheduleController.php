<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RecurringScheduleRequest;
use App\Models\RecurringSchedule;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecurringScheduleController extends Controller
{
    public function store(
        RecurringScheduleRequest $request,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $series = $scheduleService->createRecurringSchedule($request->validated(), $request->user());
        $this->notifyEmployee($series, 'created', $notifications);

        return back()->with('success', "Recurring schedule created with {$series->assignments_count} assignments.");
    }

    public function destroy(
        Request $request,
        RecurringSchedule $recurringSchedule,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless($request->user()->roles()->whereIn('slug', ['system-administrator', 'hr-manager', 'department-head'])->exists(), 403);

        $recurringSchedule->loadMissing(['employee.user.preference', 'shift']);
        DB::transaction(function () use ($recurringSchedule): void {
            $recurringSchedule->assignments()
                ->whereDate('work_date', '>=', now(config('schedule.timezone'))->toDateString())
                ->delete();
            $recurringSchedule->update(['status' => 'cancelled']);
        });
        $this->notifyEmployee($recurringSchedule, 'cancelled', $notifications);

        return back()->with('success', 'Recurring series cancelled. Future assignments were removed.');
    }

    private function notifyEmployee(
        RecurringSchedule $series,
        string $action,
        PreferenceNotificationService $notifications,
    ): void {
        $series->loadMissing(['employee.user.preference', 'shift']);
        $user = $series->employee->user;

        if ($user === null) {
            return;
        }

        $notifications->send($user, 'schedule_updates', new PreferenceMailNotification(
            $action === 'created' ? 'Recurring work schedule assigned' : 'Recurring work schedule cancelled',
            [
                'Your recurring '.$series->shift->name.' schedule was '.$action.'.',
                'Schedule period: '.$series->start_date->format('F j, Y').'–'.$series->end_date->format('F j, Y').'.',
                'Time: '.$series->shift->formatted_time.'.',
            ],
            'View My Schedule',
            route('schedules.index', ['date' => $series->start_date->toDateString()]),
        ));
    }
}

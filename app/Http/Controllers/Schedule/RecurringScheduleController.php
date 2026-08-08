<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RecurringScheduleRequest;
use App\Models\RecurringSchedule;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use App\Services\Scheduling\ScheduleLockService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
        ScheduleLockService $locks,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);

        $recurringSchedule->loadMissing(['employee.department', 'employee.user.preference', 'shift']);
        DB::transaction(function () use ($recurringSchedule, $locks): void {
            $futureAssignments = $recurringSchedule->assignments()
                ->whereDate('work_date', '>=', now(config('schedule.timezone'))->toDateString())
                ->get();

            if ($recurringSchedule->employee->department !== null) {
                foreach ($futureAssignments->pluck('work_date')->unique() as $workDate) {
                    $locks->assertUnlocked($recurringSchedule->employee->department, Carbon::parse($workDate, config('schedule.timezone')));
                }
            }

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

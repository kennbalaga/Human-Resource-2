<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\ScheduleAssignmentRequest;
use App\Models\ScheduleAssignment;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ScheduleLockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ScheduleAssignmentController extends Controller
{
    public function store(
        ScheduleAssignmentRequest $request,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $assignment = $scheduleService->createAssignment($request->validated(), $request->user());
        $this->notifyEmployee($assignment, 'assigned', $notifications);

        return back()->with('success', "{$assignment->employee->full_name} was assigned successfully.");
    }

    public function update(
        ScheduleAssignmentRequest $request,
        ScheduleAssignment $scheduleAssignment,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $assignment = $scheduleService->updateAssignment($scheduleAssignment, $request->validated(), $request->user());
        $this->notifyEmployee($assignment, 'updated', $notifications);

        return back()->with('success', 'Schedule assignment updated successfully.');
    }

    public function destroy(
        Request $request,
        ScheduleAssignment $scheduleAssignment,
        PreferenceNotificationService $notifications,
        ScheduleLockService $locks,
        ScheduleService $scheduleService,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);

        $scheduleAssignment->loadMissing(['employee.department', 'employee.user.preference', 'shift']);
        $scheduleService->assertDateEditable($scheduleAssignment->work_date, 'schedule');
        if ($scheduleAssignment->employee->department !== null) {
            $locks->assertUnlocked($scheduleAssignment->employee->department, $scheduleAssignment->work_date);
        }
        RosterWriteContext::allow($request->user(), fn () => $scheduleAssignment->delete());
        $this->notifyEmployee($scheduleAssignment, 'removed', $notifications);

        return back()->with('success', 'Schedule assignment removed.');
    }

    private function notifyEmployee(
        ScheduleAssignment $assignment,
        string $action,
        PreferenceNotificationService $notifications,
    ): void {
        $assignment->loadMissing(['employee.user.preference', 'shift']);
        $user = $assignment->employee->user;

        if ($user === null) {
            return;
        }

        $subject = match ($action) {
            'assigned' => 'New work schedule assigned',
            'updated' => 'Work schedule updated',
            default => 'Work schedule removed',
        };

        $notifications->send($user, 'schedule_updates', new PreferenceMailNotification(
            $subject,
            [
                'Your '.$assignment->shift->name.' schedule was '.$action.'.',
                'Date: '.$assignment->work_date->format('F j, Y').'.',
                'Time: '.$assignment->shift->formatted_time.'.',
            ],
            'View My Schedule',
            route('schedules.index', ['date' => $assignment->work_date->toDateString()]),
        ));
    }
}

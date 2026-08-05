<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\BulkScheduleAssignmentRequest;
use App\Http\Requests\Schedule\ScheduleAssignmentRequest;
use App\Models\ScheduleAssignment;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

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
        $assignment = $scheduleService->updateAssignment($scheduleAssignment, $request->validated());
        $this->notifyEmployee($assignment, 'updated', $notifications);

        return back()->with('success', 'Schedule assignment updated successfully.');
    }



    public function destroy(
        Request $request,
        ScheduleAssignment $scheduleAssignment,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        abort_unless($request->user()->roles->pluck('slug')->intersect(['system-administrator', 'hr-manager', 'department-head'])->isNotEmpty(), 403);

        $scheduleAssignment->loadMissing(['employee.user.preference', 'shift']);
        $scheduleAssignment->delete();
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

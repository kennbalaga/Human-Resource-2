<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
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
    use ScopesWorkforceAccess;

    public function store(
        ScheduleAssignmentRequest $request,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $assignment = $scheduleService->createAssignment($request->validated(), $request->user());
        $this->notifyEmployee($assignment, 'assigned', $notifications);
        $assignment->loadMissing(['employee', 'shift']);
        $weekHours = round($scheduleService->weeklyPaidMinutes($assignment->employee, $assignment->work_date->toDateString()) / 60, 1);
        $limit = (int) config('schedule.compliance.max_hours_per_week');

        return back()
            ->with('success', "{$assignment->employee->full_name} was assigned successfully.")
            ->with('schedule_confirmation', [
                'title' => 'Shift assigned',
                'text' => "{$assignment->employee->full_name} is on the {$assignment->shift->name} for {$assignment->work_date->format('D, M j, Y')}.",
                'rows' => [
                    ['Employee', $assignment->employee->full_name.' · '.$assignment->employee->employee_number],
                    ['Shift', $assignment->shift->name.' · '.$assignment->shift->formatted_time],
                    ['Date', $assignment->work_date->format('D, M j, Y')],
                    ['Workload', rtrim(rtrim(number_format($weekHours, 1), '0'), '.')." of {$limit} paid hours this week".($weekHours > $limit ? ' (overtime)' : '')],
                    ['Chosen by', $assignment->source_recommendation_id !== null ? 'HR, from an AI recommendation' : 'HR'],
                ],
                'note' => $assignment->employee->full_name.' is notified according to their notification settings. The assignment can still be edited or removed from the calendar.',
                'again' => ['label' => 'Assign another shift', 'target' => '#scheduleAssignmentModal'],
            ]);
    }

    public function update(
        ScheduleAssignmentRequest $request,
        ScheduleAssignment $scheduleAssignment,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        // The request rules hold the new employee_id to the author's units;
        // this holds the assignment being edited to them too, so a shift
        // cannot be taken off another ward by moving it onto one's own.
        $this->requireSupervision($request, $scheduleAssignment->loadMissing('employee')->employee, 'workforce.manage.record');
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
        $this->requireSupervision($request, $scheduleAssignment->employee);
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

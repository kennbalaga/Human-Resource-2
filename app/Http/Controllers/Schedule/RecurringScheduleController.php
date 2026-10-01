<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Concerns\ScopesWorkforceAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\RecurringScheduleRequest;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ScheduleLockService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RecurringScheduleController extends Controller
{
    use ScopesWorkforceAccess;

    public function store(
        RecurringScheduleRequest $request,
        ScheduleService $scheduleService,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $series = $scheduleService->createRecurringSchedule($request->validated(), $request->user());
        $this->notifyEmployee($series, 'created', $notifications);

        return back()
            ->with('success', "Recurring schedule created with {$series->assignments_count} assignments.")
            ->with('schedule_confirmation', $this->confirmation($series, $request->validated('origin') === 'assignment'));
    }

    /**
     * Live read-back for the recurring-schedule form: which dates would be
     * created, which would be skipped and why, and the weekly checks. Nothing
     * is written.
     */
    public function preview(RecurringScheduleRequest $request, ScheduleService $scheduleService): JsonResponse
    {
        return response()->json($scheduleService->recurringPreview($request->validated()));
    }

    /**
     * What the confirmation dialog shown after the redirect reads back.
     *
     * @return array{title: string, text: string, rows: array<int, array{0: string, 1: string}>, note: string}
     */
    private function confirmation(RecurringSchedule $series, bool $fromAssignment = false): array
    {
        $series->loadMissing(['employee', 'shift']);
        $dates = $series->assignments()->orderBy('work_date')->pluck('work_date');
        $names = ScheduleWeek::isoWeekdayNames();
        $days = $series->recurrence_type === 'daily'
            ? 'every day'
            : 'on '.collect(ScheduleWeek::isoWeekdays())
                ->filter(fn (int $iso) => in_array($iso, array_map('intval', $series->weekdays ?? []), true))
                ->map(fn (int $iso) => substr($names[$iso], 0, 3))
                ->join(', ');
        $every = (int) $series->interval_weeks > 1 ? "Every {$series->interval_weeks} weeks" : 'Every week';
        $skipped = collect($series->skipped_dates ?? [])
            ->map(fn (string $date) => Carbon::parse($date)->format('D, M j'));

        $rows = [
            ['Employee', $series->employee->full_name],
            ['Shift', $series->shift->name.' · '.$series->shift->formatted_time],
            ['Pattern', $series->recurrence_type === 'daily' ? 'Every day' : "{$every} {$days}"],
            ['Dates', $dates->isEmpty() ? '—' : Carbon::parse($dates->first())->format('M j').' – '.Carbon::parse($dates->last())->format('M j, Y')],
        ];
        if ($skipped->isNotEmpty()) {
            $rows[] = ['Skipped', $skipped->take(6)->join(' · ').($skipped->count() > 6 ? ' and '.($skipped->count() - 6).' more' : '')];
        }

        if ($fromAssignment) {
            $rows[] = ['Chosen by', 'HR'];
        }

        return [
            'title' => $fromAssignment ? 'Weekly shift assigned' : 'Recurring schedule created',
            'text' => $series->assignments_count.' '.($series->assignments_count === 1 ? 'shift was' : 'shifts were').' added to '.$series->employee->full_name.'’s schedule.',
            'rows' => $rows,
            'note' => $series->employee->full_name.' is notified according to their notification settings. Each shift can still be edited or removed on its own.',
            'again' => $fromAssignment
                ? ['label' => 'Assign another shift', 'target' => '#scheduleAssignmentModal']
                : ['label' => 'Create another series', 'target' => '#recurringScheduleModal'],
        ];
    }

    public function destroy(
        Request $request,
        RecurringSchedule $recurringSchedule,
        PreferenceNotificationService $notifications,
        ScheduleLockService $locks,
        ScheduleService $scheduleService,
    ): RedirectResponse {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);

        $recurringSchedule->loadMissing(['employee.department', 'employee.user.preference', 'shift']);
        $this->requireSupervision($request, $recurringSchedule->employee);
        DB::transaction(function () use ($recurringSchedule, $locks, $request, $scheduleService): void {
            // From the first editable day: today's shift is already under way
            // and read-only, exactly as it is for a single assignment.
            $futureAssignments = $recurringSchedule->assignments()
                ->whereDate('work_date', '>=', $scheduleService->firstEditableDate()->toDateString())
                ->get();

            if ($recurringSchedule->employee->department !== null) {
                foreach ($futureAssignments->pluck('work_date')->unique() as $workDate) {
                    $locks->assertUnlocked($recurringSchedule->employee->department, Carbon::parse($workDate, config('schedule.timezone')));
                }
            }

            // One model at a time rather than a query delete, so each removal
            // fires the model events that write the assignment audit trail.
            RosterWriteContext::allow($request->user(), fn () => $futureAssignments->each(
                fn (ScheduleAssignment $assignment) => $assignment->delete(),
            ));
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

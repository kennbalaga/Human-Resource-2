<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\BulkScheduleAssignmentRequest;
use App\Http\Requests\Schedule\RosterDraftRequest;
use App\Http\Requests\Schedule\RotationScheduleRequest;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\RosterDraft;
use App\Models\ScheduleAssignment;
use App\Notifications\PreferenceMailNotification;
use App\Services\PreferenceNotificationService;
use App\Services\ScheduleService;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use App\Services\Scheduling\RosterDraftService;
use App\Services\Scheduling\RotationScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The reviewed roster: checked while it is being edited, and published as it
 * stands rather than regenerated at the moment of saving.
 */
class RosterDraftController extends Controller
{
    public function evaluate(RosterDraftRequest $request, RosterDraftService $drafts): JsonResponse
    {
        $data = $request->validated();
        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);

        return response()->json([
            'data' => $drafts->evaluate(
                $department,
                collect($data['entries']),
                $data['start_date'],
                $data['end_date'],
                $data,
            ),
        ]);
    }

    /**
     * Put the selected staff on one shift across the chosen dates, applying the
     * rest-day, hours and night-shift rules. The quick way to start a roster
     * without placing every person by hand, and still only a draft.
     */
    public function fill(
        BulkScheduleAssignmentRequest $request,
        ScheduleService $schedules,
        RosterDraftService $drafts,
    ): JsonResponse {
        $data = $request->validated();
        $plan = $schedules->bulkAssignmentPlan($data);
        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);

        $entries = $plan['ready']
            ->map(fn (array $item) => [
                'employee_id' => $item['employee']->id,
                'shift_id' => (int) $data['shift_id'],
                'work_date' => $item['date']->toDateString(),
            ])
            ->values();

        return response()->json([
            'data' => [
                'entries' => $entries->all(),
                'evaluation' => $drafts->evaluate($department, $entries, $data['start_date'], $data['end_date'], $data),
                'ready_count' => $plan['ready']->count(),
                'skipped_count' => $plan['skipped']->count(),
                'requested_count' => $plan['ready']->count() + $plan['skipped']->count(),
                'skipped' => $plan['skipped']->take(10)->values(),
                'staffing_gaps' => $plan['staffingGaps']->take(10)->values(),
                'validation_summary' => $plan['skipped']->countBy('reason'),
            ],
        ]);
    }

    /**
     * Hand back what the assistant would roster, as an editable draft. Nothing is
     * written, so the nursing office can change it before anything is published.
     */
    public function suggest(
        RotationScheduleRequest $request,
        RotationScheduleService $rotations,
        AiSchedulingFeatureSettings $featureSettings,
        RosterDraftService $drafts,
    ): JsonResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);

        $data = $request->validated();
        $plan = $rotations->plan($data);
        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);

        $entries = $plan['ready_assignments']
            ->map(fn (array $item) => [
                'employee_id' => $item['employee']->id,
                'shift_id' => $item['shift']->id,
                'work_date' => $item['date']->toDateString(),
            ])
            ->merge($plan['ready_day_offs']->map(fn (array $item) => [
                'employee_id' => $item['employee']->id,
                'shift_id' => null,
                'work_date' => $item['date']->toDateString(),
            ]))
            ->values();

        return response()->json([
            'data' => [
                'entries' => $entries->all(),
                'evaluation' => $drafts->evaluate($department, $entries, $data['start_date'], $data['end_date'], $data),
                // The per-employee week view the assistant produced, kept so the
                // rotation itself stays inspectable and not only its outcome.
                'rows' => $plan['rows'],
                'assignment_count' => $plan['assignment_count'],
                'day_off_count' => $plan['day_off_count'],
                'skipped_count' => $plan['skipped_count'],
                'skipped' => $plan['skipped']->take(10)->values(),
                'staffing_gaps' => $plan['staffing_gaps']->take(10)->values(),
                'validation_summary' => $plan['skipped']->countBy('reason'),
                'notice' => $plan['notice'],
                'coverage_standard' => $plan['coverage_standard'],
            ],
        ]);
    }

    /**
     * Persist the roster board exactly as it stands, so a second reviewer can
     * resume it later instead of the draft only living in one browser tab.
     */
    public function saveDraft(RosterDraftRequest $request, RosterDraftService $drafts): JsonResponse
    {
        $data = $request->validated();
        $department = Department::query()->findOrFail($data['department_id']);

        // Everything besides the core draft fields is the Step 2 rule set and
        // shift selection this board was built under — saved as-is so
        // resuming re-evaluates under what was actually on screen, not
        // whatever the form defaults to when the modal is reopened.
        $rules = collect($data)
            ->except(['department_id', 'start_date', 'end_date', 'notes', 'draft_uuid', 'entries'])
            ->all();

        $draft = $drafts->saveDraft(
            $department,
            collect($data['entries']),
            $data['start_date'],
            $data['end_date'],
            $request->user(),
            $data['notes'] ?? null,
            $data['draft_uuid'] ?? null,
            $rules,
        );

        return response()->json(['data' => $draft]);
    }

    /**
     * Open drafts for a department, offered as a "resume where you left off"
     * option when the bulk-schedule modal is reopened.
     */
    public function drafts(Request $request, RosterDraftService $drafts): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);
        $validated = $request->validate(['department_id' => ['required', 'integer', 'exists:departments,id']]);
        $department = Department::query()->findOrFail($validated['department_id']);

        return response()->json(['data' => $drafts->openDraftsFor($department)]);
    }

    public function discardDraft(Request $request, RosterDraft $rosterDraft, RosterDraftService $drafts): JsonResponse
    {
        abort_unless(Gate::forUser($request->user())->allows('workforce.view'), 403);
        $drafts->discardDraft($rosterDraft);

        return response()->json(['data' => ['status' => 'discarded']]);
    }

    public function publish(
        RosterDraftRequest $request,
        RosterDraftService $drafts,
        PreferenceNotificationService $notifications,
    ): RedirectResponse {
        $data = $request->validated();
        $department = Department::query()->with('shiftRequirements')->findOrFail($data['department_id']);
        $draft = isset($data['draft_uuid']) ? RosterDraft::query()->where('uuid', $data['draft_uuid'])->first() : null;

        $result = $drafts->publish(
            $department,
            collect($data['entries']),
            $request->user(),
            $data['notes'] ?? null,
            $draft,
            $data,
        );

        // The generic request-level audit middleware already records that
        // this route was hit by whom and when, but only the *names* of
        // submitted fields, not their values — deliberately, since it runs
        // for every form in the app and can't know which fields are safe to
        // log in full. Publishing a roster is exactly the "rule overridden
        // and justification" case RA 10173 accountability and the Tier B
        // constraints need the actual text for, so it gets its own richer
        // entry here rather than widening what the generic middleware logs
        // application-wide.
        AuditLog::query()->create([
            'user_id' => $request->user()->id,
            'action' => 'schedule_roster.published',
            'route_name' => $request->route()?->getName(),
            'method' => $request->method(),
            'path' => $request->path(),
            'subject_type' => $draft ? RosterDraft::class : Department::class,
            'subject_id' => $draft?->id ?? $department->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'response_status' => 200,
            'metadata' => [
                'department_id' => $department->id,
                'date_range' => [$data['start_date'], $data['end_date']],
                'assignments_created' => $result['assignments']->count(),
                'day_offs_created' => $result['day_offs']->count(),
                'skipped' => $result['skipped']->count(),
                'rules' => collect($data)->only([
                    'position_ids', 'shift_id', 'shift_ids', 'days_off_per_week', 'max_hours_per_week', 'night_shift_limit',
                    'max_consecutive_nights', 'minimum_rest_hours', 'overtime_allowed', 'maximum_staff_per_shift',
                    'minimum_senior_per_shift', 'senior_rank_threshold', 'holiday_dates',
                ])->all(),
                'overtime_justification' => $data['overtime_justification'] ?? null,
                'night_streak_justification' => $data['night_streak_justification'] ?? null,
                'burnout_justification' => $data['burnout_justification'] ?? null,
            ],
        ]);

        foreach ($result['assignments'] as $assignment) {
            $this->notifyEmployee($assignment, $notifications);
        }

        $created = $result['assignments']->count();
        $rest = $result['day_offs']->count();
        $skipped = $result['skipped']->count();

        $message = "{$created} ".str('assignment')->plural($created)." and {$rest} ".str('rest day')->plural($rest).' published.';
        if ($skipped > 0) {
            $message .= " {$skipped} could not be scheduled and were left out.";
        }

        return back()->with('success', $message);
    }

    private function notifyEmployee(ScheduleAssignment $assignment, PreferenceNotificationService $notifications): void
    {
        $assignment->loadMissing(['employee.user.preference', 'shift']);
        $user = $assignment->employee?->user;

        if ($user === null) {
            return;
        }

        $notifications->send($user, 'schedule_updates', new PreferenceMailNotification(
            'New work schedule published',
            [
                'Your '.$assignment->shift?->name.' schedule was published.',
                'Date: '.$assignment->work_date->format('F j, Y').'.',
                'Time: '.$assignment->shift?->formatted_time.'.',
            ],
            'View schedule',
            route('schedules.index'),
        ));
    }
}

<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\BulkScheduleAssignmentRequest;
use App\Http\Requests\Schedule\RosterDraftRequest;
use App\Http\Requests\Schedule\RotationScheduleRequest;
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
                'evaluation' => $drafts->evaluate($department, $entries, $data['start_date'], $data['end_date']),
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
                'evaluation' => $drafts->evaluate($department, $entries, $data['start_date'], $data['end_date']),
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

        $draft = $drafts->saveDraft(
            $department,
            collect($data['entries']),
            $data['start_date'],
            $data['end_date'],
            $request->user(),
            $data['notes'] ?? null,
            $data['draft_uuid'] ?? null,
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
        );

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

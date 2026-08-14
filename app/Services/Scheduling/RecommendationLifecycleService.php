<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\RosterDraft;
use App\Models\ScheduleRecommendation;
use App\Models\ScheduleRecommendationDecision;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecommendationLifecycleService
{
    private const STALE_MESSAGE = 'This AI recommendation is no longer valid because the scheduling information has changed. Please generate a new recommendation.';

    public function __construct(
        private readonly ScheduleRecommendationService $recommendations,
        private readonly RecommendationFreshnessService $freshness,
        private readonly EmployeeEligibilityService $eligibility,
        private readonly RecommendationExplanationService $explanations,
        private readonly RosterDraftService $rosterDrafts,
        private readonly ScheduleLockService $locks,
    ) {}

    /** @return array<string, mixed> */
    public function create(User $requester, Department $department, Position $position, Shift $shift, string $workDate): array
    {
        $result = $this->recommendations->generate($department, $position, $shift, $workDate);
        $recommended = $result['recommended'];
        $explanation = $this->explanations->explain($result);
        $record = ScheduleRecommendation::query()->create([
            'uuid' => (string) Str::uuid(),
            'requested_by' => $requester->id,
            'target_shift_id' => $shift->id,
            'target_work_date' => $workDate,
            'target_department_id' => $department->id,
            'target_position_id' => $position->id,
            'recommended_employee_id' => $recommended['employee_id'] ?? null,
            'alternative_candidates' => collect($result['alternatives'])->map(fn (array $candidate) => [
                'employee_id' => $candidate['employee_id'],
                'score' => $candidate['score'],
            ])->all(),
            'eligibility_results' => $this->auditSafeResults($result),
            'warnings' => $result['warnings'],
            'workload_risk' => $recommended['workload_risk']['level'] ?? null,
            'explanation' => $explanation['text'],
            'explanation_source' => $explanation['source'],
            'fingerprint' => $this->freshness->fingerprint($department, $position, $shift, $workDate),
            'status' => 'for_hr_review',
            'actor_type' => 'AI_SYSTEM',
            'actor_name' => 'AI Scheduling Assistant',
            'action_type' => 'GENERATED_RECOMMENDATION',
            'generated_at' => now(),
            'expires_at' => now()->addMinutes(config('ai_workforce_scheduling.recommendation_ttl_minutes')),
        ]);

        return $result + [
            'recommendation_id' => $record->uuid,
            'status' => $record->status,
            'expires_at' => $record->expires_at->toIso8601String(),
            'explanation' => $explanation['text'],
            'explanation_source' => $explanation['source'],
        ];
    }

    /**
     * Revalidate a recommendation and materialize it into a draft roster row.
     *
     * This never touches schedule_assignments — same as the rest of the roster
     * board workflow, the recommendation only ever lands on a RosterDraft.
     * Publishing that draft (RosterDraftService::publish) is what eventually
     * saves the schedule.
     *
     * @param  array<string, mixed>  $target
     * @return array<string, mixed>
     */
    public function apply(User $user, ScheduleRecommendation $recommendation, int $employeeId, array $target): array
    {
        // Fast path: a recommendation can only be materialized once. If it
        // already produced a draft entry, hand that back instead of
        // re-validating and re-writing — this also covers a client retrying
        // an apply whose response it never received.
        $existing = $this->existingDraftEntry($recommendation);
        if ($existing !== null) {
            return $this->draftResponse($recommendation, $existing['draft'], $existing['entry']);
        }

        $this->ensureReviewable($recommendation);
        $this->ensureTargetMatches($recommendation, $target);
        $department = Department::query()->findOrFail($recommendation->target_department_id);
        $position = Position::query()->findOrFail($recommendation->target_position_id);
        $shift = Shift::query()->findOrFail($recommendation->target_shift_id);
        $workDate = $recommendation->target_work_date->toDateString();
        $fingerprint = $this->freshness->fingerprint($department, $position, $shift, $workDate);

        if (! hash_equals($recommendation->fingerprint, $fingerprint)) {
            $this->expire($recommendation);
        }

        $allowedIds = collect($recommendation->alternative_candidates)->pluck('employee_id')
            ->push($recommendation->recommended_employee_id)
            ->filter()
            ->map(fn ($id) => (int) $id);
        if (! $allowedIds->contains($employeeId)) {
            throw ValidationException::withMessages(['employee_id' => 'Select the recommended employee or one of the recorded alternatives.']);
        }

        $employee = Employee::query()->findOrFail($employeeId);
        $evaluation = $this->eligibility->evaluateCandidate($employee, $department, $position, $shift, $workDate);
        if (! $evaluation['eligible']) {
            $this->expire($recommendation);
        }

        return DB::transaction(function () use ($user, $recommendation, $employeeId, $department, $shift, $workDate): array {
            $locked = ScheduleRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);

            // Re-check under the row lock: a concurrent request may have
            // materialized this recommendation while we were validating.
            $existing = $this->existingDraftEntry($locked);
            if ($existing !== null) {
                return $this->draftResponse($locked, $existing['draft'], $existing['entry']);
            }

            $this->ensureReviewable($locked);

            // Guard rails before writing anything: a locked period or a
            // conflict against the draft as it actually stands (double
            // booking, leave, day off, understaffing rules resolved via
            // StaffingRequirementService inside RosterDraftService::evaluate)
            // both abort the whole transaction rather than partially apply.
            $this->locks->assertUnlocked($department, Carbon::parse($workDate, config('schedule.timezone')));

            $action = $employeeId === $locked->recommended_employee_id ? 'applied' : 'modified';
            $entry = [
                'employee_id' => $employeeId,
                'shift_id' => $shift->id,
                'work_date' => $workDate,
                'schedule_recommendation_id' => $locked->id,
                'source' => 'ai',
                'applied_by' => $user->id,
                'was_modified' => $action === 'modified',
            ];

            $target = $this->openDraftCovering($department, $workDate);
            $entries = $target !== null ? collect($target->entries)->push($entry) : collect([$entry]);
            $startDate = $target?->start_date->toDateString() ?? $workDate;
            $endDate = $target?->end_date->toDateString() ?? $workDate;
            $rules = $target?->rules ?? [];

            $draftEvaluation = $this->rosterDrafts->evaluate($department, $entries, $startDate, $endDate, $rules);
            $this->assertEntryPlaceable($draftEvaluation, $entry);

            $this->decision($locked, $user, $action, $employeeId);
            $locked->update(['status' => $action]);

            $draft = $this->rosterDrafts->saveDraft(
                $department,
                $entries,
                $startDate,
                $endDate,
                $user,
                $target?->notes,
                $target?->uuid,
                $rules,
            );

            return [
                'recommendation_id' => $locked->uuid,
                'status' => $action,
                'employee_id' => $employeeId,
                'draft_id' => $draft->uuid,
                'message' => 'Recommendation applied to the draft roster. Review and publish the draft to save the schedule.',
            ];
        });
    }

    public function recordDecision(User $user, ScheduleRecommendation $recommendation, string $action, ?string $reason): ScheduleRecommendationDecision
    {
        $this->ensureReviewable($recommendation);

        return DB::transaction(function () use ($user, $recommendation, $action, $reason): ScheduleRecommendationDecision {
            $locked = ScheduleRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            $this->ensureReviewable($locked);
            $decision = $this->decision($locked, $user, $action, null, $reason);
            $locked->update(['status' => $action]);

            return $decision;
        });
    }

    private function ensureReviewable(ScheduleRecommendation $recommendation): void
    {
        if ($recommendation->expires_at->isPast()) {
            $this->expire($recommendation);
        }

        if (! in_array($recommendation->status, ['generated', 'for_hr_review'], true)) {
            throw ValidationException::withMessages(['recommendation' => 'This AI recommendation has already been resolved.']);
        }
    }

    /** @param array<string, mixed> $target */
    private function ensureTargetMatches(ScheduleRecommendation $recommendation, array $target): void
    {
        $matches = (int) $target['department_id'] === $recommendation->target_department_id
            && (int) $target['position_id'] === $recommendation->target_position_id
            && (int) $target['shift_id'] === $recommendation->target_shift_id
            && $target['work_date'] === $recommendation->target_work_date->toDateString();

        if (! $matches) {
            $this->expire($recommendation);
        }
    }

    private function expire(ScheduleRecommendation $recommendation): never
    {
        $recommendation->update(['status' => 'expired']);
        throw ValidationException::withMessages(['recommendation' => self::STALE_MESSAGE]);
    }

    /**
     * The open draft, if any, whose period already covers this date — entries
     * are appended to it rather than scattering one recommendation's rows
     * across several drafts for the same department and day.
     */
    private function openDraftCovering(Department $department, string $workDate): ?RosterDraft
    {
        return $this->rosterDrafts->openDraftsFor($department)
            ->first(fn (RosterDraft $draft) => $workDate >= $draft->start_date->toDateString()
                && $workDate <= $draft->end_date->toDateString());
    }

    /**
     * @return array{draft: RosterDraft, entry: array<string, mixed>}|null
     */
    private function existingDraftEntry(ScheduleRecommendation $recommendation): ?array
    {
        $draft = RosterDraft::query()
            ->where('department_id', $recommendation->target_department_id)
            ->where('status', '!=', 'discarded')
            ->get()
            ->first(fn (RosterDraft $draft) => collect($draft->entries)->contains(
                fn (array $entry) => (int) ($entry['schedule_recommendation_id'] ?? 0) === $recommendation->id
            ));

        if ($draft === null) {
            return null;
        }

        $entry = collect($draft->entries)->first(
            fn (array $entry) => (int) ($entry['schedule_recommendation_id'] ?? 0) === $recommendation->id
        );

        return ['draft' => $draft, 'entry' => $entry];
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function draftResponse(ScheduleRecommendation $recommendation, RosterDraft $draft, array $entry): array
    {
        return [
            'recommendation_id' => $recommendation->uuid,
            'status' => $recommendation->status,
            'employee_id' => $entry['employee_id'],
            'draft_id' => $draft->uuid,
            'message' => 'This recommendation was already applied to a draft roster.',
        ];
    }

    /**
     * Abort if placing this entry on the draft, as it actually stands,
     * conflicts with the employee's own schedule (double booking, leave, a
     * day off) or the unit's staffing rules — both resolved by
     * RosterDraftService::evaluate, the same check the roster board itself
     * runs before publish.
     *
     * @param  array<string, mixed>  $evaluation
     * @param  array<string, mixed>  $entry
     */
    private function assertEntryPlaceable(array $evaluation, array $entry): void
    {
        $issue = collect($evaluation['issues'])->first(
            fn (array $issue) => $issue['employee_id'] === $entry['employee_id'] && $issue['work_date'] === $entry['work_date']
        );

        if ($issue !== null) {
            throw ValidationException::withMessages([
                'schedule' => "This recommendation could not be placed on the draft roster: {$issue['reason']}.",
            ]);
        }
    }

    private function decision(
        ScheduleRecommendation $recommendation,
        User $user,
        string $action,
        ?int $selectedEmployeeId,
        ?string $reason = null,
    ): ScheduleRecommendationDecision {
        return ScheduleRecommendationDecision::query()->create([
            'schedule_recommendation_id' => $recommendation->id,
            'user_id' => $user->id,
            'action' => $action,
            'original_recommended_employee_id' => $recommendation->recommended_employee_id,
            'final_selected_employee_id' => $selectedEmployeeId,
            'reason' => $reason,
            'metadata' => ['recommendation_uuid' => $recommendation->uuid],
            'decided_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function auditSafeResults(array $result): array
    {
        return [
            'eligible' => collect($result['eligible'])->map(fn (array $candidate) => collect($candidate)->only([
                'employee_id', 'eligible', 'score', 'score_breakdown', 'metrics', 'recommendation_reasons', 'workload_risk', 'fairness',
            ])->all())->all(),
            'ineligible' => collect($result['ineligible'])->map(fn (array $candidate) => [
                'employee_id' => $candidate['employee_id'],
                'reason_codes' => collect($candidate['reasons'])->pluck('code')->all(),
            ])->all(),
        ];
    }
}

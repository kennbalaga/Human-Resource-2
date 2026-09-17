<?php

namespace App\Services\Scheduling;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ScheduleRecommendation;
use App\Models\ScheduleRecommendationDecision;
use App\Models\Shift;
use App\Models\User;
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

    /** @param array<string, mixed> $target @return array<string, mixed> */
    public function apply(User $user, ScheduleRecommendation $recommendation, int $employeeId, array $target): array
    {
        $this->ensureReviewable($recommendation);
        $this->ensureTargetMatches($recommendation, $target);
        $department = Department::query()->findOrFail($recommendation->target_department_id);
        $position = Position::query()->findOrFail($recommendation->target_position_id);
        $shift = Shift::query()->findOrFail($recommendation->target_shift_id);
        $fingerprint = $this->freshness->fingerprint($department, $position, $shift, $recommendation->target_work_date->toDateString());

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
        $evaluation = $this->eligibility->evaluateCandidate($employee, $department, $position, $shift, $recommendation->target_work_date->toDateString());
        if (! $evaluation['eligible']) {
            $this->expire($recommendation);
        }

        return DB::transaction(function () use ($user, $recommendation, $employeeId): array {
            $locked = ScheduleRecommendation::query()->lockForUpdate()->findOrFail($recommendation->id);
            $this->ensureReviewable($locked);
            $action = $employeeId === $locked->recommended_employee_id ? 'applied' : 'modified';
            $this->decision($locked, $user, $action, $employeeId);
            $locked->update(['status' => $action]);

            return [
                'recommendation_id' => $locked->uuid,
                'status' => $action,
                'employee_id' => $employeeId,
                'message' => 'Recommendation revalidated. The employee field may now be updated; the schedule has not been saved.',
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
                'employee_id', 'eligible', 'score', 'score_breakdown', 'metrics', 'recommendation_reasons', 'workload_risk', 'burnout_protected', 'fairness',
            ])->put(
                // Level and score only. The drivers can name sick leave, which
                // has no place in a record kept for auditing the assistant.
                'burnout_risk',
                isset($candidate['burnout_risk'])
                    ? collect($candidate['burnout_risk'])->only(['level', 'score'])->all()
                    : null,
            )->all())->all(),
            'ineligible' => collect($result['ineligible'])->map(fn (array $candidate) => [
                'employee_id' => $candidate['employee_id'],
                'reason_codes' => collect($candidate['reasons'])->pluck('code')->all(),
            ])->all(),
        ];
    }
}

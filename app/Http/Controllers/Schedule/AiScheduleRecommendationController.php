<?php

namespace App\Http\Controllers\Schedule;

use App\Http\Controllers\Controller;
use App\Http\Requests\Schedule\AiScheduleDecisionRequest;
use App\Http\Requests\Schedule\ApplyAiScheduleRecommendationRequest;
use App\Http\Requests\Schedule\GenerateAiScheduleRecommendationRequest;
use App\Models\Department;
use App\Models\Position;
use App\Models\ScheduleRecommendation;
use App\Models\Shift;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use App\Services\Scheduling\RecommendationLifecycleService;
use Illuminate\Http\JsonResponse;

class AiScheduleRecommendationController extends Controller
{
    public function store(
        GenerateAiScheduleRecommendationRequest $request,
        RecommendationLifecycleService $lifecycle,
        AiSchedulingFeatureSettings $featureSettings,
    ): JsonResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);
        $data = $request->validated();
        $result = $lifecycle->create(
            $request->user(),
            Department::query()->findOrFail($data['department_id']),
            Position::query()->findOrFail($data['position_id']),
            Shift::query()->findOrFail($data['shift_id']),
            $data['work_date'],
        );

        $recommendation = ScheduleRecommendation::query()->where('uuid', $result['recommendation_id'])->firstOrFail();

        return response()->json(['data' => $result + $this->actionUrls($recommendation)]);
    }

    public function apply(
        ApplyAiScheduleRecommendationRequest $request,
        ScheduleRecommendation $scheduleRecommendation,
        RecommendationLifecycleService $lifecycle,
        AiSchedulingFeatureSettings $featureSettings,
    ): JsonResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);
        $data = $request->validated();

        return response()->json(['data' => $lifecycle->apply(
            $request->user(),
            $scheduleRecommendation,
            (int) $data['employee_id'],
            $data,
        )]);
    }

    public function decision(
        AiScheduleDecisionRequest $request,
        ScheduleRecommendation $scheduleRecommendation,
        RecommendationLifecycleService $lifecycle,
        AiSchedulingFeatureSettings $featureSettings,
    ): JsonResponse {
        abort_unless($featureSettings->assistantEnabled(), 404);
        $data = $request->validated();
        $decision = $lifecycle->recordDecision($request->user(), $scheduleRecommendation, $data['action'], $data['reason'] ?? null);

        return response()->json(['data' => [
            'recommendation_id' => $scheduleRecommendation->uuid,
            'status' => $decision->action,
        ]]);
    }

    /** @return array<string, string> */
    private function actionUrls(ScheduleRecommendation $recommendation): array
    {
        return [
            'apply_url' => route('schedules.ai-recommendations.apply', $recommendation),
            'decision_url' => route('schedules.ai-recommendations.decision', $recommendation),
        ];
    }
}

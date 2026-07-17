<?php

namespace App\Services\Scheduling;

use App\Services\Integrations\GeminiScheduleExplanationService;

class RecommendationExplanationService
{
    public function __construct(private readonly GeminiScheduleExplanationService $gemini) {}

    /** @param array<string, mixed> $result @return array{text: string, source: string} */
    public function explain(array $result): array
    {
        $fallback = $this->fallback($result);
        $response = $this->gemini->generate($this->privacySafePayload($result));

        if (! $response->success) {
            return ['text' => $fallback, 'source' => 'laravel'];
        }

        return ['text' => (string) $response->data['explanation'], 'source' => 'gemini'];
    }

    /** @param array<string, mixed> $result */
    private function fallback(array $result): string
    {
        if ($result['recommended'] === null) {
            return 'Based on the available records, no employee passed every current eligibility and conflict check. HR may continue scheduling manually.';
        }

        return 'Based on the available records, the highest-ranked eligible employee passed the current hard constraints and had the strongest deterministic workload and fairness score. HR review is required, and this suggestion may be modified or ignored.';
    }

    /** @param array<string, mixed> $result @return array<string, mixed> */
    private function privacySafePayload(array $result): array
    {
        $candidates = collect($result['eligible'])->values()->map(function (array $candidate, int $index): array {
            return [
                'candidate' => 'CANDIDATE_'.($index + 1),
                'is_recommended' => $index === 0,
                'score' => $candidate['score'],
                'score_breakdown' => collect($candidate['score_breakdown'])->map(fn (array $factor) => [
                    'factor' => $factor['label'],
                    'points' => $factor['points'],
                    'maximum' => $factor['maximum'],
                ])->values()->all(),
                'workload_risk' => [
                    'level' => $candidate['workload_risk']['level'],
                    'score' => $candidate['workload_risk']['score'],
                ],
                'fairness' => $candidate['fairness'],
            ];
        })->all();

        return [
            'candidates' => $candidates,
            'ineligible_candidate_count' => count($result['ineligible']),
            'limitations' => [
                'Competency, certification, declared availability, official rest-day, preference, and holiday data are unavailable.',
                'The workload risk indicator is operational guidance only and is not a medical or safety diagnosis.',
            ],
        ];
    }
}

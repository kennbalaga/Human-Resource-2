<?php

namespace App\Services\Integrations;

use App\Services\Scheduling\AiSchedulingFeatureSettings;
use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiScheduleExplanationService
{
    public function __construct(
        private readonly IntegrationRecorder $recorder,
        private readonly AiSchedulingFeatureSettings $featureSettings,
    ) {}

    /** @param array<string, mixed> $safeRecommendation */
    public function generate(array $safeRecommendation): IntegrationResult
    {
        if (! $this->featureSettings->geminiExplanationsEnabled()
            || ! config('integrations.gemini.enabled')
            || blank(config('integrations.gemini.api_key'))) {
            return IntegrationResult::failure('gemini', 'Gemini schedule explanations are disabled or not configured.');
        }

        $started = microtime(true);
        $endpoint = rtrim((string) config('integrations.gemini.base_url'), '/')
            .'/models/'.rawurlencode((string) config('integrations.gemini.model')).':generateContent';

        try {
            $prompt = "Explain an already-computed workforce scheduling recommendation to an HR reviewer.\n"
                ."The Laravel application has fixed the eligibility and ranking. Do not select, rerank, identify, or diagnose anyone.\n"
                ."Use only the pseudonymous records below. Be concise, acknowledge data limitations, and state that HR review is required.\n\n"
                .json_encode($safeRecommendation, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => config('integrations.gemini.api_key')])
                ->timeout(config('integrations.timeout_seconds'))
                ->retry(2, 200, throw: false)
                ->post($endpoint, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['maxOutputTokens' => 320],
                ]);

            if (! $response->successful()) {
                return $this->finish(IntegrationResult::failure('gemini', 'Gemini explanation request failed safely.', $response->status()), $started, $endpoint, $safeRecommendation);
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                return $this->finish(IntegrationResult::failure('gemini', 'Gemini returned no usable schedule explanation.', $response->status()), $started, $endpoint, $safeRecommendation);
            }

            return $this->finish(
                IntegrationResult::success('gemini', 'Schedule explanation generated.', ['explanation' => trim($text)], $response->status()),
                $started,
                $endpoint,
                $safeRecommendation,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(IntegrationResult::failure('gemini', 'Gemini is temporarily unavailable; deterministic scheduling remains available.'), $started, $endpoint, $safeRecommendation);
        }
    }

    /** @param array<string, mixed> $safeRecommendation */
    private function finish(IntegrationResult $result, float $started, string $endpoint, array $safeRecommendation): IntegrationResult
    {
        $this->recorder->record(
            $result,
            'scheduling.explain',
            (int) ((microtime(true) - $started) * 1000),
            $endpoint,
            ['candidate_count' => count($safeRecommendation['candidates'] ?? [])],
        );

        return $result;
    }
}

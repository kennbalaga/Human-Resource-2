<?php

namespace App\Services\Integrations;

use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiAnalyticsService
{
    public function __construct(private readonly IntegrationRecorder $recorder) {}

    /** @param array<string, mixed> $analytics */
    public function generateInsights(array $analytics): IntegrationResult
    {
        $started = microtime(true);
        $endpoint = rtrim((string) config('integrations.gemini.base_url'), '/')
            .'/models/'.rawurlencode((string) config('integrations.gemini.model')).':generateContent';

        if (! config('integrations.gemini.enabled') || blank(config('integrations.gemini.api_key'))) {
            return $this->finish(IntegrationResult::failure('gemini', 'Gemini is disabled or not configured.'), $started, $endpoint);
        }

        try {
            $prompt = "You are an HR workforce analyst. Analyze only the aggregate, non-identifying metrics below.\n"
                ."Return concise sections: Key findings, Risks, Recommended actions. Do not infer protected characteristics.\n\n"
                .json_encode($analytics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => config('integrations.gemini.api_key')])
                ->timeout(config('integrations.timeout_seconds'))
                ->retry(2, 200, throw: false)
                ->post($endpoint, [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 900],
                ]);

            if (! $response->successful()) {
                return $this->finish(IntegrationResult::failure('gemini', 'Gemini request failed safely.', $response->status()), $started, $endpoint);
            }

            $text = $response->json('candidates.0.content.parts.0.text');
            if (! is_string($text) || trim($text) === '') {
                return $this->finish(IntegrationResult::failure('gemini', 'Gemini returned no usable insight.', $response->status()), $started, $endpoint);
            }

            return $this->finish(IntegrationResult::success('gemini', 'AI insights generated.', ['insight' => trim($text)], $response->status()), $started, $endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(IntegrationResult::failure('gemini', 'Gemini is temporarily unavailable; core analytics remain available.'), $started, $endpoint);
        }
    }

    private function finish(IntegrationResult $result, float $started, string $endpoint): IntegrationResult
    {
        $this->recorder->record($result, 'analytics.generate', (int) ((microtime(true) - $started) * 1000), $endpoint);

        return $result;
    }
}

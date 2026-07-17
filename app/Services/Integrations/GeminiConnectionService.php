<?php

namespace App\Services\Integrations;

use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeminiConnectionService
{
    public function __construct(private readonly IntegrationRecorder $recorder) {}

    public function test(): IntegrationResult
    {
        $started = microtime(true);
        $model = (string) config('integrations.gemini.model');
        $endpoint = rtrim((string) config('integrations.gemini.base_url'), '/')
            .'/models/'.rawurlencode($model);

        if (! config('integrations.gemini.enabled') || blank(config('integrations.gemini.api_key'))) {
            return $this->finish(
                IntegrationResult::failure('gemini', 'Enable Gemini and configure an API key before testing.'),
                $started,
                $endpoint,
                $model,
            );
        }

        try {
            $response = Http::acceptJson()
                ->withHeaders(['x-goog-api-key' => config('integrations.gemini.api_key')])
                ->timeout(config('integrations.timeout_seconds'))
                ->retry(2, 200, throw: false)
                ->get($endpoint);

            if (! $response->successful()) {
                $message = match ($response->status()) {
                    401, 403 => 'Gemini rejected the API key. Create or verify a restricted Gemini API key.',
                    404 => "Gemini model {$model} is unavailable for this API key.",
                    429 => 'Gemini quota is currently exhausted. Check the project quota or billing settings.',
                    default => 'Gemini connection test failed safely.',
                };

                return $this->finish(
                    IntegrationResult::failure('gemini', $message, $response->status()),
                    $started,
                    $endpoint,
                    $model,
                );
            }

            $supportedMethods = $response->json('supportedGenerationMethods', []);
            if (! is_array($supportedMethods) || ! in_array('generateContent', $supportedMethods, true)) {
                return $this->finish(
                    IntegrationResult::failure('gemini', "Gemini model {$model} does not support content generation.", $response->status()),
                    $started,
                    $endpoint,
                    $model,
                );
            }

            return $this->finish(
                IntegrationResult::success('gemini', "Gemini connection verified using {$model}.", ['model' => $model], $response->status()),
                $started,
                $endpoint,
                $model,
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(
                IntegrationResult::failure('gemini', 'Gemini is temporarily unreachable; core HR functions remain available.'),
                $started,
                $endpoint,
                $model,
            );
        }
    }

    private function finish(IntegrationResult $result, float $started, string $endpoint, string $model): IntegrationResult
    {
        $this->recorder->record(
            $result,
            'connection.test',
            (int) ((microtime(true) - $started) * 1000),
            $endpoint,
            ['model' => $model],
        );

        return $result;
    }
}

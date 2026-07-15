<?php

namespace App\Services\Integrations;

use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZapierWebhookService
{
    public function __construct(private readonly IntegrationRecorder $recorder) {}

    /** @param array<string, mixed> $payload */
    public function send(string $event, array $payload): IntegrationResult
    {
        $started = microtime(true);
        $endpoint = (string) config('integrations.zapier.webhook_url');

        if (! config('integrations.zapier.enabled') || blank($endpoint)) {
            return $this->finish(IntegrationResult::failure('zapier', 'Zapier is disabled or not configured.'), $event, $started, $endpoint);
        }

        try {
            $body = ['event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $payload];
            $json = json_encode($body, JSON_THROW_ON_ERROR);
            $request = Http::acceptJson()->asJson()->timeout(min(10, config('integrations.timeout_seconds')))->retry(2, 200, throw: false);
            if (filled(config('integrations.zapier.signing_secret'))) {
                $request = $request->withHeaders(['X-HRMS-Signature' => hash_hmac('sha256', $json, config('integrations.zapier.signing_secret'))]);
            }
            $response = $request->withBody($json, 'application/json')->post($endpoint);
            $result = $response->successful()
                ? IntegrationResult::success('zapier', 'Webhook delivered.', [], $response->status())
                : IntegrationResult::failure('zapier', 'Webhook delivery failed safely.', $response->status());

            return $this->finish($result, $event, $started, $endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(IntegrationResult::failure('zapier', 'Zapier is temporarily unavailable; the HR transaction was saved.'), $event, $started, $endpoint);
        }
    }

    private function finish(IntegrationResult $result, string $event, float $started, string $endpoint): IntegrationResult
    {
        $this->recorder->record($result, $event, (int) ((microtime(true) - $started) * 1000), $endpoint);

        return $result;
    }
}

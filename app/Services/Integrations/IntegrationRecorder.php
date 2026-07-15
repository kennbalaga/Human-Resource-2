<?php

namespace App\Services\Integrations;

use App\Models\IntegrationEvent;
use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class IntegrationRecorder
{
    /** @param array<string, mixed> $context */
    public function record(IntegrationResult $result, string $eventType, int $durationMs, ?string $endpoint = null, array $context = []): void
    {
        try {
            IntegrationEvent::query()->create([
                'request_id' => (string) Str::uuid(),
                'provider' => $result->provider,
                'event_type' => $eventType,
                'status' => $result->success ? 'success' : 'failed',
                'response_code' => $result->httpStatus,
                'duration_ms' => $durationMs,
                'endpoint_host' => $endpoint ? parse_url($endpoint, PHP_URL_HOST) : null,
                'message' => Str::limit($result->message, 1000),
                'context' => $context,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Unable to persist integration event.', [
                'provider' => $result->provider,
                'event_type' => $eventType,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

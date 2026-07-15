<?php

namespace App\Services\Integrations;

use App\Jobs\DeliverZapierWebhook;
use Illuminate\Support\Facades\Log;
use Throwable;

class SafeIntegrationDispatcher
{
    /** @param array<string, mixed> $payload */
    public function zapier(string $event, array $payload): void
    {
        try {
            DeliverZapierWebhook::dispatch($event, $payload);
        } catch (Throwable $exception) {
            Log::warning('Zapier job could not be queued; core transaction remains successful.', [
                'event' => $event,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

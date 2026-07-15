<?php

namespace App\Jobs;

use App\Services\Integrations\ZapierWebhookService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverZapierWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    /** @param array<string, mixed> $payload */
    public function __construct(public string $event, public array $payload)
    {
        $this->onQueue('integrations');
    }

    public function handle(ZapierWebhookService $service): void
    {
        $service->send($this->event, $this->payload);
    }
}

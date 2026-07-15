<?php

namespace App\Services\Integrations;

use App\Support\IntegrationResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ZoomService
{
    public function __construct(private readonly IntegrationRecorder $recorder) {}

    /** @param array{topic: string, start_time: string, duration: int, agenda?: string|null} $meeting */
    public function createMeeting(array $meeting): IntegrationResult
    {
        $started = microtime(true);
        $endpoint = 'https://api.zoom.us/v2/users/'.rawurlencode((string) config('integrations.zoom.user_id')).'/meetings';

        if (! $this->configured()) {
            return $this->finish(IntegrationResult::failure('zoom', 'Zoom is disabled or not configured.'), $started, $endpoint);
        }

        try {
            $token = $this->accessToken();
            if ($token === null) {
                return $this->finish(IntegrationResult::failure('zoom', 'Zoom authentication failed safely.'), $started, $endpoint);
            }
            $response = Http::withToken($token)->acceptJson()
                ->timeout(config('integrations.timeout_seconds'))
                ->retry(2, 250, throw: false)
                ->post($endpoint, [
                    'topic' => $meeting['topic'],
                    'type' => 2,
                    'start_time' => $meeting['start_time'],
                    'duration' => $meeting['duration'],
                    'timezone' => config('integrations.zoom.timezone'),
                    'agenda' => $meeting['agenda'] ?? null,
                    'settings' => ['waiting_room' => true, 'join_before_host' => false],
                ]);

            if (! $response->successful()) {
                return $this->finish(IntegrationResult::failure('zoom', 'Zoom meeting creation failed safely.', $response->status()), $started, $endpoint);
            }

            return $this->finish(IntegrationResult::success('zoom', 'Zoom meeting created.', [
                'meeting_id' => $response->json('id'),
                'join_url' => $response->json('join_url'),
                'start_url' => $response->json('start_url'),
            ], $response->status()), $started, $endpoint);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish(IntegrationResult::failure('zoom', 'Zoom is temporarily unavailable; core HR functions remain available.'), $started, $endpoint);
        }
    }

    private function accessToken(): ?string
    {
        return Cache::remember('integrations.zoom.access_token', now()->addMinutes(50), function (): ?string {
            $response = Http::withBasicAuth(config('integrations.zoom.client_id'), config('integrations.zoom.client_secret'))
                ->asForm()->timeout(config('integrations.timeout_seconds'))->retry(2, 200, throw: false)
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => config('integrations.zoom.account_id'),
                ]);

            return $response->successful() ? $response->json('access_token') : null;
        });
    }

    private function configured(): bool
    {
        return config('integrations.zoom.enabled')
            && filled(config('integrations.zoom.account_id'))
            && filled(config('integrations.zoom.client_id'))
            && filled(config('integrations.zoom.client_secret'));
    }

    private function finish(IntegrationResult $result, float $started, string $endpoint): IntegrationResult
    {
        $this->recorder->record($result, 'meeting.create', (int) ((microtime(true) - $started) * 1000), $endpoint);

        return $result;
    }
}

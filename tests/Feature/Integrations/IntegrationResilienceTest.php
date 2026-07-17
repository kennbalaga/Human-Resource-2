<?php

namespace Tests\Feature\Integrations;

use App\Models\User;
use App\Services\Integrations\GeminiAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IntegrationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Http::preventStrayRequests();
    }

    public function test_gemini_failure_is_recorded_without_throwing_an_exception(): void
    {
        config([
            'integrations.gemini.enabled' => true,
            'integrations.gemini.api_key' => 'test-key',
        ]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);

        $result = app(GeminiAnalyticsService::class)->generateInsights(['active_employees' => 4]);

        $this->assertFalse($result->success);
        $this->assertDatabaseHas('integration_events', [
            'provider' => 'gemini',
            'event_type' => 'analytics.generate',
            'status' => 'failed',
            'response_code' => 503,
        ]);
    }

    public function test_removed_zapier_and_zoom_integrations_are_not_exposed(): void
    {
        $administrator = User::query()
            ->whereHas('employee', fn ($query) => $query->where('employee_number', 'SYS-0001'))
            ->firstOrFail();

        $this->actingAs($administrator)
            ->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Gemini AI')
            ->assertDontSee('Zapier')
            ->assertDontSee('Zoom');

        $this->assertFalse(Route::has('integrations.zapier.test'));
        $this->assertFalse(Route::has('integrations.zoom.meetings.store'));
        $this->assertNull(config('integrations.zapier'));
        $this->assertNull(config('integrations.zoom'));
    }
}

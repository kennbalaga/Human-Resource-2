<?php

namespace Tests\Feature\Integrations;

use App\Models\Employee;
use App\Models\IntegrationEvent;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Integrations\GeminiAnalyticsService;
use App\Services\Integrations\ZapierWebhookService;
use App\Services\Integrations\ZoomService;
use App\Services\LeaveService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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

    public function test_zapier_failure_is_recorded_without_throwing_an_exception(): void
    {
        config([
            'integrations.zapier.enabled' => true,
            'integrations.zapier.webhook_url' => 'https://hooks.zapier.test/hrms',
        ]);
        Http::fake(['*' => Http::response([], 500)]);

        $result = app(ZapierWebhookService::class)->send('leave.approved', ['leave_request_id' => 99]);

        $this->assertFalse($result->success);
        $this->assertDatabaseHas('integration_events', [
            'provider' => 'zapier',
            'event_type' => 'leave.approved',
            'status' => 'failed',
            'response_code' => 500,
        ]);
    }

    public function test_zoom_authentication_failure_does_not_break_the_request(): void
    {
        Cache::forget('integrations.zoom.access_token');
        config([
            'integrations.zoom.enabled' => true,
            'integrations.zoom.account_id' => 'account-id',
            'integrations.zoom.client_id' => 'client-id',
            'integrations.zoom.client_secret' => 'client-secret',
        ]);
        Http::fake(['https://zoom.us/oauth/token' => Http::response([], 401)]);

        $result = app(ZoomService::class)->createMeeting([
            'topic' => 'Workforce review',
            'start_time' => now()->addDay()->toIso8601String(),
            'duration' => 30,
        ]);

        $this->assertFalse($result->success);
        $this->assertDatabaseHas('integration_events', [
            'provider' => 'zoom',
            'event_type' => 'meeting.create',
            'status' => 'failed',
        ]);
    }

    public function test_leave_approval_succeeds_even_when_zapier_is_unavailable(): void
    {
        config([
            'integrations.zapier.enabled' => true,
            'integrations.zapier.webhook_url' => 'https://hooks.zapier.test/hrms',
        ]);
        Http::fake(['*' => Http::response([], 503)]);

        $employee = Employee::query()->where('employee_number', 'HR-0002')->firstOrFail();
        $manager = User::query()->whereHas('employee', fn ($query) => $query->where('employee_number', 'HR-0001'))->firstOrFail();
        $leaveType = LeaveType::query()->where('code', 'VAC')->firstOrFail();
        $start = Carbon::now()->next(Carbon::MONDAY);
        $leave = app(LeaveService::class)->create($employee, [
            'leave_type_id' => $leaveType->id,
            'start_date' => $start->toDateString(),
            'end_date' => $start->toDateString(),
            'reason' => 'Planned personal leave for a family commitment.',
        ]);
        Sanctum::actingAs($manager, ['workforce:read', 'workforce:write']);

        $this->postJson(route('api.v1.leaves.approve', $leave), ['reviewer_notes' => 'Approved for the requested date.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertSame('approved', LeaveRequest::query()->findOrFail($leave->id)->status);
        $this->assertTrue(IntegrationEvent::query()->where('provider', 'zapier')->where('status', 'failed')->exists());
    }
}

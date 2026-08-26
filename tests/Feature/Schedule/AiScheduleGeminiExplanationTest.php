<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\IntegrationEvent;
use App\Models\Position;
use App\Models\ScheduleRecommendation;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiScheduleGeminiExplanationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Employee $employee;

    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Http::preventStrayRequests();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->employee = Employee::query()->where('employee_number', 'HR-OFFICER-2026-0001')->firstOrFail();
        $this->payload = [
            'department_id' => Department::query()->where('code', 'HR')->firstOrFail()->id,
            'position_id' => Position::query()->where('code', 'HR-OFFICER')->firstOrFail()->id,
            'shift_id' => Shift::query()->where('code', 'ADMIN-0800')->firstOrFail()->id,
            'work_date' => '2027-10-01',
        ];
        config([
            'ai_workforce_scheduling.enabled' => true,
            'integrations.gemini.enabled' => true,
            'integrations.gemini.api_key' => 'test-api-key',
        ]);
    }

    public function test_disabled_explanation_integration_uses_laravel_fallback_without_an_outbound_request(): void
    {
        config(['ai_workforce_scheduling.gemini_explanations_enabled' => false]);

        $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload)
            ->assertOk()
            ->assertJsonPath('data.explanation_source', 'laravel')
            ->assertJsonPath('data.recommended.employee_id', $this->employee->id);

        Http::assertNothingSent();
    }

    public function test_gemini_failure_is_audited_and_falls_back_without_changing_the_ranking(): void
    {
        config(['ai_workforce_scheduling.gemini_explanations_enabled' => true]);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unavailable']], 503)]);

        $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload)
            ->assertOk()
            ->assertJsonPath('data.explanation_source', 'laravel')
            ->assertJsonPath('data.recommended.employee_id', $this->employee->id);

        $this->assertDatabaseHas('integration_events', [
            'provider' => 'gemini',
            'event_type' => 'scheduling.explain',
            'status' => 'failed',
            'response_code' => 503,
        ]);
    }

    public function test_gemini_receives_only_pseudonymous_metrics_and_cannot_change_the_recommendation(): void
    {
        config(['ai_workforce_scheduling.gemini_explanations_enabled' => true]);
        Http::fake(['*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'Candidate 1 has the strongest fixed score. HR review remains required.']]],
            ]],
        ], 200)]);

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.ai-recommendations.store'), $this->payload)
            ->assertOk()
            ->assertJsonPath('data.explanation_source', 'gemini')
            ->assertJsonPath('data.recommended.employee_id', $this->employee->id)
            ->assertJsonPath('data.explanation', 'Candidate 1 has the strongest fixed score. HR review remains required.');

        Http::assertSent(function (Request $request): bool {
            $body = $request->body();

            $this->assertStringContainsString('CANDIDATE_1', $body);
            $this->assertStringNotContainsString($this->employee->full_name, $body);
            $this->assertStringNotContainsString($this->employee->employee_number, $body);
            $this->assertStringNotContainsString($this->employee->user->email, $body);
            $this->assertStringNotContainsString('Private leave reason', $body);

            return true;
        });

        $record = ScheduleRecommendation::query()->where('uuid', $response->json('data.recommendation_id'))->firstOrFail();
        $this->assertSame($this->employee->id, $record->recommended_employee_id);
        $this->assertSame('gemini', $record->explanation_source);
        $this->assertSame(1, IntegrationEvent::query()->where('event_type', 'scheduling.explain')->count());
    }
}

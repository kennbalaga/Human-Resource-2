<?php

namespace Tests\Feature\Integrations;

use App\Models\AiSchedulingSetting;
use App\Models\User;
use App\Services\Scheduling\AiSchedulingFeatureSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiSchedulingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config([
            'ai_workforce_scheduling.enabled' => false,
            'ai_workforce_scheduling.gemini_explanations_enabled' => false,
            'integrations.gemini.enabled' => false,
            'integrations.gemini.api_key' => null,
        ]);
        $this->administrator = User::query()->whereHas('roles', fn ($query) => $query->where('slug', 'system-administrator'))->firstOrFail();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_environment_remains_the_fallback_until_an_admin_setting_is_saved(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $settings = app(AiSchedulingFeatureSettings::class);

        $this->assertTrue($settings->assistantEnabled());
        $this->assertSame('environment', $settings->source());
        $this->assertDatabaseCount('ai_scheduling_settings', 0);
    }

    public function test_system_administrator_can_enable_the_assistant_for_all_authorized_scheduling_users(): void
    {
        $this->actingAs($this->administrator)->patch(route('integrations.ai-scheduling.update'), [
            'assistant_enabled' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('ai_scheduling_settings', [
            'id' => 1,
            'assistant_enabled' => true,
            'gemini_explanations_enabled' => false,
            'updated_by' => $this->administrator->id,
        ]);
        $this->flushSession();
        $this->actingAs($this->manager)->get(route('schedules.index'))
            ->assertOk()->assertSee('Generate AI Recommendation');
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->administrator->id,
            'action' => 'integrations.ai-scheduling.update',
            'response_status' => 302,
        ]);
    }

    public function test_saved_off_setting_overrides_an_enabled_environment_without_affecting_manual_scheduling(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        AiSchedulingSetting::query()->create([
            'assistant_enabled' => false,
            'gemini_explanations_enabled' => false,
            'updated_by' => $this->administrator->id,
        ]);

        $response = $this->actingAs($this->manager)->get(route('schedules.index'));
        $response->assertOk()
            ->assertDontSee('Generate AI Recommendation')
            ->assertSee('Save assignment');

        $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), [
            'department_id' => 1,
            'position_id' => 1,
            'shift_id' => 1,
            'work_date' => '2027-10-01',
        ])->assertNotFound();
    }

    public function test_hr_manager_can_view_but_cannot_change_the_global_setting(): void
    {
        $this->actingAs($this->manager)->get(route('integrations.index'))
            ->assertOk()
            ->assertSee('Only a System Administrator can change this global setting.')
            ->assertDontSee('Save AI settings');

        $this->actingAs($this->manager)->patch(route('integrations.ai-scheduling.update'), [
            'assistant_enabled' => '1',
        ])->assertForbidden();
        $this->assertDatabaseCount('ai_scheduling_settings', 0);
    }

    public function test_gemini_explanations_cannot_be_enabled_without_provider_configuration(): void
    {
        $this->actingAs($this->administrator)->from(route('integrations.index'))
            ->patch(route('integrations.ai-scheduling.update'), [
                'assistant_enabled' => '1',
                'gemini_explanations_enabled' => '1',
            ])
            ->assertRedirect(route('integrations.index'))
            ->assertSessionHasErrors('gemini_explanations_enabled');

        $this->assertDatabaseCount('ai_scheduling_settings', 0);
    }

    public function test_disabling_the_assistant_also_disables_gemini_explanations(): void
    {
        config([
            'integrations.gemini.enabled' => true,
            'integrations.gemini.api_key' => 'test-key',
        ]);
        $this->actingAs($this->administrator)->patch(route('integrations.ai-scheduling.update'), [
            'assistant_enabled' => '1',
            'gemini_explanations_enabled' => '1',
        ])->assertRedirect();
        $this->assertTrue(AiSchedulingSetting::query()->firstOrFail()->gemini_explanations_enabled);

        $this->actingAs($this->administrator)->patch(route('integrations.ai-scheduling.update'), [])
            ->assertRedirect();

        $setting = AiSchedulingSetting::query()->firstOrFail();
        $this->assertFalse($setting->assistant_enabled);
        $this->assertFalse($setting->gemini_explanations_enabled);
    }
}

<?php

namespace Tests\Feature\Schedule;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiScheduleUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_feature_flag_controls_the_optional_assistant_without_changing_the_existing_form(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->get(route('schedules.index'))
            ->assertOk()
            ->assertDontSee('Generate AI Recommendation')
            ->assertDontSee('schedule-assignment-dialog');

        config(['ai_workforce_scheduling.enabled' => true]);
        $response = $this->actingAs($manager)->get(route('schedules.index'));

        $response
            ->assertOk()
            ->assertSee('AI bulk schedule')
            ->assertSee('Generate a bulk schedule')
            ->assertSee('Rotating · AI balanced')
            ->assertSee('Custom · AI optimized mix')
            ->assertSee('All active staff')
            ->assertSee('Choose who to schedule')
            ->assertSee('name="night_shift_limit" value="6"', false)
            ->assertSee('bulk-inline-employee-list')
            ->assertSee('Select a department to load its active employees.')
            ->assertSeeInOrder(['value="specific"', 'value="all"'], false)
            ->assertSeeInOrder(['Step 1 · Department and staff', 'Shift pattern'])
            ->assertSee('I reviewed the summary above and approve this bulk schedule')
            ->assertSee('Approve & publish', false)
            ->assertSee('Generate AI Recommendation')
            ->assertSee('AI-Generated Recommendation')
            ->assertSee('schedule-assignment-dialog')
            ->assertSee('Manual assignment')
            ->assertSee('AI suggestions never save automatically.')
            ->assertSee('name="employee_id"', false)
            ->assertSee('name="shift_id"', false)
            ->assertSee('name="work_date"', false)
            ->assertSee('Save assignment')
            ->assertSee(route('schedules.store'), false)
            ->assertSee(route('schedules.ai-recommendations.store'), false);
    }

    public function test_standard_employee_never_sees_ai_management_controls(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get(route('schedules.index'))
            ->assertOk()->assertDontSee('Generate AI Recommendation');
    }

    public function test_ai_javascript_has_no_form_submission_or_reset_behavior(): void
    {
        $script = file_get_contents(resource_path('js/ai-scheduling.js'));

        $this->assertStringNotContainsString('.submit(', $script);
        $this->assertStringNotContainsString('.reset(', $script);
        $this->assertStringContainsString("event.target.closest('[data-ai-select-candidate]')", $script);
        $this->assertStringContainsString('form.elements.employee_id.value = String(data.employee_id)', $script);
        $this->assertStringContainsString("form.elements.employee_id.dispatchEvent(new Event('change'", $script);
    }
}

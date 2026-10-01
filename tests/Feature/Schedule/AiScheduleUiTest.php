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
            ->assertSee('Select all shown')
            ->assertSee('Choose who to schedule')
            ->assertSee('name="night_shift_limit" value="6"', false)
            ->assertSee('bulk-inline-employee-list')
            ->assertSee('Select a department to load its active employees.')
            ->assertSeeInOrder(['data-bulk-show="all"', 'data-bulk-show="selected"', 'data-bulk-show="unselected"'], false)
            ->assertSeeInOrder(['Choose who to schedule', 'Shift pattern'])
            ->assertSee('I reviewed the summary above and approve this bulk schedule')
            ->assertSee('Approve & publish', false)
            ->assertSee('Generate AI Recommendation')
            ->assertSee('Who can take this shift?')
            ->assertSee('The assistant checks each employee for:')
            ->assertSee('Performance ratings and disciplinary records are never used.')
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

    /**
     * The three scheduling dialogs against the workforce design: the staff
     * table's own select-all and search, the two ways a series ends, and the
     * left-out list shown rather than folded away.
     *
     * Markup, not looks -- but each of these is a control that moved, and a
     * control that moves silently back is the way a redesign comes undone.
     */
    public function test_the_scheduling_dialogs_carry_the_workforce_design_controls(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);

        $this->actingAs(User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail())
            ->get(route('schedules.index'))
            ->assertOk()
            // Step 1: select-all is the table's own header cell, and the
            // search sits in the toolbar over the rows it filters.
            ->assertSee('bulk-employee-head', false)
            ->assertSee('bulk-staff-search', false)
            ->assertSee('aria-label="Search employee name or ID"', false)
            ->assertSee('Choose a department and position, then select specific employees')
            // Recurring: ends on a date, or after a number of shifts.
            ->assertSee('name="end_mode" value="on"', false)
            ->assertSee('name="end_mode" value="after"', false)
            ->assertSee('name="occurrences"', false)
            ->assertSee('data-recurring-employee-meta', false)
            ->assertSee('Busiest week, paid hours')
            // Assign: who was left out is shown, not folded.
            ->assertSee('data-ai-ineligible-title', false)
            ->assertSee('These fields and the final save stay with HR.');
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
        $this->assertStringContainsString("event.target.closest('[data-ai-use-candidate]')", $script);
        $this->assertStringContainsString('form.elements.employee_id.value = String(data.employee_id)', $script);
        $this->assertStringContainsString("form.elements.employee_id.dispatchEvent(new Event('change'", $script);
    }
}

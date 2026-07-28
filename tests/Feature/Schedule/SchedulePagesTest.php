<?php

namespace Tests\Feature\Schedule;

use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchedulePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/schedules')->assertRedirect('/login');
    }

    public function test_hr_manager_can_view_calendar_and_shift_templates(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->get('/schedules?view=month')
            ->assertOk()
            ->assertSee('Shift &amp; Schedule Management', false)
            ->assertSee('Recurring schedule')
            ->assertSee('Assign shift');

        $this->actingAs($manager)
            ->get('/shifts')
            ->assertOk()
            ->assertSee('Shift templates')
            ->assertSee('Morning Shift')
            ->assertSee('Night Shift');
    }

    public function test_standard_employee_sees_own_schedule_without_management_controls(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)
            ->get('/schedules')
            ->assertOk()
            ->assertDontSee('Assign shift')
            ->assertDontSee('Recurring schedule');

        $this->actingAs($employee)->get('/shifts')->assertForbidden();
    }

    public function test_standard_employee_cannot_create_an_assignment(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $shift = Shift::query()->firstOrFail();

        $this->actingAs($employee)->post('/schedules', [
            'employee_id' => $employee->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-04-01',
        ])->assertForbidden();
    }

    public function test_conflict_endpoint_reports_an_overlap(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $assignment = ScheduleAssignment::query()
            ->where('employee_id', $manager->employee->id)
            ->firstOrFail();

        $this->actingAs($manager)->postJson('/schedules/conflicts', [
            'employee_id' => $assignment->employee_id,
            'shift_id' => $assignment->shift_id,
            'work_date' => $assignment->work_date->toDateString(),
        ])->assertOk()
            ->assertJsonPath('has_conflicts', true)
            ->assertJsonCount(1, 'conflicts');
    }

    public function test_hr_manager_can_create_and_update_shift_template(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)->post('/shifts', [
            'code' => 'MANUAL-CODE',
            'name' => 'Flex Shift',
            'start_time' => '10:00',
            'end_time' => '18:00',
            'break_minutes' => 30,
            'color' => '#2F80ED',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $shift = Shift::query()->where('code', 'FLEX-1000')->firstOrFail();

        $this->actingAs($manager)->put("/shifts/{$shift->id}", [
            'name' => 'Flexible Shift',
            'start_time' => '10:00',
            'end_time' => '18:00',
            'break_minutes' => 45,
            'color' => '#176B43',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('shifts', [
            'id' => $shift->id,
            'name' => 'Flexible Shift',
            'break_minutes' => 45,
            'is_active' => false,
        ]);
    }
}

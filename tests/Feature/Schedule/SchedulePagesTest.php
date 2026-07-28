<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
            ->assertSee('Department schedule')
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

        $this->actingAs($employee)->post('/schedules/bulk-preview', [
            'department_id' => $employee->employee->department_id,
            'employee_ids' => [$employee->employee->id],
            'shift_id' => $shift->id,
            'start_date' => '2027-04-01',
            'end_date' => '2027-04-01',
        ])->assertForbidden();
    }

    public function test_hr_manager_can_review_and_create_valid_bulk_assignments_without_overwriting_conflicts(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $otherEmployee = Employee::query()
            ->where('employment_status', 'active')
            ->where('department_id', $manager->employee->department_id)
            ->whereKeyNot($manager->employee->id)
            ->firstOrFail();
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
        $date = '2027-04-05';

        $this->actingAs($manager)->post('/schedules', [
            'employee_id' => $manager->employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date,
        ])->assertSessionHasNoErrors();

        $payload = [
            'department_id' => $manager->employee->department_id,
            'employee_ids' => [$manager->employee->id, $otherEmployee->id],
            'shift_id' => $shift->id,
            'start_date' => $date,
            'end_date' => $date,
        ];

        $this->actingAs($manager)->postJson('/schedules/bulk-preview', $payload)
            ->assertOk()
            ->assertJsonPath('ready_count', 1)
            ->assertJsonPath('skipped_count', 1);

        $this->actingAs($manager)->post('/schedules/bulk', $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', '1 assignment created. 1 skipped because of conflicts, leave, or inactive employees.');

        $this->assertDatabaseHas('schedule_assignments', [
            'employee_id' => $otherEmployee->id,
            'shift_id' => $shift->id,
            'work_date' => $date.' 00:00:00',
        ]);
        $this->assertSame(1, ScheduleAssignment::query()
            ->where('employee_id', $manager->employee->id)
            ->where('shift_id', $shift->id)
            ->whereDate('work_date', $date)
            ->count());
    }

    public function test_bulk_preview_skips_an_employee_with_approved_leave(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = Employee::query()->where('employment_status', 'active')->firstOrFail();
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
        $date = '2027-04-06';
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => $date,
            'end_date' => $date,
            'requested_days' => 1,
            'reason' => 'Approved leave for bulk scheduling test.',
            'status' => 'approved',
        ]);

        $this->actingAs($manager)->postJson('/schedules/bulk-preview', [
            'department_id' => $employee->department_id,
            'employee_ids' => [$employee->id],
            'shift_id' => $shift->id,
            'start_date' => $date,
            'end_date' => $date,
        ])->assertOk()
            ->assertJsonPath('ready_count', 0)
            ->assertJsonPath('skipped_count', 1)
            ->assertJsonPath('skipped.0.reason', 'Approved leave');
    }

    public function test_bulk_periods_generate_weekly_biweekly_and_monthly_date_ranges(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $employee = Employee::query()->where('employment_status', 'active')->firstOrFail();
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
        $base = ['department_id' => $employee->department_id, 'employee_ids' => [$employee->id], 'shift_id' => $shift->id, 'include_weekends' => true];

        $this->actingAs($manager)->postJson('/schedules/bulk-preview', $base + [
            'schedule_period' => 'weekly',
            'period_start' => '2027-04-04',
        ])->assertOk()->assertJsonPath('requested_count', 7);

        $this->actingAs($manager)->postJson('/schedules/bulk-preview', $base + [
            'schedule_period' => 'two_weeks',
            'period_start' => '2027-04-04',
        ])->assertOk()->assertJsonPath('requested_count', 14);

        $this->actingAs($manager)->postJson('/schedules/bulk-preview', $base + [
            'schedule_period' => 'monthly',
            'period_month' => '2028-02',
        ])->assertOk()->assertJsonPath('requested_count', 29);
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

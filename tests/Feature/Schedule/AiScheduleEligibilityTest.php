<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\EmployeeEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiScheduleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Employee $employee;

    private Department $department;

    private Position $position;

    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->employee = Employee::query()->where('employee_number', 'HR-0002')->firstOrFail();
        $this->department = Department::query()->where('code', 'HR')->firstOrFail();
        $this->position = Position::query()->where('code', 'HR-OFFICER')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
    }

    public function test_feature_is_disabled_by_default(): void
    {
        $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload())->assertNotFound();
    }

    public function test_standard_employees_remain_unauthorized(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);

        $this->actingAs($this->employee->user)
            ->postJson(route('schedules.ai-recommendations.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_generation_is_read_only_and_returns_eligible_and_ineligible_candidates(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $before = [
            'schedules' => ScheduleAssignment::query()->count(),
            'employees' => Employee::query()->count(),
            'leaves' => LeaveRequest::query()->count(),
        ];

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.ai-recommendations.store'), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.eligible.0.employee_id', $this->employee->id)
            ->assertJsonPath('data.eligible.0.eligible', true)
            ->assertJsonPath('data.ineligible.0.reasons.0.code', 'position_mismatch')
            ->assertJsonPath('data.recommended.employee_id', $this->employee->id);

        $this->assertNotEmpty($response->json('data.warnings'));
        $this->assertSame($before['schedules'], ScheduleAssignment::query()->count());
        $this->assertSame($before['employees'], Employee::query()->count());
        $this->assertSame($before['leaves'], LeaveRequest::query()->count());
    }

    public function test_approved_leave_and_overlap_make_an_employee_ineligible_but_pending_or_rejected_leave_does_not(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $date = '2027-10-04';

        foreach (['pending', 'rejected'] as $status) {
            $leave = $this->leave($date, $date, $status);
            $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload($date))
                ->assertOk()->assertJsonPath('data.eligible.0.employee_id', $this->employee->id);
            $leave->delete();
        }

        $this->leave($date, $date, 'approved');
        $response = $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload($date))->assertOk();
        $employeeResult = collect($response->json('data.ineligible'))->firstWhere('employee_id', $this->employee->id);
        $this->assertContains('approved_leave', collect($employeeResult['reasons'])->pluck('code'));

        LeaveRequest::query()->delete();
        ScheduleAssignment::query()->create([
            'employee_id' => $this->employee->id,
            'shift_id' => $this->dayShift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]);
        $response = $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload($date))->assertOk();
        $employeeResult = collect($response->json('data.ineligible'))->firstWhere('employee_id', $this->employee->id);
        $this->assertContains('schedule_overlap', collect($employeeResult['reasons'])->pluck('code'));
    }

    public function test_overnight_shift_checks_leave_on_the_following_day(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $night = Shift::query()->where('code', 'NIGHT-2300')->firstOrFail();
        $this->leave('2027-10-06', '2027-10-06', 'approved');

        $response = $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), $this->payload('2027-10-05', $night))->assertOk();
        $employeeResult = collect($response->json('data.ineligible'))->firstWhere('employee_id', $this->employee->id);

        $this->assertContains('approved_leave', collect($employeeResult['reasons'])->pluck('code'));
    }

    public function test_candidate_evaluation_detects_wrong_department_position_and_inactive_status(): void
    {
        $this->employee->update(['employment_status' => 'inactive']);
        $otherDepartment = Department::query()->where('code', 'IT')->firstOrFail();
        $otherPosition = Position::query()->where('code', 'SYS-ADMIN')->firstOrFail();

        $result = app(EmployeeEligibilityService::class)->evaluateCandidate(
            $this->employee->fresh(),
            $otherDepartment,
            $otherPosition,
            $this->dayShift,
            '2027-10-08',
        );

        $codes = collect($result['reasons'])->pluck('code');
        $this->assertContains('employee_inactive', $codes);
        $this->assertContains('department_mismatch', $codes);
        $this->assertContains('position_mismatch', $codes);
    }

    public function test_api_endpoint_requires_manager_role_and_write_ability(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        Sanctum::actingAs($this->manager, ['workforce:read']);
        $this->postJson(route('api.v1.schedule-recommendations.store'), $this->payload())->assertForbidden();

        Sanctum::actingAs($this->manager, ['workforce:read', 'workforce:write']);
        $this->postJson(route('api.v1.schedule-recommendations.store'), $this->payload())
            ->assertOk()->assertJsonPath('data.eligible.0.employee_id', $this->employee->id);
    }

    /** @return array<string, mixed> */
    private function payload(string $date = '2027-10-01', ?Shift $shift = null): array
    {
        return [
            'department_id' => $this->department->id,
            'position_id' => $this->position->id,
            'shift_id' => ($shift ?? $this->dayShift)->id,
            'work_date' => $date,
        ];
    }

    private function leave(string $start, string $end, string $status): LeaveRequest
    {
        return LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => $start,
            'end_date' => $end,
            'requested_days' => 1,
            'reason' => 'Eligibility test leave record.',
            'status' => $status,
        ]);
    }
}

<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ScheduleSkillMixTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleService $service;

    private Department $department;

    private Position $staffPosition;

    private Position $chargePosition;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->service = app(ScheduleService::class);
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $this->department = Department::query()->create([
            'code' => 'SKILLMIX',
            'name' => 'Skill Mix Ward',
            'is_active' => true,
        ]);

        $this->staffPosition = Position::query()->create([
            'department_id' => $this->department->id,
            'code' => 'SM-STAFF',
            'title' => 'Ward Staff Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);

        $this->chargePosition = Position::query()->create([
            'department_id' => $this->department->id,
            'code' => 'SM-CHARGE',
            'title' => 'Ward Charge Nurse',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);
    }

    public function test_positions_default_to_entry_level_rank(): void
    {
        $position = Position::query()->create([
            'department_id' => $this->department->id,
            'code' => 'SM-UNSET',
            'title' => 'Unranked Position',
            'is_active' => true,
        ]);

        $this->assertSame(1, $position->refresh()->seniority_rank);
    }

    public function test_it_reports_a_gap_when_a_shift_has_no_senior_on_duty(): void
    {
        $juniors = collect([
            $this->employee('SM-2026-0001', $this->staffPosition),
            $this->employee('SM-2026-0002', $this->staffPosition),
        ]);

        $plan = $this->service->bulkAssignmentPlan($this->payload($juniors, [
            'minimum_senior_per_shift' => 1,
            'senior_rank_threshold' => 3,
        ]));

        // Head count is satisfied, so only the seniority rule should complain.
        $this->assertCount(2, $plan['ready']);
        $this->assertCount(1, $plan['staffingGaps']);

        $gap = $plan['staffingGaps']->first();
        $this->assertSame('senior staff (rank 3+)', $gap['label']);
        $this->assertSame(0, $gap['available']);
        $this->assertSame(1, $gap['required']);
        $this->assertSame('2027-03-01', $gap['date']);
    }

    public function test_it_clears_the_gap_once_a_senior_covers_the_shift(): void
    {
        $team = collect([
            $this->employee('SM-2026-0003', $this->staffPosition),
            $this->employee('SM-2026-0004', $this->chargePosition),
        ]);

        $plan = $this->service->bulkAssignmentPlan($this->payload($team, [
            'minimum_senior_per_shift' => 1,
            'senior_rank_threshold' => 3,
        ]));

        $this->assertCount(2, $plan['ready']);
        $this->assertTrue($plan['staffingGaps']->isEmpty());
    }

    public function test_a_rank_below_the_threshold_does_not_count_as_senior(): void
    {
        $team = collect([
            $this->employee('SM-2026-0005', $this->staffPosition),
            $this->employee('SM-2026-0006', $this->chargePosition),
        ]);

        // The charge nurse sits at rank 3, so demanding rank 4+ must still flag it.
        $plan = $this->service->bulkAssignmentPlan($this->payload($team, [
            'minimum_senior_per_shift' => 1,
            'senior_rank_threshold' => 4,
        ]));

        $this->assertCount(1, $plan['staffingGaps']);
        $this->assertSame('senior staff (rank 4+)', $plan['staffingGaps']->first()['label']);
    }

    public function test_the_seniority_check_is_skipped_when_set_to_zero(): void
    {
        $juniors = collect([$this->employee('SM-2026-0007', $this->staffPosition)]);

        $plan = $this->service->bulkAssignmentPlan($this->payload($juniors, [
            'minimum_senior_per_shift' => 0,
        ]));

        $this->assertTrue($plan['staffingGaps']->isEmpty());
    }

    public function test_both_head_count_and_seniority_gaps_are_reported_together(): void
    {
        $juniors = collect([$this->employee('SM-2026-0008', $this->staffPosition)]);
        $this->department->shiftRequirements()->create([
            'shift_id' => $this->shift->id,
            'minimum_staff' => 3,
            'minimum_senior' => 0,
        ]);

        $plan = $this->service->bulkAssignmentPlan($this->payload($juniors, [
            'minimum_senior_per_shift' => 1,
            'senior_rank_threshold' => 3,
        ]));

        $labels = $plan['staffingGaps']->pluck('label')->all();
        $this->assertSame(['staff', 'senior staff (rank 3+)'], $labels);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function payload($employees, array $rules): array
    {
        return array_merge([
            'department_id' => $this->department->id,
            'employee_ids' => $employees->pluck('id')->all(),
            'shift_id' => $this->shift->id,
            // A single Monday keeps the plan to one shift to assert against.
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-01',
        ], $rules);
    }

    private function employee(string $number, Position $position): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->department->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Ward',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }
}

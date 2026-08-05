<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Services\ScheduleService;
use App\Services\Scheduling\RotationScheduleService;
use App\Services\Scheduling\StaffingRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffingStandardTest extends TestCase
{
    use RefreshDatabase;

    private Department $ward;

    private Position $staffPosition;

    private Position $chargePosition;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->ward = Department::query()->create([
            'code' => 'WARD1',
            'name' => 'Medical Ward 1',
            'category' => Department::CATEGORY_CLINICAL,
            'bed_capacity' => 36,
            'nurse_patient_ratio' => 12,
            'is_active' => true,
        ]);

        $this->staffPosition = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'W1-STAFF',
            'title' => 'Ward Staff Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);

        $this->chargePosition = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'W1-CHARGE',
            'title' => 'Ward Charge Nurse',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);
    }

    public function test_it_derives_the_shift_requirement_from_beds_and_ratio(): void
    {
        // 36 beds at 1:12 is three nurses on duty for any one shift.
        $this->assertSame(3, $this->ward->derivedMinimumStaffPerShift());

        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $requirement = app(StaffingRequirementService::class)->forShift($this->ward, $shift);

        $this->assertSame(3, $requirement['staff']);
        $this->assertSame('beds and ratio', $requirement['source']);
    }

    public function test_a_unit_without_beds_has_nothing_to_derive(): void
    {
        $office = Department::query()->create([
            'code' => 'OFFICE1',
            'name' => 'Records Office',
            'category' => Department::CATEGORY_ADMINISTRATIVE,
            'is_active' => true,
        ]);

        $this->assertNull($office->derivedMinimumStaffPerShift());

        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $requirement = app(StaffingRequirementService::class)->forShift($office, $shift);

        $this->assertSame(1, $requirement['staff']);
        $this->assertSame('default minimum', $requirement['source']);
    }

    public function test_a_recorded_shift_standard_overrides_the_derived_figure(): void
    {
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $this->ward->shiftRequirements()->create([
            'shift_id' => $night->id,
            'minimum_staff' => 5,
            'minimum_senior' => 2,
        ]);

        $requirement = app(StaffingRequirementService::class)->forShift($this->ward->fresh(), $night);

        $this->assertSame(5, $requirement['staff']);
        $this->assertSame(2, $requirement['senior']);
        $this->assertSame('unit shift standard', $requirement['source']);
    }

    public function test_bulk_scheduling_reads_the_unit_standard_without_being_told(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $nurses = collect([$this->employee('W1-0001', $this->staffPosition)]);

        // No minimum is passed: the ward's own standard of three must apply.
        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => $nurses->pluck('id')->all(),
            'shift_id' => $shift->id,
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-01',
        ]);

        $gap = $plan['staffingGaps']->firstWhere('label', 'staff');
        $this->assertNotNull($gap);
        $this->assertSame(3, $gap['required']);
        $this->assertSame(1, $gap['available']);
        $this->assertStringContainsString('beds and ratio', $gap['suggestion']);
    }

    public function test_a_figure_typed_into_the_roster_form_overrides_the_standard(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $nurses = collect([$this->employee('W1-0002', $this->staffPosition)]);

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => $nurses->pluck('id')->all(),
            'shift_id' => $shift->id,
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-01',
            'minimum_staff_per_shift' => 1,
        ]);

        $this->assertTrue($plan['staffingGaps']->isEmpty());
    }

    public function test_the_rotation_fills_shifts_towards_their_own_requirement(): void
    {
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        // Mornings need four on duty, nights only one.
        $this->ward->shiftRequirements()->create(['shift_id' => $morning->id, 'minimum_staff' => 4, 'minimum_senior' => 0]);
        $this->ward->shiftRequirements()->create(['shift_id' => $night->id, 'minimum_staff' => 1, 'minimum_senior' => 0]);

        $nurses = collect(range(1, 5))->map(fn (int $i) => $this->employee('W1-10'.$i, $this->staffPosition));

        $plan = app(RotationScheduleService::class)->plan([
            'department_id' => $this->ward->id,
            'employee_ids' => $nurses->pluck('id')->all(),
            'shift_ids' => [$morning->id, $night->id],
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-05',
            'days_off_per_week' => 0,
        ]);

        $firstDay = $plan['ready_assignments']->filter(fn (array $item) => $item['date']->toDateString() === '2027-03-01');
        $onMorning = $firstDay->filter(fn (array $item) => $item['shift']->id === $morning->id)->count();
        $onNight = $firstDay->filter(fn (array $item) => $item['shift']->id === $night->id)->count();

        // An even split would have put two or three on each; the requirement must win.
        $this->assertSame(4, $onMorning);
        $this->assertSame(1, $onNight);
    }

    public function test_the_rotation_puts_a_senior_on_every_shift_that_needs_one(): void
    {
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        foreach ([$morning, $night] as $shift) {
            $this->ward->shiftRequirements()->create([
                'shift_id' => $shift->id,
                'minimum_staff' => 2,
                'minimum_senior' => 1,
            ]);
        }

        // Two charge nurses among six, one for each shift if placed deliberately.
        $team = collect([
            $this->employee('W1-2001', $this->chargePosition),
            $this->employee('W1-2002', $this->chargePosition),
            $this->employee('W1-2003', $this->staffPosition),
            $this->employee('W1-2004', $this->staffPosition),
            $this->employee('W1-2005', $this->staffPosition),
            $this->employee('W1-2006', $this->staffPosition),
        ]);

        $plan = app(RotationScheduleService::class)->plan([
            'department_id' => $this->ward->id,
            'employee_ids' => $team->pluck('id')->all(),
            'shift_ids' => [$morning->id, $night->id],
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-05',
            'days_off_per_week' => 0,
        ]);

        $this->assertTrue(
            $plan['staffing_gaps']->where('label', 'like', 'senior%')->isEmpty(),
            'The rotation left a shift without a charge nurse.',
        );

        $firstDay = $plan['ready_assignments']->filter(fn (array $item) => $item['date']->toDateString() === '2027-03-01');

        foreach ([$morning, $night] as $shift) {
            $seniors = $firstDay
                ->filter(fn (array $item) => $item['shift']->id === $shift->id)
                ->filter(fn (array $item) => $item['employee']->position->seniority_rank >= ScheduleService::DEFAULT_SENIOR_RANK_THRESHOLD)
                ->count();

            $this->assertGreaterThanOrEqual(1, $seniors, "{$shift->name} has no senior on duty.");
        }
    }

    public function test_the_manager_can_record_a_shift_coverage_standard(): void
    {
        $manager = \App\Models\User::query()->whereHas('roles', fn ($q) => $q->where('slug', 'hr-manager'))->firstOrFail();
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->actingAs($manager)
            ->put(route('departments.shift-coverage.update', $this->ward), [
                'requirements' => [
                    $morning->id => ['minimum_staff' => 6, 'minimum_senior' => 2],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('department_shift_requirements', [
            'department_id' => $this->ward->id,
            'shift_id' => $morning->id,
            'minimum_staff' => 6,
            'minimum_senior' => 2,
        ]);
    }

    private function employee(string $number, Position $position): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->ward->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Ward',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }
}

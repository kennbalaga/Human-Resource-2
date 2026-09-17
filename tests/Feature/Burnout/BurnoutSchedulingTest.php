<?php

namespace Tests\Feature\Burnout;

use App\Models\BurnoutRiskSnapshot;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Burnout\BurnoutProtection;
use App\Services\Burnout\BurnoutRiskService;
use App\Services\ScheduleService;
use App\Services\Scheduling\RosterDraftService;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Employees at high burnout risk are kept inside stricter limits by every path
 * that proposes a roster, and flagged by the one that checks a roster by hand.
 */
class BurnoutSchedulingTest extends TestCase
{
    use RefreshDatabase;

    private Department $ward;

    private Position $staff;

    private Position $charge;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Notification::fake();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->ward = Department::query()->create([
            'code' => 'BS-WARD',
            'name' => 'Burnout Scheduling Ward',
            'category' => Department::CATEGORY_CLINICAL,
            'bed_capacity' => 24,
            'nurse_patient_ratio' => 12,
            'is_active' => true,
        ]);
        $this->staff = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'BS-STAFF',
            'title' => 'Staff Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);
        $this->charge = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'BS-CHARGE',
            'title' => 'Charge Nurse',
            'seniority_rank' => 3,
            'is_active' => true,
        ]);
    }

    public function test_bulk_fill_keeps_two_rest_days_and_the_hour_cap_for_a_high_risk_employee(): void
    {
        $atRisk = $this->employee('BS-0001', level: 'high');
        $fine = $this->employee('BS-0002', level: 'low');
        $office = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => [$atRisk->id, $fine->id],
            'shift_id' => $office->id,
            'start_date' => '2027-11-01',
            'end_date' => '2027-11-07',
            'include_weekends' => true,
            'days_off_per_week' => 1,
        ]);

        $placed = $plan['ready']->countBy(fn (array $item) => $item['employee']->id);
        // Five eight-hour days is both the two-rest-day limit and the 40-hour cap.
        $this->assertSame(5, $placed[$atRisk->id]);
        $this->assertSame(6, $placed[$fine->id]);
        $this->assertTrue($plan['skipped']->contains(fn (array $skip) => $skip['employee'] === $atRisk->full_name
            && str_starts_with($skip['reason'], BurnoutProtection::REASON_PREFIX)));
        $this->assertFalse($plan['skipped']->contains(fn (array $skip) => $skip['employee'] === $fine->full_name
            && str_starts_with($skip['reason'], BurnoutProtection::REASON_PREFIX)));
    }

    public function test_bulk_fill_caps_night_shifts_for_a_high_risk_employee(): void
    {
        $atRisk = $this->employee('BS-0003', level: 'high');
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => [$atRisk->id],
            'shift_id' => $night->id,
            'start_date' => '2027-11-01',
            'end_date' => '2027-11-07',
            'include_weekends' => true,
        ]);

        $this->assertCount((int) config('burnout.protection.max_night_shifts_per_week'), $plan['ready']);
    }

    public function test_bulk_fill_fills_a_staffing_ceiling_with_lower_risk_employees_first(): void
    {
        // Alphabetically first, so without the ordering it would take the slot.
        $atRisk = $this->employee('BS-0004', level: 'high', lastName: 'Aardvark');
        $fine = $this->employee('BS-0005', level: 'low', lastName: 'Zamora');
        $office = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => [$atRisk->id, $fine->id],
            'shift_id' => $office->id,
            'start_date' => '2027-11-01',
            'end_date' => '2027-11-01',
            'maximum_staff_per_shift' => 1,
        ]);

        $this->assertSame([$fine->id], $plan['ready']->map(fn (array $item) => $item['employee']->id)->all());
    }

    public function test_switching_protection_off_restores_the_ordinary_limits(): void
    {
        config(['burnout.protection.enabled' => false]);
        $atRisk = $this->employee('BS-0006', level: 'high');
        $office = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => [$atRisk->id],
            'shift_id' => $office->id,
            'start_date' => '2027-11-01',
            'end_date' => '2027-11-07',
            'include_weekends' => true,
            'days_off_per_week' => 1,
            'overtime_allowed' => true,
        ]);

        $this->assertCount(6, $plan['ready']);
    }

    public function test_the_rotation_assistant_gives_a_high_risk_employee_two_rest_days(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $atRisk = $this->employee('BS-0007', level: 'high');
        $shiftIds = Shift::query()->whereIn('code', ['MORNING-0600', 'AFTERNOON-1400'])->orderBy('start_time')->pluck('id')->all();

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), [
                'department_id' => $this->ward->id,
                'employee_ids' => [$atRisk->id],
                'shift_ids' => $shiftIds,
                'schedule_period' => 'weekly',
                'period_start' => '2027-11-01',
            ])
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 5)
            ->assertJsonPath('data.day_off_count', 2);

        $week = $response->json('data.rows.0.weeks.0');
        $this->assertCount(2, $week['day_offs']);
    }

    public function test_the_board_flags_a_high_risk_employee_placed_past_their_limits_and_publishing_needs_a_reason(): void
    {
        $atRisk = $this->employee('BS-0008', level: 'high', position: $this->charge);
        $colleague = $this->employee('BS-0009', level: 'low');
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $rules = ['shift_ids' => [$morning->id]];

        // Six mornings in one week: one past the two-rest-day limit.
        $entries = collect(['2027-04-05', '2027-04-06', '2027-04-07', '2027-04-08', '2027-04-09', '2027-04-10'])
            ->flatMap(fn (string $date) => [
                ['employee_id' => $atRisk->id, 'shift_id' => $morning->id, 'work_date' => $date],
                ['employee_id' => $colleague->id, 'shift_id' => $morning->id, 'work_date' => $date],
            ])
            ->values();
        $service = app(RosterDraftService::class);

        $evaluation = $service->evaluate($this->ward, $entries, '2027-04-05', '2027-04-10', $rules);

        $this->assertSame(0, $evaluation['summary']['shifts_short']);
        $this->assertSame(1, $evaluation['summary']['burnout_warnings']);
        $this->assertSame(1, $evaluation['summary']['burnout_protected']);
        $this->assertSame($atRisk->id, $evaluation['burnout_warnings'][0]['employee_id']);
        $this->assertSame('2027-04-10', $evaluation['burnout_warnings'][0]['work_date']);
        // Still on the board: a warning, not a block.
        $this->assertSame(12, $evaluation['summary']['assignments']);
        $marked = collect($evaluation['days'][0]['shifts'])->firstWhere('shift_id', $morning->id)['assigned'];
        $this->assertSame('high', collect($marked)->firstWhere('employee_id', $atRisk->id)['burnout_level']);

        try {
            $service->publish($this->ward, $entries, $this->manager, null, null, $rules);
            $this->fail('A roster past a high-risk employee\'s limits was published without a justification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('burnout_justification', $exception->errors());
        }
        $this->assertSame(0, ScheduleAssignment::query()->where('employee_id', $atRisk->id)->count());

        $result = $service->publish($this->ward, $entries, $this->manager, null, null, $rules + [
            'burnout_justification' => 'Two nurses out sick; cover arranged with the charge nurse.',
        ]);

        $this->assertSame(12, $result['assignments']->count());
        $this->assertStringContainsString(
            'Burnout risk justification: Two nurses out sick',
            (string) ScheduleAssignment::query()->where('employee_id', $atRisk->id)->value('notes'),
        );
    }

    public function test_the_single_shift_assistant_ranks_a_high_risk_employee_last(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $office = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        // The position is new to this test, so these two are the whole pool.
        $rested = $this->employee('BS-0010', level: 'high');
        $busy = $this->employee('BS-0011', level: 'low');
        // The low-risk candidate is the busier one this week, so on points
        // alone the high-risk one would win.
        foreach (['2027-11-01', '2027-11-02', '2027-11-03'] as $date) {
            RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
                'employee_id' => $busy->id,
                'shift_id' => $office->id,
                'work_date' => $date,
                'status' => 'scheduled',
                'created_by' => $this->manager->id,
            ]));
        }

        $response = $this->actingAs($this->manager)->postJson(route('schedules.ai-recommendations.store'), [
            'department_id' => $this->ward->id,
            'position_id' => $this->staff->id,
            'shift_id' => $office->id,
            'work_date' => '2027-11-05',
        ])->assertOk();

        $response->assertJsonPath('data.recommended.employee_id', $busy->id);
        $eligible = collect($response->json('data.eligible'));
        $this->assertSame($rested->id, $eligible->last()['employee_id']);
        $this->assertTrue($eligible->last()['burnout_protected']);
        $this->assertSame('high', $eligible->last()['burnout_risk']['level']);
        // Ranked last despite the higher score.
        $this->assertGreaterThan($eligible->first()['score'], $eligible->last()['score']);
        $this->assertTrue(collect($response->json('data.warnings'))->contains(fn (string $warning) => str_contains($warning, 'high burnout risk')));
    }

    private function employee(string $number, string $level, ?Position $position = null, ?string $lastName = null): Employee
    {
        $position ??= $this->staff;

        $employee = Employee::query()->create([
            'department_id' => $this->ward->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Scheduled',
            'last_name' => $lastName ?? 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2020-01-01',
        ]);

        // Today's assessment, stated rather than built from a month of records:
        // these tests are about what scheduling does with a level.
        BurnoutRiskSnapshot::query()->create([
            'employee_id' => $employee->id,
            'as_of_date' => app(BurnoutRiskService::class)->today()->toDateString(),
            'score' => $level === 'high' ? 72 : 8,
            'previous_score' => $level === 'high' ? 50 : 8,
            'level' => $level,
            'factors' => [],
        ]);

        return $employee;
    }
}

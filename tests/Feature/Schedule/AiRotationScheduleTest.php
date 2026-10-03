<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AiRotationScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Employee $employee;

    /** @var array<int> */
    private array $shiftIds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->employee = Employee::query()->where('employee_number', 'HR-OFFICER-2026-0001')->firstOrFail();
        // Two rotating legs. The Administrative Shift used to stand in as the
        // second here, but a standalone office day is no longer poolable with a
        // rotating one — the two describe incompatible patterns, and pairing
        // them is exactly what the pool rules now refuse.
        $this->shiftIds = Shift::query()
            ->whereIn('code', ['MORNING-0600', 'AFTERNOON-1400'])
            ->orderBy('start_time')
            ->pluck('id')
            ->all();
    }

    public function test_rotation_generator_is_protected_by_the_ai_feature_setting(): void
    {
        $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $this->payload())
            ->assertNotFound();
    }

    public function test_weekly_rotation_keeps_one_shift_and_creates_one_day_off(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 6)
            ->assertJsonPath('data.day_off_count', 1)
            ->assertJsonPath('data.skipped_count', 0)
            ->assertJsonPath('data.rows.0.weeks.0.assignments', 6)
            ->assertJsonCount(7, 'data.rows.0.weeks.0.days');

        $week = $response->json('data.rows.0.weeks.0');
        $this->assertNotEmpty($week['day_off']);
        $this->assertContains($week['shift_id'], $this->shiftIds);
        $this->assertCount(6, collect($week['days'])->where('status', 'scheduled'));
        $this->assertCount(1, collect($week['days'])->where('status', 'day_off'));
        $this->assertDatabaseCount('schedule_day_offs', 0);
    }

    public function test_two_week_rotation_changes_the_employee_shift_between_weeks(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $this->payload('two_weeks'))
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 12)
            ->assertJsonPath('data.day_off_count', 2);

        $weeks = $response->json('data.rows.0.weeks');
        $this->assertCount(2, $weeks);
        $this->assertNotSame($weeks[0]['shift_id'], $weeks[1]['shift_id']);
        $this->assertSame(7.0, Carbon::parse($weeks[0]['day_off'])->diffInDays(Carbon::parse($weeks[1]['day_off'])));
    }

    public function test_custom_ai_mix_keeps_a_stable_shift_while_applying_two_days_off(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $payload = $this->payload('two_weeks');
        $payload['schedule_method'] = 'custom';
        $payload['days_off_per_week'] = 2;

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 10)
            ->assertJsonPath('data.day_off_count', 4);

        $weeks = $response->json('data.rows.0.weeks');
        $this->assertSame($weeks[0]['shift_id'], $weeks[1]['shift_id']);
        $this->assertCount(2, $weeks[0]['day_offs']);
        $this->assertCount(2, $weeks[1]['day_offs']);
    }

    public function test_ai_preview_blocks_assignments_over_the_weekly_hours_rule(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $payload = $this->payload();
        $payload['max_hours_per_week'] = 16;
        $payload['overtime_allowed'] = false;
        // One whole scheduling week (Sunday to Saturday), so the 16-hour cap
        // applies to all seven days at once.
        $payload['period_start'] = '2027-10-31';

        $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 2)
            ->assertJsonPath('data.skipped_count', 4)
            ->assertJsonPath('data.validation_summary.Maximum weekly hours exceeded', 4);
    }

    public function test_ai_preview_protects_holiday_dates_separately_from_days_off(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $payload = $this->payload();
        $payload['holiday_dates_csv'] = '2027-11-02';

        $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 5)
            ->assertJsonPath('data.day_off_count', 1)
            ->assertJsonPath('data.validation_summary.Holiday or closure date', 1);
    }

    public function test_rotation_balances_department_coverage_and_avoids_repeating_when_possible(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $employees = Employee::query()
            ->where('department_id', $this->employee->department_id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->take(2)
            ->get();
        $payload = $this->payload('two_weeks');
        $payload['employee_ids'] = $employees->pluck('id')->all();

        $response = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk();
        $rows = $response->json('data.rows');

        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]['weeks'][0]['shift_id'], $rows[1]['weeks'][0]['shift_id']);
        $this->assertNotSame($rows[0]['weeks'][0]['shift_id'], $rows[0]['weeks'][1]['shift_id']);
        $this->assertNotSame($rows[1]['weeks'][0]['shift_id'], $rows[1]['weeks'][1]['shift_id']);
    }

    public function test_weekly_rotation_has_one_day_off_even_when_period_starts_midweek(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        $payload = $this->payload();
        $payload['period_start'] = '2027-11-03';

        $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.rows.0.weeks')
            ->assertJsonPath('data.assignment_count', 6)
            ->assertJsonPath('data.day_off_count', 1);
    }

    public function test_reviewed_rotation_saves_assignments_and_explicit_day_offs_atomically(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);

        $payload = $this->payload('two_weeks');
        // A two-shift rotation only clears the Tier A coverage gate with a team
        // behind it: each shift has to keep its minimum on duty every day,
        // including the changeover day the rotation rests the incoming staff
        // over. So the assistant is asked for the whole section's roster, and
        // this test then follows one employee through it.
        $payload['employee_ids'] = Employee::query()
            ->where('department_id', $this->employee->department_id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        // The reviewer publishes the roster the assistant proposed, rather than
        // the server rebuilding it at save time.
        $suggested = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $payload)
            ->assertOk();

        $entries = $suggested->json('data.entries');
        $dates = collect($entries)->pluck('work_date');

        $this->actingAs($this->manager)
            ->post(route('schedules.roster.publish'), [
                'department_id' => $payload['department_id'],
                'start_date' => $dates->min(),
                'end_date' => $dates->max(),
                // The real roster board always scopes this to the shifts it
                // shows; without it every other active shift in the system is
                // graded for coverage across this date range too.
                'shift_ids' => $this->shiftIds,
                'entries' => $entries,
            ])
            ->assertSessionHasNoErrors();

        // The section is an administrative unit, and those run Monday-Saturday:
        // the fortnight's two Sundays are the unit's own rest days, so all
        // twelve working days are written and neither Sunday is staffed.
        $this->assertSame(12, ScheduleAssignment::query()
            ->where('employee_id', $this->employee->id)
            ->whereDate('work_date', '>=', '2027-11-01')
            ->whereDate('work_date', '<=', '2027-11-14')
            ->count());
        $this->assertDatabaseMissing('schedule_assignments', [
            'employee_id' => $this->employee->id,
            'work_date' => '2027-11-07',
        ]);

        // Two rest days, and both of them the Sundays -- not a weekday the
        // rotation spent on top of a Sunday it was never going to staff.
        $this->assertSame(
            ['2027-11-07', '2027-11-14'],
            ScheduleDayOff::query()
                ->where('employee_id', $this->employee->id)
                ->whereDate('work_date', '>=', '2027-11-01')
                ->whereDate('work_date', '<=', '2027-11-14')
                ->orderBy('work_date')
                ->pluck('work_date')
                ->map(fn ($date) => $date->toDateString())
                ->all(),
        );
    }

    /**
     * The standing Sunday rest day belongs to the department, not to the fill
     * path: a plain bulk fill never places one, so the roster has to supply it
     * or an administrative employee is published with no rest day at all.
     */
    public function test_a_bulk_filled_administrative_roster_still_gets_its_sunday_rest_day(): void
    {
        $shift = Shift::query()->findOrFail($this->shiftIds[0]);
        $employeeIds = Employee::query()
            ->where('department_id', $this->employee->department_id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->pluck('id')
            ->all();

        $filled = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.fill'), [
                'department_id' => $this->employee->department_id,
                'employee_ids' => $employeeIds,
                'shift_id' => $shift->id,
                'start_date' => '2027-11-01',
                'end_date' => '2027-11-07',
                'include_weekends' => true,
            ])
            ->assertOk();

        // The fill itself proposes six days: Sunday is not a day this unit
        // staffs, even with weekends included.
        $entries = collect($filled->json('data.entries'));
        $this->assertFalse($entries->contains('work_date', '2027-11-07'));

        // The board nonetheless shows the Sunday, as the unit's rest day for
        // everyone on it, rather than as a blank column.
        $sunday = collect($filled->json('data.evaluation.days'))->firstWhere('date', '2027-11-07');
        $this->assertTrue($sunday['is_standing_rest_day']);
        $this->assertSame([], $sunday['shifts']);
        $this->assertCount(count($employeeIds), $sunday['day_offs']);

        $this->actingAs($this->manager)
            ->post(route('schedules.roster.publish'), [
                'department_id' => $this->employee->department_id,
                'start_date' => '2027-11-01',
                'end_date' => '2027-11-07',
                'shift_ids' => [$shift->id],
                'entries' => $entries->all(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('schedule_day_offs', [
            'employee_id' => $this->employee->id,
            'work_date' => '2027-11-07 00:00:00',
            'source' => 'standing_rest_day',
        ]);
    }

    /**
     * The same rule, from the other side: a clinical unit runs every day, so
     * nothing may impose a Sunday on it. Its rest days stay rotated.
     */
    public function test_a_clinical_roster_is_given_no_standing_sunday_rest_day(): void
    {
        $department = Department::query()
            ->where('category', Department::CATEGORY_CLINICAL)
            ->whereHas('employees', fn ($query) => $query->where('employment_status', 'active'))
            ->firstOrFail();
        $shift = Shift::query()->findOrFail($this->shiftIds[0]);
        $employeeIds = Employee::query()
            ->where('department_id', $department->id)
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->take(3)
            ->pluck('id')
            ->all();

        // Nov 2-7, not Nov 1-7: a seventh consecutive workday is refused by the
        // consecutive-workday cap, which would leave the Sunday unfilled for a
        // reason that has nothing to do with the rule under test.
        $filled = $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.fill'), [
                'department_id' => $department->id,
                'employee_ids' => $employeeIds,
                'shift_id' => $shift->id,
                'start_date' => '2027-11-02',
                'end_date' => '2027-11-07',
                'include_weekends' => true,
            ])
            ->assertOk();

        $sunday = collect($filled->json('data.evaluation.days'))->firstWhere('date', '2027-11-07');
        $this->assertFalse($sunday['is_standing_rest_day']);
        $this->assertSame([], $sunday['day_offs'], 'A clinical unit is given no standing Sunday rest day.');
        $this->assertNotSame([], $sunday['shifts'], 'Sunday stays an ordinary working day for a clinical unit.');
        $this->assertTrue(collect($filled->json('data.entries'))->contains('work_date', '2027-11-07'));
    }

    public function test_manual_assignment_cannot_override_a_generated_day_off(): void
    {
        $shift = Shift::query()->findOrFail($this->shiftIds[0]);
        ScheduleDayOff::query()->create([
            'employee_id' => $this->employee->id,
            'work_date' => '2027-11-03',
            'source' => 'ai_rotation',
            'created_by' => $this->manager->id,
        ]);

        $this->actingAs($this->manager)->post(route('schedules.store'), [
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-11-03',
        ])->assertSessionHasErrors('schedule');
    }

    public function test_rotation_skips_approved_leave_and_keeps_a_separate_weekly_day_off(): void
    {
        config(['ai_workforce_scheduling.enabled' => true]);
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $this->employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => '2027-11-02',
            'end_date' => '2027-11-02',
            'requested_days' => 1,
            'reason' => 'Approved leave during rotation test.',
            'status' => 'approved',
        ]);

        $this->actingAs($this->manager)
            ->postJson(route('schedules.roster.suggest'), $this->payload())
            ->assertOk()
            ->assertJsonPath('data.assignment_count', 5)
            ->assertJsonPath('data.day_off_count', 1)
            ->assertJsonPath('data.skipped_count', 1)
            ->assertJsonPath('data.skipped.0.reason', 'Approved leave');
    }

    /** @return array<string, mixed> */
    private function payload(string $period = 'weekly'): array
    {
        return [
            'department_id' => $this->employee->department_id,
            'employee_ids' => [$this->employee->id],
            'shift_ids' => $this->shiftIds,
            'schedule_period' => $period,
            'period_start' => '2027-11-01',
        ];
    }
}

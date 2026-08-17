<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
use App\Services\Scheduling\RotationScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things a roster form has to get right about shifts: how many of them a
 * run needs, and how many people may stand on one.
 *
 * Morning, Afternoon and Night tile a 24-hour day, so rostering one alone
 * leaves the rest of it uncovered — the assistant asks for a second. The
 * Administrative Shift is a standalone 8-to-5 office day with no second leg to
 * pair it with. And whichever is chosen, the roster form's figure is a ceiling:
 * it stops the assistant piling every eligible name onto one shift, without
 * being able to redefine what counts as adequate cover.
 */
class ShiftPoolAndCapacityTest extends TestCase
{
    use RefreshDatabase;

    private Department $ward;

    private Position $position;

    private Shift $administrative;

    private Shift $morning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        // The pool lives on the assistant's step of the roster form, so its
        // endpoint only exists when the assistant is switched on.
        config(['ai_workforce_scheduling.enabled' => true]);

        $this->administrative = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->ward = Department::query()->create([
            'code' => 'POOL-WARD',
            'name' => 'Shift Pool Ward',
            'category' => Department::CATEGORY_CLINICAL,
            'is_active' => true,
        ]);

        $this->position = Position::query()->create([
            'department_id' => $this->ward->id,
            'code' => 'POOL-NURSE',
            'title' => 'Pool Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);
    }

    private function employee(string $number): Employee
    {
        $user = User::query()->create([
            'name' => 'Pool '.$number,
            'email' => strtolower($number).'@hrms.local',
            'password' => 'password-for-testing-only',
            'is_active' => true,
        ]);

        return Employee::query()->create([
            'user_id' => $user->id,
            'department_id' => $this->ward->id,
            'position_id' => $this->position->id,
            'employee_number' => $number,
            'first_name' => 'Pool',
            'last_name' => $number,
            'employment_status' => 'active',
            'hire_date' => '2026-01-05',
        ]);
    }

    public function test_the_standard_templates_declare_whether_they_rotate(): void
    {
        $this->assertFalse($this->administrative->is_rotating, 'An 8-to-5 office day stands alone.');

        foreach (['MORNING-0600', 'AFTERNOON-1400', 'NIGHT-2200'] as $code) {
            $this->assertTrue(
                Shift::query()->where('code', $code)->value('is_rotating'),
                "{$code} is one leg of a round-the-clock rotation.",
            );
        }
    }

    public function test_a_standalone_shift_may_be_rostered_on_its_own(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->postJson(route('schedules.roster.suggest'), $this->rotationPayload([$this->administrative->id]))
            ->assertOk();
    }

    public function test_a_rotating_shift_alone_is_refused_because_it_covers_part_of_a_day(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->postJson(route('schedules.roster.suggest'), $this->rotationPayload([$this->morning->id]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.shift_ids.0',
                'Select at least two shifts — a rotating shift covers only part of the day.',
            );
    }

    /**
     * The conflict this prevents: an 8-to-5 office day already fills the day it
     * covers, so pairing it with a Night leg asks one team to work two patterns
     * that cannot both hold.
     */
    public function test_a_standalone_shift_cannot_be_combined_with_a_rotating_one(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->postJson(route('schedules.roster.suggest'), $this->rotationPayload([
                $this->administrative->id,
                Shift::query()->where('code', 'NIGHT-2200')->value('id'),
            ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.shift_ids.0',
                'A standalone shift cannot be combined with a rotating one. Choose either the standalone shift on its own, or two or more rotating shifts.',
            );
    }

    public function test_two_standalone_shifts_cannot_be_pooled_together(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $secondOffice = Shift::query()->create([
            'code' => 'OFFICE-0900',
            'name' => 'Late Office Shift',
            'start_time' => '09:00',
            'end_time' => '18:00',
            'break_minutes' => 60,
            'color' => '#176B43',
            'is_active' => true,
            'is_rotating' => false,
        ]);

        $this->actingAs($manager)
            ->postJson(route('schedules.roster.suggest'), $this->rotationPayload([
                $this->administrative->id,
                $secondOffice->id,
            ]))
            ->assertStatus(422)
            ->assertJsonPath(
                'errors.shift_ids.0',
                'A standalone shift covers a full working day on its own, so only one may be chosen.',
            );
    }

    public function test_two_rotating_shifts_are_accepted(): void
    {
        $manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($manager)
            ->postJson(route('schedules.roster.suggest'), $this->rotationPayload([
                $this->morning->id,
                Shift::query()->where('code', 'NIGHT-2200')->value('id'),
            ]))
            ->assertOk();
    }

    /**
     * The overshoot this was raised for: every eligible name landing on one
     * shift because nothing said how many were too many.
     */
    public function test_a_bulk_fill_never_puts_more_than_the_maximum_on_one_shift(): void
    {
        $team = collect(['PW-0001', 'PW-0002', 'PW-0003', 'PW-0004', 'PW-0005'])
            ->map(fn (string $number) => $this->employee($number));

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => $team->pluck('id')->all(),
            'shift_id' => $this->administrative->id,
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-01',
            'maximum_staff_per_shift' => 2,
        ]);

        $this->assertCount(2, $plan['ready'], 'The ceiling was exceeded.');
        $this->assertCount(3, $plan['skipped']);
        $this->assertStringContainsString('maximum of 2 staff', $plan['skipped']->first()['reason']);
    }

    public function test_a_bulk_fill_without_a_maximum_still_places_everyone(): void
    {
        $team = collect(['PW-0006', 'PW-0007', 'PW-0008'])
            ->map(fn (string $number) => $this->employee($number));

        $plan = app(ScheduleService::class)->bulkAssignmentPlan([
            'department_id' => $this->ward->id,
            'employee_ids' => $team->pluck('id')->all(),
            'shift_id' => $this->administrative->id,
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-01',
        ]);

        $this->assertCount(3, $plan['ready']);
    }

    public function test_the_assistant_also_holds_the_maximum_per_shift_and_date(): void
    {
        $team = collect(['PW-0009', 'PW-0010', 'PW-0011', 'PW-0012', 'PW-0013', 'PW-0014'])
            ->map(fn (string $number) => $this->employee($number));

        $plan = app(RotationScheduleService::class)->plan([
            'department_id' => $this->ward->id,
            'employee_ids' => $team->pluck('id')->all(),
            'shift_ids' => [$this->administrative->id],
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-07',
            'schedule_method' => 'rotation',
            'maximum_staff_per_shift' => 2,
        ]);

        $perShiftDate = $plan['ready_assignments']
            ->groupBy(fn (array $item) => $item['shift']->id.'|'.$item['date']->toDateString())
            ->map->count();

        $this->assertNotEmpty($perShiftDate, 'The assistant produced nothing to check.');
        $this->assertLessThanOrEqual(2, $perShiftDate->max(), 'A shift went over its ceiling.');
    }

    /**
     * @param  list<int>  $shiftIds
     * @return array<string, mixed>
     */
    private function rotationPayload(array $shiftIds): array
    {
        $employee = $this->employee('PP-'.substr(md5(implode(',', $shiftIds)), 0, 4));

        return [
            'department_id' => $this->ward->id,
            'employee_ids' => [$employee->id],
            'shift_ids' => $shiftIds,
            'schedule_method' => 'rotation',
            'schedule_period' => 'weekly',
            'period_start' => '2027-03-01',
            'start_date' => '2027-03-01',
            'end_date' => '2027-03-07',
        ];
    }
}

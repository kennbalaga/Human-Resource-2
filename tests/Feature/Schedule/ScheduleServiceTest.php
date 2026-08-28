<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private ScheduleService $service;

    private Employee $employee;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->service = app(ScheduleService::class);
        $this->employee = Employee::query()->where('employee_number', 'HR-OFFICER-2026-0001')->firstOrFail();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_it_creates_a_non_conflicting_assignment(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $assignment = $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-01-11',
            'notes' => 'Coverage test',
        ], $this->manager);

        $this->assertSame('scheduled', $assignment->status);
        $this->assertSame('Coverage test', $assignment->notes);
        $this->assertDatabaseHas('schedule_assignments', [
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-01-11',
        ]);
    }

    public function test_it_rejects_overlapping_shifts(): void
    {
        $dayShift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $overlapShift = Shift::query()->create([
            'code' => 'MID-1200',
            'name' => 'Mid Shift',
            'start_time' => '12:00',
            'end_time' => '20:00',
            'break_minutes' => 60,
            'color' => '#D97706',
            'is_active' => true,
        ]);

        $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $dayShift->id,
            'work_date' => '2027-01-12',
        ], $this->manager);

        $this->expectException(ValidationException::class);

        $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $overlapShift->id,
            'work_date' => '2027-01-12',
        ], $this->manager);
    }

    public function test_overnight_shift_conflicts_with_next_morning(): void
    {
        $nightShift = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $morningShift = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $nightShift->id,
            'work_date' => '2027-01-13',
        ], $this->manager);

        $conflicts = $this->service->conflictsFor(
            $this->employee,
            $morningShift,
            '2027-01-14',
        );

        $this->assertCount(0, $conflicts, 'A shift ending exactly at 7:00 AM may be followed by one starting at 7:00 AM.');
        $this->assertCount(1, $this->service->restConflictsFor(
            $this->employee,
            $morningShift,
            '2027-01-14',
        ), 'Back-to-back shifts must still be flagged by the configurable minimum-rest rule.');

        $overlapShift = Shift::query()->create([
            'code' => 'EARLY-0500',
            'name' => 'Early Shift',
            'start_time' => '05:00',
            'end_time' => '13:00',
            'break_minutes' => 60,
            'color' => '#0F766E',
            'is_active' => true,
        ]);

        $this->assertCount(1, $this->service->conflictsFor($this->employee, $overlapShift, '2027-01-14'));
    }

    public function test_weekly_recurrence_expands_only_selected_weekdays(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();

        $series = $this->service->createRecurringSchedule([
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'start_date' => '2027-02-01',
            'end_date' => '2027-02-14',
            'recurrence_type' => 'weekly',
            'weekdays' => [1, 3, 5],
            'interval_weeks' => 1,
            'notes' => null,
        ], $this->manager);

        $dates = ScheduleAssignment::query()
            ->where('recurring_schedule_id', $series->id)
            ->orderBy('work_date')
            ->pluck('work_date')
            ->map->toDateString()
            ->all();

        $this->assertSame([
            '2027-02-01', '2027-02-03', '2027-02-05',
            '2027-02-08', '2027-02-10', '2027-02-12',
        ], $dates);
    }

    public function test_it_rejects_an_assignment_on_a_date_that_has_already_started(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $today = now(config('schedule.timezone'))->startOfDay();
        $before = ScheduleAssignment::query()->count();

        foreach ([$today, $today->copy()->subDay(), $today->copy()->subWeek()] as $date) {
            try {
                $this->service->createAssignment([
                    'employee_id' => $this->employee->id,
                    'shift_id' => $shift->id,
                    'work_date' => $date->toDateString(),
                ], $this->manager);
                $this->fail("An assignment on {$date->toDateString()} should have been rejected.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('work_date', $exception->errors());
            }
        }

        $this->assertDatabaseCount('schedule_assignments', $before);
    }

    public function test_it_rejects_moving_an_assignment_off_a_date_that_has_already_started(): void
    {
        $today = now(config('schedule.timezone'))->startOfDay();

        // A roster published before today still holds today's assignments; the
        // service just may no longer move them. The assignment is written here
        // rather than fished out of the seed: the seeded roster only runs
        // Monday to Friday of the current week, so on a weekend there was no
        // row dated today and the lookup failed the test on the calendar
        // instead of on the rule it exists to check.
        $assignment = $this->assignmentOn($today);

        $this->expectException(ValidationException::class);

        $this->service->updateAssignment($assignment, [
            'employee_id' => $assignment->employee_id,
            'shift_id' => $assignment->shift_id,
            'work_date' => $today->copy()->addMonths(6)->toDateString(),
        ], $this->manager);
    }

    public function test_it_allows_an_assignment_on_an_upcoming_date(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $today = now(config('schedule.timezone'))->startOfDay();
        $upcoming = $today->copy()->addMonths(6);

        $assignment = $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'work_date' => $upcoming->toDateString(),
        ], $this->manager);

        $this->assertTrue($assignment->exists);
        $this->assertTrue($this->service->isDateEditable($today->copy()->addDay()));
        $this->assertFalse($this->service->isDateEditable($today));
        $this->assertFalse($this->service->isDateEditable($today->copy()->subDay()));
    }

    public function test_recurring_creation_is_atomic_when_one_date_conflicts(): void
    {
        $shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $this->service->createAssignment([
            'employee_id' => $this->employee->id,
            'shift_id' => $shift->id,
            'work_date' => '2027-03-03',
        ], $this->manager);

        try {
            $this->service->createRecurringSchedule([
                'employee_id' => $this->employee->id,
                'shift_id' => $shift->id,
                'start_date' => '2027-03-01',
                'end_date' => '2027-03-05',
                'recurrence_type' => 'daily',
                'weekdays' => [],
                'interval_weeks' => 1,
                'notes' => null,
            ], $this->manager);
            $this->fail('A validation exception should have been thrown.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('recurring_schedules', 0);
            $this->assertDatabaseCount('schedule_assignments', 21);
        }
    }

    /**
     * Write an assignment on a given date through the same unattended-write
     * guard the seeder uses, so a test depending on "today" does not depend on
     * which weekday it happens to run.
     */
    private function assignmentOn(CarbonInterface $date): ScheduleAssignment
    {
        $shift = Shift::query()->where('is_active', true)->firstOrFail();
        $employee = Employee::query()
            ->where('employment_status', 'active')
            ->whereDoesntHave('scheduleAssignments', fn ($query) => $query
                ->whereBetween('work_date', [$date->copy()->subDay(), $date->copy()->addDay()]))
            ->firstOrFail();

        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
        ]));
    }
}

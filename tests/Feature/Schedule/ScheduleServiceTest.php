<?php

namespace Tests\Feature\Schedule;

use App\Models\Employee;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\ScheduleService;
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
        $this->employee = Employee::query()->where('employee_number', 'HR-0002')->firstOrFail();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_it_creates_a_non_conflicting_assignment(): void
    {
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();

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
            'work_date' => '2027-01-11 00:00:00',
        ]);
    }

    public function test_it_rejects_overlapping_shifts(): void
    {
        $dayShift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
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
        $nightShift = Shift::query()->where('code', 'NIGHT-2300')->firstOrFail();
        $morningShift = Shift::query()->where('code', 'AM-0700')->firstOrFail();

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
            'code' => 'EARLY-0600',
            'name' => 'Early Shift',
            'start_time' => '06:00',
            'end_time' => '14:00',
            'break_minutes' => 60,
            'color' => '#0F766E',
            'is_active' => true,
        ]);

        $this->assertCount(1, $this->service->conflictsFor($this->employee, $overlapShift, '2027-01-14'));
    }

    public function test_weekly_recurrence_expands_only_selected_weekdays(): void
    {
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();

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

    public function test_recurring_creation_is_atomic_when_one_date_conflicts(): void
    {
        $shift = Shift::query()->where('code', 'DAY-0800')->firstOrFail();
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
}

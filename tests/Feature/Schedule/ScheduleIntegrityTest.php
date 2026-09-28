<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignmentAudit;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\EmployeeEligibilityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The rules every roster path already applied through
 * bulkAssignmentBlockReason() -- leave, the edit window, the audit trail --
 * held to the paths that had been missing them: a hand-made assignment, a
 * recurring series, and the AI recommender.
 */
class ScheduleIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->shift = Shift::query()->where('is_active', true)->firstOrFail();
    }

    public function test_a_hand_made_assignment_is_refused_on_approved_leave(): void
    {
        $employee = $this->employee();
        $date = now(config('schedule.timezone'))->addDays(21)->toDateString();
        $this->approvedLeave($employee, $date);

        $this->actingAs($this->manager)
            ->postJson(route('schedules.conflicts'), ['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'work_date' => $date])
            ->assertOk()
            ->assertJsonPath('has_conflicts', true)
            ->assertJsonPath('leave.start_date', $date);

        $this->actingAs($this->manager)
            ->post(route('schedules.store'), ['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'work_date' => $date])
            ->assertSessionHasErrors('schedule');

        $this->assertDatabaseMissing('schedule_assignments', ['employee_id' => $employee->id]);
    }

    public function test_a_recurring_series_is_refused_when_it_crosses_approved_leave(): void
    {
        $employee = $this->employee();
        $start = now(config('schedule.timezone'))->addDays(10);
        $this->approvedLeave($employee, $start->copy()->addDays(2)->toDateString());

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $this->series($employee, $start, $start->copy()->addDays(5)))
            ->assertSessionHasErrors('schedule');

        $this->assertDatabaseMissing('schedule_assignments', ['employee_id' => $employee->id]);
    }

    public function test_a_recurring_series_cannot_start_on_a_day_that_has_already_begun(): void
    {
        $employee = $this->employee();
        $today = now(config('schedule.timezone'));

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $this->series($employee, $today->copy()->subDays(5), $today->copy()->addDays(3)))
            ->assertSessionHasErrors('start_date');

        $this->assertDatabaseMissing('schedule_assignments', ['employee_id' => $employee->id]);
    }

    public function test_cancelling_a_series_keeps_todays_shift_and_audits_every_removal(): void
    {
        $employee = $this->employee();
        $tomorrow = now(config('schedule.timezone'))->addDay();

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $this->series($employee, $tomorrow, $tomorrow->copy()->addDays(3)))
            ->assertSessionHasNoErrors();
        $series = RecurringSchedule::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(4, $series->assignments()->count());

        // Tomorrow's shift is today's by the time the series is cancelled.
        $this->travelTo(Carbon::parse($tomorrow->toDateString().' 09:00:00', config('schedule.timezone')));

        $this->actingAs($this->manager)->delete(route('recurring-schedules.destroy', $series))->assertSessionHasNoErrors();

        $this->assertSame(1, $series->assignments()->count(), 'Only the shift already under way remains.');
        $this->assertSame(3, ScheduleAssignmentAudit::query()->where('employee_id', $employee->id)->where('action', 'deleted')->count());
    }

    public function test_the_ai_recommender_does_not_offer_someone_on_a_scheduled_day_off(): void
    {
        $employee = $this->employee();
        $date = now(config('schedule.timezone'))->addDays(25)->toDateString();
        ScheduleDayOff::query()->create(['employee_id' => $employee->id, 'work_date' => $date, 'source' => 'manual', 'created_by' => $this->manager->id]);

        $result = app(EmployeeEligibilityService::class)->evaluateCandidate(
            $employee->fresh(['department', 'position']),
            $employee->department,
            $employee->position,
            $this->shift,
            $date,
        );

        $this->assertFalse($result['eligible']);
        $this->assertContains('scheduled_day_off', array_column($result['reasons'], 'code'));
    }

    private function employee(): Employee
    {
        $department = Department::query()->where('code', 'HR')->firstOrFail();

        return Employee::query()->create([
            'department_id' => $department->id,
            'position_id' => Position::query()->where('department_id', $department->id)->firstOrFail()->id,
            'employee_number' => 'SIT-'.Str::random(8),
            'first_name' => 'Schedule',
            'last_name' => 'Integrity',
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function approvedLeave(Employee $employee, string $date): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => $date,
            'end_date' => $date,
            'requested_days' => 1,
            'reason' => 'Approved leave fixture.',
            'status' => 'approved',
        ]);
    }

    /** @return array<string, mixed> */
    private function series(Employee $employee, Carbon $start, Carbon $end): array
    {
        return [
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'recurrence_type' => 'daily',
            'interval_weeks' => 1,
        ];
    }
}

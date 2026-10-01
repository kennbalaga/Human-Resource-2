<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\RecurringSchedule;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterDraftService;
use App\Support\ScheduleWeek;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The recurring-schedule preview, the skip-conflicts create, the standing
 * cover read by the assignment form, and the Step 4 labor-compliance rows.
 */
class RecurringPreviewAndCoverageTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Shift $shift;

    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Notification::fake();

        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->shift = Shift::query()->where('code', 'ADMIN-0800')->firstOrFail();
        $this->department = Department::query()->where('code', 'HR')->firstOrFail();
    }

    public function test_the_preview_lists_created_and_skipped_dates_without_writing_anything(): void
    {
        $employee = $this->employee();
        [$start, $end] = $this->range();
        $leaveDay = $start->copy()->addDays(2)->toDateString();
        $this->approvedLeave($employee, $leaveDay);

        $response = $this->actingAs($this->manager)
            ->postJson(route('recurring-schedules.preview'), $this->series($employee, $start, $end))
            ->assertOk()
            ->assertJsonPath('summary.generated', 5)
            ->assertJsonPath('summary.create', 4)
            ->assertJsonPath('summary.skipped', 1)
            ->assertJsonPath('limits.week_starts_on', 0);

        $skipped = collect($response->json('dates'))->firstWhere('status', 'skipped');
        $this->assertSame($leaveDay, $skipped['date']);
        $this->assertSame('leave', $skipped['kind']);
        $this->assertDatabaseMissing('schedule_assignments', ['employee_id' => $employee->id]);
        $this->assertDatabaseCount('recurring_schedules', 0);
    }

    public function test_a_series_with_a_clash_is_still_refused_unless_skipping_is_asked_for(): void
    {
        $employee = $this->employee();
        [$start, $end] = $this->range();
        $this->approvedLeave($employee, $start->copy()->addDays(2)->toDateString());

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $this->series($employee, $start, $end))
            ->assertSessionHasErrors('schedule');
        $this->assertDatabaseCount('recurring_schedules', 0);

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $this->series($employee, $start, $end) + ['skip_conflicts' => 1])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('schedule_confirmation', fn (array $confirmation) => $confirmation['title'] === 'Recurring schedule created'
                && collect($confirmation['rows'])->contains(fn (array $row) => $row[0] === 'Skipped'));

        $series = RecurringSchedule::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(4, $series->assignments()->count());
        $this->assertDatabaseMissing('schedule_assignments', [
            'employee_id' => $employee->id,
            'work_date' => $start->copy()->addDays(2)->toDateString(),
        ]);
    }

    public function test_a_weekly_series_posted_from_the_form_generates_its_dates(): void
    {
        // A browser form sends every value as a string, weekdays included.
        $employee = $this->employee();
        [$start, $end] = $this->range();
        $payload = $this->series($employee, $start, $end);
        $payload['weekdays'] = ['1', '3', '5'];
        $payload['interval_weeks'] = '1';

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $payload)
            ->assertSessionHasNoErrors();

        $series = RecurringSchedule::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(3, $series->assignments()->count());
        $this->assertSame([1, 3, 5], $series->weekdays);
    }

    public function test_the_assignment_form_reads_the_cover_already_on_a_shift(): void
    {
        $date = Carbon::now(config('schedule.timezone'))->addDays(12)->toDateString();
        $standing = $this->employee();
        ScheduleAssignment::withoutEvents(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $standing->id,
            'shift_id' => $this->shift->id,
            'work_date' => $date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));

        $this->actingAs($this->manager)
            ->getJson(route('schedules.coverage', ['department_id' => $this->department->id, 'shift_id' => $this->shift->id, 'work_date' => $date]))
            ->assertOk()
            ->assertJsonPath('staffed', 1)
            ->assertJsonPath('names.0', $standing->full_name);
    }

    public function test_a_hand_made_assignment_returns_a_confirmation_read_back(): void
    {
        $employee = $this->employee();
        $date = Carbon::now(config('schedule.timezone'))->addDays(15)->toDateString();

        $this->actingAs($this->manager)
            ->post(route('schedules.store'), ['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'work_date' => $date])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('schedule_confirmation', fn (array $confirmation) => $confirmation['title'] === 'Shift assigned'
                // The hours this shift puts on the week are read back with it.
                && collect($confirmation['rows'])->contains(fn (array $row) => $row[0] === 'Workload'
                    && str_contains($row[1], 'of '.config('schedule.compliance.max_hours_per_week').' paid hours this week')));
    }

    public function test_the_availability_check_reads_back_the_hours_already_on_that_week(): void
    {
        $employee = $this->employee();
        $date = Carbon::now(config('schedule.timezone'))->addWeeks(4)->next(Carbon::WEDNESDAY);
        ScheduleAssignment::withoutEvents(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            // The Monday of the same Sunday-to-Saturday week.
            'work_date' => $date->copy()->subDays(2)->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));
        $shiftHours = round($this->shift->duration_minutes / 60, 1);

        $this->actingAs($this->manager)
            ->postJson(route('schedules.conflicts'), [
                'employee_id' => $employee->id,
                'shift_id' => $this->shift->id,
                'work_date' => $date->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('has_conflicts', false)
            ->assertJsonPath('week.start', ScheduleWeek::start($date)->toDateString())
            ->assertJsonPath('week.scheduled_hours', fn (int|float $value) => (float) $value === $shiftHours)
            ->assertJsonPath('week.after_hours', fn (int|float $value) => (float) $value === round($shiftHours * 2, 1))
            ->assertJsonPath('week.limit', (int) config('schedule.compliance.max_hours_per_week'));
    }

    public function test_a_weekly_repeat_started_from_the_assignment_form_reads_back_as_one(): void
    {
        $employee = $this->employee();
        [$start, $end] = $this->range();
        $payload = $this->series($employee, $start, $end);
        $payload['weekdays'] = [$start->dayOfWeekIso];
        $payload['origin'] = 'assignment';

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('schedule_confirmation', fn (array $confirmation) => $confirmation['title'] === 'Weekly shift assigned'
                && $confirmation['again']['target'] === '#scheduleAssignmentModal');
    }

    public function test_step_one_reads_what_the_period_already_holds_for_each_employee(): void
    {
        $employee = $this->employee();
        $start = Carbon::now(config('schedule.timezone'))->addWeeks(5)->startOfWeek(Carbon::SUNDAY);
        $end = $start->copy()->addDays(6);
        ScheduleAssignment::withoutEvents(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'work_date' => $start->copy()->addDay()->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));
        // Two days of the leave fall inside the period; the third does not.
        $this->approvedLeaveRange($employee, $end->copy()->subDay()->toDateString(), $end->copy()->addDay()->toDateString());

        $this->actingAs($this->manager)
            ->getJson(route('schedules.staff-load', [
                'department_id' => $this->department->id,
                'start_date' => $start->copy()->startOfDay()->toDateTimeString(),
                'end_date' => $end->toDateString(),
            ]))
            ->assertOk()
            ->assertJsonPath("employees.{$employee->id}.shifts", 1)
            ->assertJsonPath("employees.{$employee->id}.leave_days", 2)
            ->assertJsonPath("employees.{$employee->id}.days_off", 0);
    }

    public function test_the_roster_evaluation_reports_labor_compliance_by_article(): void
    {
        $employee = $this->employee();
        $date = Carbon::now(config('schedule.timezone'))->addDays(20)->toDateString();

        $evaluation = app(RosterDraftService::class)->evaluate($this->department, collect([
            ['employee_id' => $employee->id, 'shift_id' => $this->shift->id, 'work_date' => $date],
        ]), $date, $date, ['max_hours_per_week' => 48]);

        $rows = collect($evaluation['compliance'])->keyBy('article');
        $this->assertEqualsCanonicalizing(
            ['Labor Code Art. 83', 'Labor Code Art. 85', 'Labor Code Art. 86', 'Labor Code Art. 87', 'Labor Code Art. 91', 'Hospital policy'],
            $rows->keys()->all(),
        );
        $this->assertSame('ok', $rows['Labor Code Art. 87']['state']);
        $this->assertSame('pending', $rows['Hospital policy']['state']);
    }

    /** @return array{Carbon, Carbon} */
    /**
     * "After 10 shifts" is the other way a series ends. The count is taken
     * over the dates the pattern generates, so a clash later skipped does not
     * quietly lengthen the run to make the number up.
     */
    public function test_a_series_can_end_after_a_number_of_shifts_instead_of_on_a_date(): void
    {
        $employee = $this->employee();
        [$start] = $this->range();

        $payload = $this->series($employee, $start, $start->copy()->addDays(4));
        unset($payload['end_date']);
        $payload['end_mode'] = 'after';
        $payload['occurrences'] = 8;

        $this->actingAs($this->manager)
            ->postJson(route('recurring-schedules.preview'), $payload)
            ->assertOk()
            ->assertJsonPath('summary.generated', 8)
            ->assertJsonPath('summary.create', 8)
            // Eight weekdays from a Monday runs into the Wednesday of the
            // second week, not to the Friday the posted window named.
            ->assertJsonPath('range.end', $start->copy()->addDays(9)->toDateString());
    }

    public function test_a_counted_series_records_the_date_its_count_ran_out_on(): void
    {
        $employee = $this->employee();
        [$start] = $this->range();

        $payload = $this->series($employee, $start, $start->copy()->addDays(4));
        unset($payload['end_date']);
        $payload['end_mode'] = 'after';
        $payload['occurrences'] = 3;

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $payload)
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('recurring_schedules', [
            'employee_id' => $employee->id,
            'start_date' => $start->copy()->startOfDay()->toDateTimeString(),
            // Not start + the 180-day window the request filled in to satisfy
            // the range rules.
            'end_date' => $start->copy()->addDays(2)->startOfDay()->toDateTimeString(),
        ]);
        $this->assertSame(3, ScheduleAssignment::query()->where('employee_id', $employee->id)->count());
    }

    public function test_a_counted_series_must_say_how_many(): void
    {
        $employee = $this->employee();
        [$start] = $this->range();

        $payload = $this->series($employee, $start, $start->copy()->addDays(4));
        unset($payload['end_date']);
        $payload['end_mode'] = 'after';

        $this->actingAs($this->manager)
            ->post(route('recurring-schedules.store'), $payload)
            ->assertSessionHasErrors('occurrences');

        $this->assertDatabaseCount('recurring_schedules', 0);
    }

    /** The date-ended series everything sent before this option existed. */
    public function test_leaving_the_end_mode_out_still_reads_the_posted_end_date(): void
    {
        $employee = $this->employee();
        [$start, $end] = $this->range();

        $this->actingAs($this->manager)
            ->postJson(route('recurring-schedules.preview'), $this->series($employee, $start, $end))
            ->assertOk()
            ->assertJsonPath('summary.generated', 5)
            ->assertJsonPath('range.end', $end->toDateString());
    }

    private function range(): array
    {
        // A Monday-to-Friday run a few weeks out, well inside the edit window.
        $start = Carbon::now(config('schedule.timezone'))->addWeeks(3)->startOfWeek(Carbon::MONDAY);

        return [$start, $start->copy()->addDays(4)];
    }

    /** @return array<string, mixed> */
    private function series(Employee $employee, Carbon $start, Carbon $end): array
    {
        return [
            'employee_id' => $employee->id,
            'shift_id' => $this->shift->id,
            'start_date' => $start->copy()->startOfDay()->toDateTimeString(),
            'end_date' => $end->toDateString(),
            'recurrence_type' => 'weekly',
            'weekdays' => [1, 2, 3, 4, 5],
            'interval_weeks' => 1,
        ];
    }

    private function employee(): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->department->id,
            'position_id' => Position::query()->where('department_id', $this->department->id)->firstOrFail()->id,
            'employee_number' => 'RPC-'.Str::random(8),
            'first_name' => 'Recurring',
            'last_name' => 'Preview '.Str::random(4),
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function approvedLeave(Employee $employee, string $date): void
    {
        $this->approvedLeaveRange($employee, $date, $date);
    }

    private function approvedLeaveRange(Employee $employee, string $start, string $end): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => $start,
            'end_date' => $end,
            'requested_days' => 1,
            'reason' => 'Approved leave fixture.',
            'status' => 'approved',
        ]);
    }
}

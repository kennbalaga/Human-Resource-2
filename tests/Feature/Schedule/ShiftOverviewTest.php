<?php

namespace Tests\Feature\Schedule;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\ShiftOverviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShiftOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // The seeders ship a demo roster and demo leave. Counting tests need to own
        // every row on the day, so the slate is wiped and rebuilt per test.
        AttendanceRecord::query()->delete();
        ScheduleAssignment::query()->delete();
        ScheduleDayOff::query()->delete();
        LeaveRequest::query()->delete();
    }

    /**
     * The standalone shift panel is gone from the dashboard: this service now
     * feeds the schedule rail beside the attendance chart, where the day is a
     * timeline rather than a wall of pool cards. The full coverage view lives in
     * the schedules module the rail links out to.
     */
    public function test_dashboard_renders_todays_schedule_in_the_calendar_rail(): void
    {
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Today’s schedule', false)
            ->assertSee('schedule-timeline', false);

        foreach (['Morning Shift', 'Administrative Shift', 'Afternoon Shift', 'Night Shift'] as $shift) {
            $response->assertSee($shift);
        }

        // The head counts the pool cards used to spell out, in the one line the
        // timeline gives each shift. Only the count is asserted: whether the
        // absentee reads "missing" or "not yet in" depends on how far past 6am
        // the suite happens to run, and that rule has tests of its own below.
        $response->assertSee('0 of 1 clocked in');
    }

    public function test_rail_falls_back_to_an_empty_state_without_a_roster(): void
    {
        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Today’s schedule', false)
            ->assertSee('Nobody is rostered today')
            ->assertDontSee('schedule-timeline', false);
    }

    public function test_every_active_shift_becomes_a_pool_with_its_start_time(): void
    {
        $overview = app(ShiftOverviewService::class)->forToday();

        // Pools are ordered by start time, which is how the day actually runs.
        $this->assertSame(
            ['Morning Shift', 'Administrative Shift', 'Afternoon Shift', 'Night Shift'],
            array_column($overview['shifts'], 'name'),
        );
        $this->assertSame(
            ['6:00 AM', '8:00 AM', '2:00 PM', '10:00 PM'],
            array_column($overview['shifts'], 'start_time'),
        );
    }

    public function test_pool_splits_assigned_into_clocked_in_and_missing(): void
    {
        [$onDuty, $missing] = $this->employees(2);

        $this->roster($onDuty, 'MORNING-0600');
        $this->roster($missing, 'MORNING-0600');
        $this->recordAttendance($onDuty);

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(2, $pool['assigned']);
        $this->assertSame(1, $pool['clocked_in']);
        $this->assertSame(1, $pool['missing']);
        $this->assertSame(50.0, $pool['coverage']);
    }

    public function test_pools_are_kept_apart_by_shift(): void
    {
        [$morning, $night] = $this->employees(2);

        $this->roster($morning, 'MORNING-0600');
        $this->roster($night, 'NIGHT-2200');
        $this->recordAttendance($night);

        $this->assertSame(1, $this->poolFor('Morning Shift')['assigned']);
        $this->assertSame(0, $this->poolFor('Morning Shift')['clocked_in']);
        $this->assertSame(1, $this->poolFor('Night Shift')['clocked_in']);
        $this->assertSame(0, $this->poolFor('Night Shift')['missing']);
        // A shift nobody was rostered onto still shows up, reading empty.
        $this->assertSame(0, $this->poolFor('Afternoon Shift')['assigned']);
    }

    public function test_approved_leave_is_not_counted_missing(): void
    {
        $employee = $this->firstEmployee();

        $this->roster($employee, 'MORNING-0600');
        $this->approveLeave($employee);

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(1, $pool['assigned']);
        $this->assertSame(1, $pool['on_leave']);
        $this->assertSame(0, $pool['missing']);
    }

    public function test_a_check_in_outranks_an_approved_leave_request(): void
    {
        $employee = $this->firstEmployee();

        $this->roster($employee, 'MORNING-0600');
        $this->approveLeave($employee);
        $this->recordAttendance($employee);

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(1, $pool['clocked_in']);
        $this->assertSame(0, $pool['on_leave']);
    }

    public function test_a_day_off_drops_the_employee_out_of_the_assigned_pool(): void
    {
        $employee = $this->firstEmployee();

        $this->roster($employee, 'MORNING-0600');
        ScheduleDayOff::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $this->today()->toDateString(),
            'source' => 'manual',
        ]);

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(0, $pool['assigned']);
        $this->assertSame(0, $pool['missing']);
    }

    public function test_a_check_in_without_a_roster_line_is_not_counted(): void
    {
        [$rostered, $walkIn] = $this->employees(2);

        $this->roster($rostered, 'MORNING-0600');
        $this->recordAttendance($walkIn);

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(1, $pool['assigned']);
        $this->assertSame(0, $pool['clocked_in']);
        $this->assertSame(1, $pool['missing']);
    }

    public function test_totals_reconcile_against_the_assigned_head_count(): void
    {
        [$onDuty, $onLeave, $missing] = $this->employees(3);

        $this->roster($onDuty, 'MORNING-0600');
        $this->roster($onLeave, 'AFTERNOON-1400');
        $this->roster($missing, 'NIGHT-2200');
        $this->recordAttendance($onDuty);
        $this->approveLeave($onLeave);

        $overview = app(ShiftOverviewService::class)->forToday();
        $totals = $overview['totals'];

        $this->assertTrue($overview['rostered']);
        $this->assertSame(3, $totals['assigned']);
        $this->assertSame(1, $totals['clocked_in']);
        $this->assertSame(1, $totals['on_leave']);
        $this->assertSame(1, $totals['missing']);
        $this->assertSame(
            $totals['assigned'],
            $totals['clocked_in'] + $totals['on_leave'] + $totals['missing'],
        );
    }

    public function test_an_unrostered_day_reports_nothing_to_show(): void
    {
        $overview = app(ShiftOverviewService::class)->forToday();

        $this->assertFalse($overview['rostered']);
        $this->assertSame(0, $overview['totals']['assigned']);
        $this->assertSame(0.0, $overview['coverage']);
        // The pools are still described, so the panel can explain an empty day.
        $this->assertCount(4, $overview['shifts']);
    }

    public function test_dashboard_exposes_the_overview_to_the_view(): void
    {
        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response->assertOk();
        $this->assertSame(
            $this->today()->toDateString(),
            $response->viewData('shiftOverview')['date'],
        );
    }

    public function test_a_shift_that_has_not_opened_yet_holds_its_absences_neutral(): void
    {
        $this->freezeAt('05:00');
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $pool = $this->poolFor('Morning Shift');

        // The head count is unchanged -- only the reading of it is. Nobody rostered
        // onto a shift that starts in an hour has missed anything.
        $this->assertSame(1, $pool['missing']);
        $this->assertSame('upcoming', $pool['phase']);
        $this->assertTrue($pool['awaiting']);
        $this->assertSame('Starts in 1h', $pool['phase_label']);
        $this->assertSame(0.0, $pool['elapsed']);
    }

    public function test_a_shift_inside_its_grace_window_is_still_filling(): void
    {
        $this->freezeAt('06:10');
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame('starting', $pool['phase']);
        $this->assertSame('Just started', $pool['phase_label']);
        $this->assertTrue($pool['awaiting']);
        $this->assertGreaterThan(0, $pool['elapsed']);
    }

    public function test_an_absence_reads_as_missing_once_the_grace_window_closes(): void
    {
        $this->freezeAt('07:00');
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame(1, $pool['missing']);
        $this->assertSame('active', $pool['phase']);
        $this->assertSame('In progress', $pool['phase_label']);
        $this->assertFalse($pool['awaiting']);
    }

    public function test_a_closed_shift_reports_as_ended(): void
    {
        $this->freezeAt('16:00');

        $pool = $this->poolFor('Morning Shift');

        $this->assertSame('ended', $pool['phase']);
        $this->assertSame('Ended', $pool['phase_label']);
        $this->assertFalse($pool['awaiting']);
        $this->assertSame(1.0, $pool['elapsed']);
    }

    public function test_an_overnight_pool_is_measured_against_the_following_morning(): void
    {
        // 11 PM sits inside the night shift, not after it -- the window has to run
        // past midnight rather than wrapping back on itself.
        $this->freezeAt('23:00');

        $pool = $this->poolFor('Night Shift');

        $this->assertTrue($pool['crosses_midnight']);
        $this->assertSame('active', $pool['phase']);
        $this->assertSame('10:00 PM – 6:00 AM', $pool['span_label']);
        $this->assertSame('8h', $pool['duration_label']);
    }

    /**
     * The rail keeps every pool on one timeline rather than splitting staffed
     * from empty into two columns: a shift nobody is on is still part of the day
     * and still has to appear at its own hour. It just says so in one line.
     */
    public function test_the_rail_marks_pools_nobody_is_rostered_onto(): void
    {
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('0 of 1 clocked in')
            ->assertSee('is-quiet', false)
            ->assertSee('Nobody rostered.')
            ->assertSee('Open shift &amp; schedule management', false);
    }

    private function freezeAt(string $time): void
    {
        Carbon::setTestNow(
            Carbon::parse($this->today()->toDateString().' '.$time, config('workforce.timezone')),
        );
    }

    /** @return array<string, mixed> */
    private function poolFor(string $name): array
    {
        $overview = app(ShiftOverviewService::class)->forToday();

        return collect($overview['shifts'])->firstWhere('name', $name);
    }

    private function today(): Carbon
    {
        return Carbon::now(config('workforce.timezone'))->startOfDay();
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function firstEmployee(): Employee
    {
        return $this->employees(1)->first();
    }

    /** @return Collection<int, Employee> */
    private function employees(int $count): Collection
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->take($count)
            ->get();
    }

    private function roster(Employee $employee, string $shiftCode): void
    {
        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => Shift::query()->where('code', $shiftCode)->value('id'),
            'work_date' => $this->today()->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager()->id,
        ]));
    }

    private function recordAttendance(Employee $employee): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => $this->today()->toDateString(),
            'check_in_at' => $this->today()->copy()->setTime(8, 0),
            'check_in_method' => 'manual',
            'status' => 'present',
        ]);
    }

    private function approveLeave(Employee $employee): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => $this->today()->toDateString(),
            'end_date' => $this->today()->toDateString(),
            'requested_days' => 1,
            'reason' => 'Approved for the shift overview test.',
            'status' => 'approved',
        ]);
    }
}

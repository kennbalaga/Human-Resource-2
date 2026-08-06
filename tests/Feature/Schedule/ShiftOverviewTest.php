<?php

namespace Tests\Feature\Schedule;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
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

    public function test_dashboard_renders_the_shift_overview_panel(): void
    {
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Today’s shift overview', false)
            ->assertSee('Shift &amp; Schedule Management', false)
            // The attendance panel offers a data table too, so key off this one's class.
            ->assertSee('shift-data-table', false);

        foreach (['Morning Shift', 'Administrative Shift', 'Afternoon Shift', 'Night Shift'] as $shift) {
            $response->assertSee($shift);
        }

        foreach (['Assigned', 'Clocked in', 'Missing'] as $metric) {
            $response->assertSee($metric);
        }
    }

    public function test_panel_falls_back_to_an_empty_state_without_a_roster(): void
    {
        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Today’s shift overview', false)
            ->assertSee('Nobody is rostered today')
            ->assertDontSee('shift-data-table', false);
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
        ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => Shift::query()->where('code', $shiftCode)->value('id'),
            'work_date' => $this->today()->toDateString(),
            'status' => 'scheduled',
        ]);
    }

    private function recordAttendance(Employee $employee): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'office_location_id' => 1,
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

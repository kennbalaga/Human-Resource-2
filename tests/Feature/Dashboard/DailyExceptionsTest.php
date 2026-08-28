<?php

namespace Tests\Feature\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use App\Services\DailyExceptionsService;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DailyExceptionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // Demo roster, demo attendance and demo leave all land on today. A test
        // that names people has to own every row on the day.
        AttendanceRecord::query()->delete();
        ScheduleAssignment::query()->delete();
        ScheduleDayOff::query()->delete();
        LeaveRequest::query()->delete();
    }

    public function test_dashboard_renders_the_panel(): void
    {
        $this->freezeAt('07:00');

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Who is not on the floor')
            ->assertSee('Unaccounted')
            ->assertSee('On approved leave');
    }

    public function test_a_rostered_absence_is_named_once_the_grace_window_closes(): void
    {
        $this->freezeAt('07:00');
        $employee = $this->firstEmployee();
        $this->roster($employee, 'MORNING-0600');

        $exceptions = $this->exceptionsFor($this->manager());

        $this->assertTrue($exceptions['settled']);
        $this->assertSame(1, $exceptions['unaccounted_total']);
        $this->assertSame($employee->full_name, $exceptions['unaccounted'][0]['name']);
        // Which pool they were expected on is the first thing a charge nurse asks.
        $this->assertSame('Morning Shift', $exceptions['unaccounted'][0]['shift']);
        $this->assertSame('6:00 AM – 2:00 PM', $exceptions['unaccounted'][0]['span']);
    }

    /**
     * The counterpart to ShiftOverviewTest's grace-window cases. A list of names is
     * a stronger claim than a head count, so it holds back at least as long.
     */
    public function test_nobody_is_named_while_the_shift_is_still_filling(): void
    {
        $this->freezeAt('06:10');
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $exceptions = $this->exceptionsFor($this->manager());

        $this->assertFalse($exceptions['settled']);
        $this->assertSame(0, $exceptions['unaccounted_total']);
        $this->assertSame([], $exceptions['unaccounted']);
    }

    public function test_the_empty_state_distinguishes_a_quiet_day_from_an_early_one(): void
    {
        $this->freezeAt('06:10');
        $this->roster($this->firstEmployee(), 'MORNING-0600');

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            // Not the same statement as "everyone is in".
            ->assertSee('No shift has passed its check-in window yet');
    }

    public function test_a_check_in_clears_the_name(): void
    {
        $this->freezeAt('07:00');
        $employee = $this->firstEmployee();
        $this->roster($employee, 'MORNING-0600');
        $this->recordAttendance($employee);

        $this->assertSame(0, $this->exceptionsFor($this->manager())['unaccounted_total']);
    }

    public function test_approved_leave_is_listed_separately_and_never_as_unaccounted(): void
    {
        $this->freezeAt('07:00');
        $employee = $this->firstEmployee();
        $this->roster($employee, 'MORNING-0600');
        $this->approveLeave($employee);

        $exceptions = $this->exceptionsFor($this->manager());

        $this->assertSame(0, $exceptions['unaccounted_total']);
        $this->assertSame(1, $exceptions['on_leave_total']);
        $this->assertSame($employee->full_name, $exceptions['on_leave'][0]['name']);
        $this->assertSame('Last day today', $exceptions['on_leave'][0]['until_label']);
    }

    public function test_a_day_off_is_not_an_absence(): void
    {
        $this->freezeAt('07:00');
        $employee = $this->firstEmployee();
        $this->roster($employee, 'MORNING-0600');
        ScheduleDayOff::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $this->today()->toDateString(),
            'source' => 'manual',
        ]);

        $this->assertSame(0, $this->exceptionsFor($this->manager())['unaccounted_total']);
    }

    public function test_leave_running_past_today_reports_its_return_date(): void
    {
        $this->freezeAt('07:00');
        $employee = $this->firstEmployee();
        $this->approveLeave($employee, endsInDays: 3);

        $exceptions = $this->exceptionsFor($this->manager());

        $this->assertSame(
            'Until '.$this->today()->copy()->addDays(3)->format('M j'),
            $exceptions['on_leave'][0]['until_label'],
        );
    }

    /**
     * The reason this panel is not folded into the shared, hospital-wide shift
     * board: it names individuals, and a head's reach stops at their own unit.
     */
    public function test_a_department_head_is_not_shown_another_units_absentees(): void
    {
        $this->freezeAt('07:00');
        $head = $this->nursingHead();
        $inside = Employee::query()
            ->where('employment_status', 'active')
            ->where('department_id', $head->employee->department_id)
            ->whereKeyNot($head->employee->id)
            ->firstOrFail();
        $outside = Employee::query()
            ->where('employment_status', 'active')
            ->whereNotNull('department_id')
            ->where('department_id', '!=', $head->employee->department_id)
            ->firstOrFail();

        $this->roster($inside, 'MORNING-0600');
        $this->roster($outside, 'MORNING-0600');

        $headView = $this->exceptionsFor($head);
        $names = array_column($headView['unaccounted'], 'name');

        $this->assertSame(1, $headView['unaccounted_total']);
        $this->assertContains($inside->full_name, $names);
        $this->assertNotContains($outside->full_name, $names);

        // And the cache key separates the two scopes rather than serving whichever
        // was built first in this minute.
        $this->assertSame(2, $this->exceptionsFor($this->manager())['unaccounted_total']);
    }

    /** @return array<string, mixed> */
    private function exceptionsFor(User $user): array
    {
        return app(DailyExceptionsService::class)->forToday($user);
    }

    private function freezeAt(string $time): void
    {
        Carbon::setTestNow(
            Carbon::parse($this->today()->toDateString().' '.$time, config('workforce.timezone')),
        );
    }

    private function today(): Carbon
    {
        return Carbon::now(config('workforce.timezone'))->startOfDay();
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function nursingHead(): User
    {
        return User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
    }

    private function firstEmployee(): Employee
    {
        return Employee::query()->where('employment_status', 'active')->orderBy('id')->firstOrFail();
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
            'office_location_id' => 1,
            'attendance_date' => $this->today()->toDateString(),
            'check_in_at' => $this->today()->copy()->setTime(6, 5),
            'check_in_method' => 'manual',
            'status' => 'present',
        ]);
    }

    private function approveLeave(Employee $employee, int $endsInDays = 0): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => $this->today()->toDateString(),
            'end_date' => $this->today()->copy()->addDays($endsInDays)->toDateString(),
            'requested_days' => $endsInDays + 1,
            'reason' => 'Approved for the daily exceptions test.',
            'status' => 'approved',
        ]);
    }
}

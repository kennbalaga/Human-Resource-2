<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\User;
use App\Services\AttendanceOverviewService;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AttendanceOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // The seeders ship a demo roster and demo leave. Counting tests need to own
        // every row in the window, so the slate is wiped and rebuilt per test.
        AttendanceRecord::query()->delete();
        ScheduleAssignment::query()->delete();
        ScheduleDayOff::query()->delete();
        LeaveRequest::query()->delete();
    }

    public function test_dashboard_renders_the_attendance_overview_panel(): void
    {
        $this->recordAttendance($this->firstEmployee(), $this->today(), 'present');

        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Attendance overview')
            ->assertSee('Time &amp; Attendance', false)
            ->assertSee('data-attendance-chart', false)
            ->assertSee('View data table');

        foreach (['Present', 'Late', 'On leave', 'Absent'] as $series) {
            $response->assertSee($series);
        }
    }

    public function test_panel_falls_back_to_an_empty_state_without_activity(): void
    {
        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Attendance overview')
            ->assertDontSee('data-attendance-chart', false)
            ->assertSee('No attendance, leave, or roster activity');
    }

    public function test_overview_separates_present_late_on_leave_and_absent(): void
    {
        $today = $this->today();
        [$present, $late, $onLeave, $absent] = Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->take(4)
            ->get();

        $this->recordAttendance($present, $today, 'present');
        $this->recordAttendance($late, $today, 'late');
        $this->approveLeave($onLeave, $today, $today);

        // Only rostered staff can be absent, so all four need a shift on the day.
        foreach ([$present, $late, $onLeave, $absent] as $employee) {
            $this->roster($employee, $today);
        }

        $bucket = $this->bucketFor($today);

        $this->assertSame(1, $bucket['present']);
        $this->assertSame(1, $bucket['late']);
        $this->assertSame(1, $bucket['on_leave']);
        $this->assertSame(1, $bucket['absent']);
        $this->assertSame(4, $bucket['total']);
    }

    public function test_a_day_without_a_roster_reports_no_absences(): void
    {
        $today = $this->today();
        $this->recordAttendance($this->firstEmployee(), $today, 'present');

        $bucket = $this->bucketFor($today);

        $this->assertSame(1, $bucket['present']);
        $this->assertSame(0, $bucket['absent']);
    }

    public function test_a_rostered_day_off_is_not_counted_absent(): void
    {
        $today = $this->today();
        $employee = $this->firstEmployee();

        $this->roster($employee, $today);
        ScheduleDayOff::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $today->toDateString(),
            'source' => 'manual',
        ]);

        $this->assertSame(0, $this->bucketFor($today)['absent']);
    }

    public function test_a_check_in_outranks_an_approved_leave_request(): void
    {
        $today = $this->today();
        $employee = $this->firstEmployee();

        $this->approveLeave($employee, $today, $today);
        $this->recordAttendance($employee, $today, 'late');

        $bucket = $this->bucketFor($today);

        $this->assertSame(1, $bucket['late']);
        $this->assertSame(0, $bucket['on_leave']);
    }

    public function test_leave_spanning_several_days_lands_in_every_covered_bucket(): void
    {
        $today = $this->today();
        $this->approveLeave($this->firstEmployee(), $today->copy()->subDays(2), $today);

        $overview = app(AttendanceOverviewService::class)->forRange(7);

        $this->assertSame(3, $overview['totals']['on_leave']);
    }

    public function test_range_is_limited_to_the_supported_windows(): void
    {
        $service = app(AttendanceOverviewService::class);

        $this->assertCount(7, $service->forRange(7)['buckets']);
        $this->assertCount(30, $service->forRange(30)['buckets']);
        // Anything else falls back to the default rather than charting a stray value.
        $this->assertCount(7, $service->forRange(365)['buckets']);
    }

    public function test_dashboard_honours_the_requested_range(): void
    {
        $response = $this->actingAs($this->manager())->get('/dashboard?attendance_days=30');

        $response->assertOk();
        $this->assertSame(30, $response->viewData('attendanceOverview')['days']);
        $this->assertCount(30, $response->viewData('attendanceOverview')['buckets']);
    }

    /** @return array<string, mixed> */
    private function bucketFor(Carbon $date): array
    {
        $overview = app(AttendanceOverviewService::class)->forRange(7);

        return collect($overview['buckets'])->firstWhere('date', $date->toDateString());
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
        return Employee::query()->where('employment_status', 'active')->orderBy('id')->firstOrFail();
    }

    private function recordAttendance(Employee $employee, Carbon $date, string $status): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => $date->toDateString(),
            'check_in_at' => $date->copy()->setTime(8, 0),
            'check_in_method' => 'manual',
            'status' => $status,
            'late_minutes' => $status === 'late' ? 15 : 0,
        ]);
    }

    private function approveLeave(Employee $employee, Carbon $from, Carbon $to): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => $from->toDateString(),
            'end_date' => $to->toDateString(),
            'requested_days' => $from->diffInDays($to) + 1,
            'reason' => 'Approved for the attendance overview test.',
            'status' => 'approved',
        ]);
    }

    private function roster(Employee $employee, Carbon $date): void
    {
        RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => Shift::query()->value('id'),
            'work_date' => $date->toDateString(),
            'status' => 'scheduled',
            'created_by' => $this->manager()->id,
        ]));
    }
}

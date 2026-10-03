<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Role;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\Shift;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StaffDashboardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A fixed mid-month Wednesday morning in Manila (10:00 local). Every widget
     * here is a statement about "today", so the clock is pinned rather than left
     * to decide whether a fixture lands in this month or the last one.
     */
    private const NOW = '2026-08-19 02:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(self::NOW);
    }

    public function test_staff_land_on_their_own_dashboard_rather_than_the_org_overview(): void
    {
        $this->seed();

        $response = $this->actingAs($this->staffUser())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('My workday')
            ->assertSee('Today’s attendance', false)
            ->assertSee('My shift today')
            ->assertSee('Upcoming schedule')
            ->assertSee('My attendance summary')
            ->assertSee('Timesheet status')
            ->assertSee('Leave balance')
            ->assertSee('My overtime summary')
            ->assertSee('My workforce analytics')
            ->assertSee('View my schedule')
            ->assertSee('Request leave')
            ->assertSee('View timesheet')
            ->assertSee('View overtime');

        // The hospital-wide panels belong to the manager dashboard and must not
        // reach a staff account through this route.
        $response
            ->assertDontSee('Recently added employees')
            ->assertDontSee('Workforce by department')
            ->assertDontSee('Total employees');
    }

    public function test_managers_keep_the_organisation_dashboard(): void
    {
        $this->seed();

        $user = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('HRMS Overview')
            ->assertSee('Recently added employees')
            ->assertDontSee('My workday');
    }

    public function test_an_account_without_an_employee_profile_keeps_the_overview(): void
    {
        $this->seed();

        $user = User::factory()->create();
        $user->roles()->sync([Role::query()->where('slug', 'employee')->firstOrFail()->id]);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSee('HRMS Overview')
            ->assertDontSee('My workday');
    }

    public function test_attendance_card_reports_an_open_biometric_check_in(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $office = $this->office();
        $device = BiometricDevice::query()->create([
            'office_location_id' => $office->id,
            'code' => 'TERM-ER-1',
            'name' => 'Emergency Lobby Terminal',
            'provider' => 'zkteco',
            'is_active' => true,
            'last_seen_at' => now(),
        ]);
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => '4417',
            'is_active' => true,
            'enrolled_at' => now()->subMonth(),
        ]);

        $this->record($employee, $office, $this->localDay(), [
            'check_in_at' => $this->localInstant($this->localDay(), '06:55'),
            'check_in_method' => 'biometric',
            'check_in_biometric_device_id' => $device->id,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Clocked In')
            ->assertSee('On Time')
            ->assertSee('6:55 AM')
            ->assertSee('Clock Out')
            ->assertSee('Biometric scan')
            ->assertSee('Emergency Lobby Terminal')
            ->assertSee('Biometric ID 4417')
            ->assertDontSee('Clock In</span>', false);

        $today = $response->viewData('dashboard')['today'];
        $this->assertSame('clocked_in', $today['state']);
        $this->assertTrue($today['can_clock_out']);
        $this->assertFalse($today['can_clock_in']);
        $this->assertTrue($today['biometric']['enrolled']);
        $this->assertTrue($today['biometric']['assigned']);
    }

    public function test_a_reserved_pin_does_not_claim_the_fingerprint_has_been_captured(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $device = BiometricDevice::query()->create([
            'office_location_id' => $this->office()->id,
            'code' => 'TERM-ER-1',
            'name' => 'Emergency Lobby Terminal',
            'provider' => 'zkteco',
            'is_active' => true,
        ]);

        // What a bulk PIN assignment leaves behind: the number is reserved, but
        // nobody has stood at the terminal and taken this person's finger.
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $device->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
            'enrolled_at' => null,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Awaiting fingerprint capture')
            ->assertSee('Awaiting capture')
            ->assertDontSee('Enrolled on');

        $today = $response->viewData('dashboard')['today'];
        $this->assertTrue($today['biometric']['assigned']);
        $this->assertFalse($today['biometric']['enrolled']);
    }

    public function test_the_dashboard_prefers_the_terminal_that_is_still_in_service(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $office = $this->office();

        // A retired terminal still holding a captured template, and the
        // replacement holding only a reservation. Sorting on enrolled_at alone
        // would send the nurse to the machine that is switched off.
        $retired = BiometricDevice::query()->create([
            'office_location_id' => $office->id,
            'code' => 'TERM-OLD',
            'name' => 'Retired Lobby Terminal',
            'provider' => 'zkteco',
            'is_active' => false,
        ]);
        $current = BiometricDevice::query()->create([
            'office_location_id' => $office->id,
            'code' => 'TERM-NEW',
            'name' => 'Replacement Lobby Terminal',
            'provider' => 'zkteco',
            'is_active' => true,
        ]);

        BiometricEnrollment::query()->create([
            'biometric_device_id' => $retired->id,
            'employee_id' => $employee->id,
            'external_user_id' => '4417',
            'is_active' => true,
            'enrolled_at' => now()->subMonth(),
        ]);
        BiometricEnrollment::query()->create([
            'biometric_device_id' => $current->id,
            'employee_id' => $employee->id,
            'external_user_id' => (string) $employee->id,
            'is_active' => true,
            'enrolled_at' => null,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');

        $response->assertOk()->assertSee('Replacement Lobby Terminal');

        $today = $response->viewData('dashboard')['today'];
        $this->assertSame((string) $employee->id, $today['biometric']['external_id']);
        $this->assertFalse($today['biometric']['enrolled']);
    }

    public function test_attendance_card_reports_the_closed_day_with_its_total_hours(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $office = $this->office();

        $this->record($employee, $office, $this->localDay(), [
            'check_in_at' => $this->localInstant($this->localDay(), '06:00'),
            'check_out_at' => $this->localInstant($this->localDay(), '15:00'),
            'check_in_method' => 'biometric',
            'check_out_method' => 'biometric',
            'worked_minutes' => 480,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Clocked Out')
            ->assertSee('3:00 PM')
            ->assertSee('8h 00m');

        $this->assertSame('completed', $response->viewData('dashboard')['today']['state']);
    }

    public function test_clocking_out_from_the_dashboard_returns_to_the_dashboard(): void
    {
        $this->seed();

        $office = $this->office();
        $this->record($this->staffEmployee(), $office, $this->localDay(), [
            'check_in_at' => now()->subHours(2),
        ]);

        $this->actingAs($this->staffUser())
            ->post(route('attendance.check-out'), [
                'office_location_id' => $office->id,
                'return_to' => 'dashboard',
            ])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasNoErrors();
    }

    public function test_a_caller_supplied_destination_is_rejected(): void
    {
        $this->seed();

        $this->actingAs($this->staffUser())
            ->post(route('attendance.check-in'), [
                'office_location_id' => $this->office()->id,
                'return_to' => 'https://evil.example.com',
            ])
            ->assertSessionHasErrors('return_to');

        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_my_shift_and_upcoming_schedule_show_only_this_employee(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $this->clearSchedule($employee);

        $colleague = Employee::query()->where('id', '!=', $employee->id)->firstOrFail();
        $shift = $this->morningShift();
        $today = Carbon::parse($this->localDay());

        $this->assign($employee, $shift, $today);
        $this->assign($employee, $shift, $today->copy()->addDay());
        ScheduleDayOff::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $today->copy()->addDays(2)->toDateString(),
            'source' => 'roster_draft',
        ]);
        // A colleague's roster line on the same day must never surface here.
        $this->assign($colleague, $shift, $today->copy()->addDay());

        $dashboard = $this->actingAs($this->staffUser())->get('/dashboard')->viewData('dashboard');

        $this->assertSame('shift', $dashboard['shift']['kind']);
        $this->assertSame($shift->name, $dashboard['shift']['name']);
        $this->assertSame($employee->position?->title ?? 'Unassigned', $dashboard['shift']['position']);

        $rows = collect($dashboard['upcoming']['rows']);
        $this->assertSame(
            [$today->copy()->addDay()->toDateString(), $today->copy()->addDays(2)->toDateString()],
            $rows->pluck('date')->all(),
        );
        $this->assertSame($shift->name, $rows->first()['shift']);
        $this->assertSame('Rest Day', $rows->last()['shift']);
    }

    public function test_a_rest_day_replaces_the_shift_card_and_raises_a_change_alert(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $this->clearSchedule($employee);

        $shift = $this->morningShift();
        $today = Carbon::parse($this->localDay());

        $this->assign($employee, $shift, $today);
        ScheduleDayOff::query()->create([
            'employee_id' => $employee->id,
            'work_date' => $today->toDateString(),
            'source' => 'preference',
            'notes' => 'Staff coverage',
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');
        $dashboard = $response->viewData('dashboard');

        $this->assertSame('rest_day', $dashboard['shift']['kind']);

        $change = collect($dashboard['schedule_changes'])->firstWhere('date', $today->toDateString());

        $this->assertNotNull($change);
        $this->assertSame('rest_day', $change['type']);
        $this->assertStringContainsString($shift->name, $change['from']);
        $this->assertStringContainsString('Rest Day', $change['to']);
        $this->assertSame('Staff coverage', $change['reason']);

        $response->assertSee('Staff coverage')->assertSee('Previous');
    }

    public function test_monthly_tallies_count_absence_only_for_days_that_have_closed(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $this->clearSchedule($employee);

        $office = $this->office();
        $shift = $this->morningShift();
        $today = Carbon::parse($this->localDay());
        $onTime = $today->copy()->subDays(3);
        $lateDay = $today->copy()->subDays(2);
        $missed = $today->copy()->subDay();

        $this->record($employee, $office, $onTime->toDateString(), [
            'check_in_at' => $this->localInstant($onTime->toDateString(), '08:00'),
            'check_out_at' => $this->localInstant($onTime->toDateString(), '17:00'),
            'worked_minutes' => 480,
        ]);
        $this->record($employee, $office, $lateDay->toDateString(), [
            'check_in_at' => $this->localInstant($lateDay->toDateString(), '08:25'),
            'check_out_at' => $this->localInstant($lateDay->toDateString(), '17:00'),
            'status' => 'late',
            'late_minutes' => 25,
            'worked_minutes' => 455,
        ]);

        foreach ([$onTime, $lateDay, $missed, $today] as $date) {
            $this->assign($employee, $shift, $date);
        }

        $summary = $this->actingAs($this->staffUser())->get('/dashboard')
            ->viewData('dashboard')['attendance_summary'];

        $this->assertSame(1, $summary['present']);
        $this->assertSame(1, $summary['late']);
        // Today is rostered but still running, so it is not a miss yet.
        $this->assertSame(1, $summary['absent']);
        $this->assertSame(3, $summary['expected']);
        $this->assertEqualsWithDelta(66.7, $summary['rate'], 0.1);
    }

    public function test_approved_leave_is_not_counted_as_an_absence(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $this->clearSchedule($employee);

        $shift = $this->morningShift();
        $today = Carbon::parse($this->localDay());
        $leaveDay = $today->copy()->subDay();

        $this->assign($employee, $shift, $leaveDay);
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'SICK')->firstOrFail()->id,
            'start_date' => $leaveDay->toDateString(),
            'end_date' => $leaveDay->toDateString(),
            'requested_days' => 1,
            'reason' => 'Fever',
            'status' => 'approved',
        ]);

        $summary = $this->actingAs($this->staffUser())->get('/dashboard')
            ->viewData('dashboard')['attendance_summary'];

        $this->assertSame(0, $summary['absent']);
        $this->assertSame(1, $summary['leave']);
        $this->assertSame(0, $summary['expected']);
    }

    public function test_overtime_splits_the_month_into_approved_and_pending(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $office = $this->office();
        $today = Carbon::parse($this->localDay());

        $rows = [
            [$today->copy()->subDays(3), 90, 'approved'],
            [$today->copy()->subDays(2), 30, 'pending'],
            // Rejected overtime is in neither half, and not in the total either.
            [$today->copy()->subDay(), 60, 'rejected'],
        ];

        foreach ($rows as [$date, $minutes, $approval]) {
            $this->record($employee, $office, $date->toDateString(), [
                'check_in_at' => $this->localInstant($date->toDateString(), '08:00'),
                'check_out_at' => $this->localInstant($date->toDateString(), '18:00'),
                'approval_status' => $approval,
                'worked_minutes' => 540,
                'overtime_minutes' => $minutes,
            ]);
        }

        $overtime = $this->actingAs($this->staffUser())->get('/dashboard')
            ->viewData('dashboard')['overtime'];

        $this->assertSame('2h 00m', $overtime['total_hours']);
        $this->assertSame('1h 30m', $overtime['approved_hours']);
        $this->assertSame('0h 30m', $overtime['pending_hours']);
        $this->assertSame(2, $overtime['days']);
    }

    public function test_timesheet_card_flags_a_draft_period_for_submission(): void
    {
        $this->seed();

        $today = Carbon::parse($this->localDay());

        Timesheet::query()->create([
            'employee_id' => $this->staffEmployee()->id,
            'period_start' => $today->copy()->startOfWeek(Carbon::MONDAY)->toDateString(),
            'period_end' => $today->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'status' => 'draft',
            'regular_minutes' => 1920,
            'overtime_minutes' => 45,
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');
        $timesheet = $response->viewData('dashboard')['timesheet'];

        $this->assertSame('draft', $timesheet['status']);
        $this->assertTrue($timesheet['attention']);
        $this->assertSame('32h 45m', $timesheet['total_hours']);
        $this->assertSame('0h 45m', $timesheet['overtime_hours']);

        $response->assertSee('Draft — pending submission', false);
    }

    public function test_leave_widget_lists_the_three_credits_and_recent_requests(): void
    {
        $this->seed();

        $employee = $this->staffEmployee();
        $vacation = LeaveType::query()->where('code', 'VAC')->firstOrFail();

        LeaveBalance::query()->updateOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $vacation->id, 'year' => now()->year],
            ['entitled_days' => 15, 'used_days' => 3, 'pending_days' => 0],
        );

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => $vacation->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
            'requested_days' => 3,
            'reason' => 'Family matters',
            'status' => 'approved',
        ]);

        $response = $this->actingAs($this->staffUser())->get('/dashboard');
        $leave = $response->viewData('dashboard')['leave'];

        $this->assertSame(['VAC', 'SICK', 'EMER'], collect($leave['balances'])->pluck('code')->all());
        $this->assertEqualsWithDelta(12.0, collect($leave['balances'])->firstWhere('code', 'VAC')['available'], 0.01);
        // Approved leave that has not started yet reads as its phase, not as a
        // bare "Approved" the employee would have to check the dates against.
        $this->assertSame('Upcoming', $leave['recent'][0]['status_label']);

        $response
            ->assertSee('Vacation Leave')
            ->assertSee('Sick Leave')
            ->assertSee('Emergency Leave')
            ->assertSee('Request leave');
    }

    public function test_analytics_plots_six_months_of_this_employees_own_attendance(): void
    {
        $this->seed();

        $analytics = $this->actingAs($this->staffUser())->get('/dashboard')
            ->viewData('dashboard')['analytics'];

        $this->assertCount(6, $analytics['trend']);
        $this->assertSame('Mar', $analytics['trend'][0]['label']);
        $this->assertTrue(collect($analytics['trend'])->last()['is_current']);
        $this->assertSame(
            ['Attendance rate', 'Punctuality rate', 'Total hours worked', 'Overtime', 'Leave used'],
            collect($analytics['metrics'])->pluck('label')->all(),
        );
    }

    private function staffUser(): User
    {
        return User::query()->where('email', 'employee@hrms.local')->firstOrFail();
    }

    private function staffEmployee(): Employee
    {
        return $this->staffUser()->employee()->firstOrFail();
    }

    private function office(): OfficeLocation
    {
        return OfficeLocation::query()->where('is_active', true)->firstOrFail();
    }

    private function morningShift(): Shift
    {
        return Shift::query()->where('is_active', true)->orderBy('start_time')->firstOrFail();
    }

    /** Today as the office sees it, which is the day every widget reports on. */
    private function localDay(): string
    {
        return Carbon::now($this->office()->timezone)->toDateString();
    }

    /**
     * A wall-clock time at the office, as the UTC instant the column stores.
     * Eloquent writes a datetime in whatever zone the instance carries, so a
     * fixture built in Manila time would otherwise be read back as UTC and
     * replay eight hours later.
     */
    private function localInstant(string $date, string $time): Carbon
    {
        return Carbon::parse($date.' '.$time, $this->office()->timezone)->utc();
    }

    /**
     * The seeders publish a week of roster lines for this account. Tests that count
     * rows clear them first so they assert against their own fixtures.
     */
    private function clearSchedule(Employee $employee): void
    {
        ScheduleAssignment::query()->where('employee_id', $employee->id)->delete();
        ScheduleDayOff::query()->where('employee_id', $employee->id)->delete();
    }

    private function assign(Employee $employee, Shift $shift, Carbon $date): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'work_date' => $date->toDateString(),
            'status' => 'scheduled',
            'created_by' => User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail()->id,
        ]));
    }

    /** @param array<string, mixed> $attributes */
    private function record(Employee $employee, OfficeLocation $office, string $date, array $attributes): AttendanceRecord
    {
        return AttendanceRecord::query()->create($attributes + [
            'employee_id' => $employee->id,
            'office_location_id' => $office->id,
            'attendance_date' => $date,
            'status' => 'present',
        ]);
    }
}

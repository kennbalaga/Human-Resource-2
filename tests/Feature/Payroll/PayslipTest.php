<?php

namespace Tests\Feature\Payroll;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\User;
use App\Services\Payroll\PayslipBuilder;
use App\Services\Payroll\PayslipPeriod;
use App\Services\TimesheetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\ConfirmsDownloadPassword;
use Tests\TestCase;

class PayslipTest extends TestCase
{
    use ConfirmsDownloadPassword, RefreshDatabase;

    /** The first half of May 2027: ten weekdays, and no holiday on any of them. */
    private const PERIOD = '2027-05-1';

    private const RANGE = ['date_from' => '2027-05-01', 'date_to' => '2027-05-15'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_periods_split_the_month_at_the_fifteenth(): void
    {
        $first = PayslipPeriod::forDate('2026-08-15');
        $second = PayslipPeriod::forDate('2026-08-16');

        $this->assertSame(['2026-08-01', '2026-08-15', '2026-08-1'], [$first->start->toDateString(), $first->end->toDateString(), $first->key()]);
        $this->assertSame(['2026-08-16', '2026-08-31', '2026-08-2'], [$second->start->toDateString(), $second->end->toDateString(), $second->key()]);
        $this->assertSame('2027-02-28', PayslipPeriod::fromKey('2027-02-2')->end->toDateString());
        $this->assertNull(PayslipPeriod::fromKey('2027-13-1'));
    }

    public function test_an_employee_sees_their_own_payslip_with_its_number_and_confidential_mark(): void
    {
        $employee = $this->user('employee@hrms.local');
        $this->timesheetFor($employee->employee, ['2027-05-03', '2027-05-04']);

        $this->actingAs($employee)->get(route('payslips.show', [$employee->employee, self::PERIOD]))
            ->assertOk()
            ->assertSee(sprintf('PS-202705A-%05d', $employee->employee->id))
            ->assertSee('Confidential')
            ->assertSee('Days worked')
            ->assertSee('Computed by Payroll');
    }

    public function test_the_attendance_summary_counts_worked_leave_and_absent_days(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($employee, ['2027-05-03', '2027-05-04', '2027-05-06']);
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->where('code', 'VAC')->value('id'),
            'start_date' => '2027-05-05',
            'end_date' => '2027-05-05',
            'requested_days' => 1,
            'reason' => 'Family errand',
            'status' => 'approved',
        ]);

        $payslip = $this->build($employee);

        $this->assertSame(['scheduled' => 10, 'worked' => 3, 'leave' => 1.0, 'pending' => 0, 'absent' => 6], $payslip['days']);
        $this->assertSame(1440, $payslip['minutes']['regular']);
        $this->assertSame(90, $payslip['minutes']['overtime']);
        $this->assertCount(1, $payslip['leave']);
        $this->assertTrue($payslip['complete']);
    }

    public function test_a_holiday_is_not_expected_as_a_working_day(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        Holiday::query()->create(['date' => '2027-05-05', 'name' => 'Test Holiday', 'type' => Holiday::TYPE_REGULAR]);
        $this->timesheetFor($employee, ['2027-05-03']);

        $payslip = $this->build($employee);

        // Labor Day 2027 is listed too, but falls on a Saturday, so only the
        // weekday holiday takes a day off the expectation.
        $this->assertSame(9, $payslip['days']['scheduled']);
        $this->assertSame(8, $payslip['days']['absent']);
        $this->assertSame(['Labor Day', 'Test Holiday'], $payslip['holidays']->pluck('name')->all());
    }

    public function test_a_week_that_straddles_the_fifteenth_lands_on_both_payslips(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($employee, ['2027-03-15', '2027-03-16']);

        $this->assertSame(1, app(PayslipBuilder::class)->build($employee, PayslipPeriod::fromKey('2027-03-1'))['days']['worked']);
        $this->assertSame(1, app(PayslipBuilder::class)->build($employee, PayslipPeriod::fromKey('2027-03-2'))['days']['worked']);
    }

    public function test_unapproved_weeks_make_a_payslip_partial_without_counting_as_absences(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($employee, ['2027-05-03']);
        $this->timesheetFor($employee, ['2027-05-10', '2027-05-11'], 'submitted');

        $payslip = $this->build($employee);

        $this->assertFalse($payslip['complete']);
        $this->assertSame(['approved' => 1, 'pending' => 1], $payslip['weeks']);
        $this->assertSame(['scheduled' => 10, 'worked' => 1, 'leave' => 0.0, 'pending' => 2, 'absent' => 7], $payslip['days']);
    }

    public function test_weekend_work_without_a_roster_never_reads_as_more_than_scheduled(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        $employee->update(['hire_date' => '2027-05-15']);
        $this->timesheetFor($employee, ['2027-05-15']);

        $payslip = $this->build($employee->refresh());

        $this->assertSame(1, $payslip['days']['scheduled']);
        $this->assertSame(0, $payslip['days']['absent']);
    }

    public function test_there_is_no_payslip_until_a_timesheet_in_the_period_is_approved(): void
    {
        $employee = $this->user('employee@hrms.local');
        $this->timesheetFor($employee->employee, ['2027-05-03'], 'submitted');

        $this->actingAs($employee)->get(route('payslips.show', [$employee->employee, self::PERIOD]))->assertNotFound();

        $this->flushSession();
        $this->actingAs($this->user('hr.manager@hrms.local'))->get(route('payslips.show', [$employee->employee, self::PERIOD]))->assertNotFound();
    }

    public function test_hr_opens_any_employees_payslip_and_the_access_is_audited(): void
    {
        $hr = $this->user('hr.manager@hrms.local');
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($employee, ['2027-05-03']);

        $this->actingAs($hr)->get(route('payslips.show', [$employee, self::PERIOD]))->assertOk();

        $this->assertDatabaseHas('audit_logs', ['user_id' => $hr->id, 'action' => 'payslips.view', 'subject_type' => Employee::class, 'subject_id' => $employee->id]);
    }

    public function test_an_employee_cannot_open_a_colleagues_payslip(): void
    {
        $employee = $this->user('employee@hrms.local');
        $colleague = $this->user('hr.manager@hrms.local')->employee;
        $this->timesheetFor($colleague, ['2027-05-03']);

        $this->actingAs($employee)->get(route('payslips.show', [$colleague, self::PERIOD]))->assertForbidden();
        $this->actingAs($employee)->get(route('payslips.download', [$colleague, self::PERIOD]))->assertForbidden();
    }

    public function test_a_department_head_cannot_open_a_payslip_from_their_own_department(): void
    {
        $head = $this->user('nursing.head@hrms.local');
        $colleague = $this->colleagueOf($head);
        $this->timesheetFor($colleague, ['2027-05-03']);

        $this->actingAs($head)->get(route('payslips.show', [$colleague, self::PERIOD]))->assertForbidden();
    }

    public function test_a_system_administrator_cannot_open_another_employees_payslip(): void
    {
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($employee, ['2027-05-03']);

        $this->actingAs($this->user('admin@hrms.local'))->get(route('payslips.show', [$employee, self::PERIOD]))->assertForbidden();
    }

    public function test_a_department_head_still_sees_their_own_payslip(): void
    {
        $head = $this->user('nursing.head@hrms.local');
        $this->timesheetFor($head->employee, ['2027-05-03']);

        $this->actingAs($head)->get(route('payslips.show', [$head->employee, self::PERIOD]))->assertOk();
    }

    public function test_the_list_is_the_employees_own_unless_the_reader_is_hr(): void
    {
        $head = $this->user('nursing.head@hrms.local');
        $colleague = $this->colleagueOf($head);
        $employee = $this->user('employee@hrms.local')->employee;
        $this->timesheetFor($colleague, ['2027-05-03']);
        $this->timesheetFor($employee, ['2027-05-03']);

        $this->actingAs($this->user('hr.manager@hrms.local'))->get(route('payslips.index', self::RANGE))
            ->assertOk()
            ->assertSee($colleague->employee_number)
            ->assertSee($employee->employee_number);

        $this->flushSession();
        $this->actingAs($head)->get(route('payslips.index', self::RANGE))
            ->assertOk()
            ->assertDontSee($colleague->employee_number)
            ->assertSee('No payslips yet');

        $this->flushSession();
        $this->actingAs($this->user('employee@hrms.local'))->get(route('payslips.index', self::RANGE))
            ->assertOk()
            ->assertSee(sprintf('PS-202705A-%05d', $employee->id))
            ->assertDontSee(sprintf('PS-202705A-%05d', $colleague->id));
    }

    public function test_the_pdf_downloads_and_is_audited(): void
    {
        $employee = $this->user('employee@hrms.local');
        $this->timesheetFor($employee->employee, ['2027-05-03']);

        $this->actingAs($employee)->get(route('payslips.download', [$employee->employee, self::PERIOD]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('audit_logs', ['user_id' => $employee->id, 'action' => 'payslips.download', 'subject_id' => $employee->employee->id]);
    }

    public function test_approving_a_timesheet_tells_the_employee_their_payslip_is_ready(): void
    {
        $employee = $this->user('employee@hrms.local');
        $timesheet = $this->timesheetFor($employee->employee, ['2027-05-03'], 'submitted');

        $this->actingAs($this->user('hr.manager@hrms.local'))
            ->post(route('timesheets.approve', $timesheet))
            ->assertSessionHasNoErrors();

        $notification = $employee->notifications()->firstOrFail();
        $this->assertSame('Your payslip is ready', $notification->data['title']);
        $this->assertSame('payroll', $notification->data['category']);
        $this->assertSame(route('payslips.show', [$employee->employee, self::PERIOD]), $notification->data['action_url']);
    }

    /** @return array<string, mixed> */
    private function build(Employee $employee): array
    {
        return app(PayslipBuilder::class)->build($employee, PayslipPeriod::fromKey(self::PERIOD));
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    private function colleagueOf(User $head): Employee
    {
        return Employee::query()
            ->where('department_id', $head->employee->department_id)
            ->whereKeyNot($head->employee->id)
            ->firstOrFail();
    }

    /**
     * The week's timesheet holding the given dates, with an entry per date,
     * totalled by the same recalculation production uses and then moved to the
     * given status.
     *
     * @param  array<int, string>  $dates
     */
    private function timesheetFor(Employee $employee, array $dates, string $status = 'approved'): Timesheet
    {
        $monday = Carbon::parse($dates[0])->startOfWeek(Carbon::MONDAY);
        $timesheet = Timesheet::query()->create([
            'employee_id' => $employee->id,
            'period_start' => $monday->toDateString(),
            'period_end' => $monday->copy()->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'status' => 'draft',
        ]);

        foreach ($dates as $date) {
            $record = AttendanceRecord::query()->create([
                'employee_id' => $employee->id,
                'office_location_id' => OfficeLocation::query()->value('id'),
                'attendance_date' => $date,
                'check_in_at' => $date.' 08:00:00',
                'check_out_at' => $date.' 17:30:00',
                'status' => 'present',
                'worked_minutes' => 510,
                'overtime_minutes' => 30,
            ]);
            TimesheetEntry::query()->create([
                'timesheet_id' => $timesheet->id,
                'attendance_record_id' => $record->id,
                'work_date' => $date,
                'regular_minutes' => 480,
                'overtime_minutes' => 30,
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'source' => 'attendance',
            ]);
        }

        app(TimesheetService::class)->recalculate($timesheet);

        $timesheet->update([
            'status' => $status,
            'submitted_at' => $status === 'draft' ? null : now(),
            'reviewed_by' => $status === 'approved' ? $this->user('hr.manager@hrms.local')->id : null,
            'reviewed_at' => $status === 'approved' ? now() : null,
        ]);

        return $timesheet->refresh();
    }
}

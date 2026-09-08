<?php

namespace Tests\Feature\Dashboard;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OfficeLocation;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\ApprovalQueueService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // The seeders ship demo leave, timesheets and attendance. A queue test
        // has to own every row it counts, so the slate is wiped and rebuilt per
        // test. Timesheets go first: their entries cascade, and each entry holds
        // a restricting reference to the attendance record behind it.
        LeaveRequest::query()->delete();
        Timesheet::query()->delete();
        AttendanceRecord::query()->delete();
    }

    public function test_dashboard_renders_the_approvals_panel(): void
    {
        $this->pendingLeave($this->employeeOutsideNursing());

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Pending approvals')
            ->assertSee('Needs your decision')
            ->assertSee('Leave requests')
            ->assertSee('Timesheets')
            // The queue's whole ordering claim, stated where the reader can see it.
            ->assertSee('Oldest first', false);
    }

    public function test_both_kinds_of_pending_work_are_counted_and_named(): void
    {
        $employee = $this->employeeOutsideNursing();

        $this->pendingLeave($employee);
        $this->submittedTimesheet($employee);

        $queue = app(ApprovalQueueService::class)->forUser($this->manager());

        $this->assertSame(2, $queue['total']);
        $this->assertSame(1, $this->group($queue, 'leave')['count']);
        $this->assertSame(1, $this->group($queue, 'timesheet')['count']);
        $this->assertSame(
            $employee->full_name,
            $this->group($queue, 'leave')['items'][0]['name'],
        );
        $this->assertSame(
            $employee->full_name,
            $this->group($queue, 'timesheet')['items'][0]['name'],
        );
    }

    public function test_the_queue_is_ordered_oldest_first(): void
    {
        [$first, $second] = Employee::query()->where('employment_status', 'active')->orderBy('id')->take(2)->get();

        // Filed in the opposite order to the one they should be listed in, so a
        // newest-first regression cannot pass by accident.
        $this->pendingLeave($second, filedDaysAgo: 1);
        $this->pendingLeave($first, filedDaysAgo: 9);

        $items = $this->group(app(ApprovalQueueService::class)->forUser($this->manager()), 'leave')['items'];

        $this->assertSame($first->full_name, $items[0]['name']);
        $this->assertSame($second->full_name, $items[1]['name']);
        $this->assertSame('9 days', $items[0]['waiting_label']);
        $this->assertSame('1 day', $items[1]['waiting_label']);
    }

    public function test_an_item_left_past_the_threshold_is_flagged_as_stale(): void
    {
        $this->pendingLeave($this->employeeOutsideNursing(), filedDaysAgo: 6);

        $queue = app(ApprovalQueueService::class)->forUser($this->manager());
        $item = $this->group($queue, 'leave')['items'][0];

        $this->assertTrue($item['stale']);
        $this->assertSame(6, $queue['oldest_days']);
        $this->assertSame('6 days', $queue['oldest_label']);
    }

    public function test_something_filed_today_is_not_stale(): void
    {
        $this->pendingLeave($this->employeeOutsideNursing());

        $queue = app(ApprovalQueueService::class)->forUser($this->manager());

        $this->assertFalse($this->group($queue, 'leave')['items'][0]['stale']);
        $this->assertSame('today', $queue['oldest_label']);
    }

    public function test_an_empty_queue_says_so_rather_than_showing_nothing(): void
    {
        $queue = app(ApprovalQueueService::class)->forUser($this->manager());

        $this->assertSame(0, $queue['total']);
        $this->assertNull($queue['oldest_days']);
        $this->assertSame([], $this->group($queue, 'leave')['items']);

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Nothing is waiting on you');
    }

    /**
     * The panel names people, so the department constraint is not a convenience —
     * it is the whole reason a head may be shown this at all.
     */
    public function test_a_department_head_only_sees_their_own_units_queue(): void
    {
        $head = $this->nursingHead();
        $inside = Employee::query()
            ->where('department_id', $head->employee->department_id)
            ->whereKeyNot($head->employee->id)
            ->firstOrFail();
        $outside = $this->employeeOutsideNursing();

        $this->pendingLeave($inside);
        $this->pendingLeave($outside);

        $headQueue = app(ApprovalQueueService::class)->forUser($head);
        $names = array_column($this->group($headQueue, 'leave')['items'], 'name');

        $this->assertSame(1, $headQueue['total']);
        $this->assertContains($inside->full_name, $names);
        $this->assertNotContains($outside->full_name, $names);

        // HR runs the hospital, so both rows are theirs to clear.
        $this->assertSame(2, app(ApprovalQueueService::class)->forUser($this->manager())['total']);
    }

    public function test_an_account_that_supervises_nobody_is_handed_an_empty_queue(): void
    {
        $this->pendingLeave($this->employeeOutsideNursing());

        $staff = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->assertSame(0, app(ApprovalQueueService::class)->forUser($staff)['total']);
    }

    public function test_only_pending_and_submitted_rows_reach_the_queue(): void
    {
        $employee = $this->employeeOutsideNursing();

        $this->pendingLeave($employee, status: 'approved');
        $this->submittedTimesheet($employee, status: 'draft');

        $this->assertSame(0, app(ApprovalQueueService::class)->forUser($this->manager())['total']);
    }

    public function test_attendance_awaiting_approval_reaches_the_queue(): void
    {
        $employee = $this->employeeOutsideNursing();
        $this->pendingAttendance($employee, daysAgo: 4);

        $queue = app(ApprovalQueueService::class)->forUser($this->manager());
        $group = $this->group($queue, 'attendance');

        // Attendance was counted nowhere outside the report's own tile, so a
        // record could wait indefinitely with nothing on the dashboard saying so.
        $this->assertSame(1, $group['count']);
        $this->assertSame(1, $queue['total']);
        $this->assertSame($employee->full_name, $group['items'][0]['name']);
        $this->assertSame('4 days', $group['items'][0]['waiting_label']);
        $this->assertTrue($group['items'][0]['stale']);
    }

    public function test_a_record_still_on_shift_is_not_counted(): void
    {
        // No check-out means the report offers no way to answer it, so a count
        // here would be a number the destination cannot clear.
        $this->pendingAttendance($this->employeeOutsideNursing(), checkedOut: false);

        $this->assertSame(0, app(ApprovalQueueService::class)->forUser($this->manager())['total']);
    }

    public function test_an_already_approved_record_leaves_the_queue(): void
    {
        $this->pendingAttendance($this->employeeOutsideNursing(), approvalStatus: 'approved');

        $this->assertSame(0, app(ApprovalQueueService::class)->forUser($this->manager())['total']);
    }

    public function test_the_attendance_link_reaches_back_to_the_oldest_pending_day(): void
    {
        $record = $this->pendingAttendance($this->employeeOutsideNursing(), daysAgo: 40);

        $url = urldecode($this->group(app(ApprovalQueueService::class)->forUser($this->manager()), 'attendance')['url']);

        // The report defaults to month-to-date, so a bare link would promise a
        // count and then open a screen that does not contain it.
        $this->assertStringContainsString('date_from='.$record->attendance_date->toDateString(), $url);
        $this->assertStringContainsString('approval_status=pending', $url);
    }

    public function test_the_attendance_link_never_exceeds_the_reports_range_cap(): void
    {
        // Older than the report will accept in one range. The link has to open
        // the widest window allowed rather than a validation error.
        $this->pendingAttendance($this->employeeOutsideNursing(), daysAgo: (int) config('reports.max_days') + 30);

        $url = $this->group(app(ApprovalQueueService::class)->forUser($this->manager()), 'attendance')['url'];

        $this->actingAs($this->manager())->get($url)->assertOk()->assertSessionHasNoErrors();
    }

    public function test_a_department_head_only_sees_their_own_units_attendance(): void
    {
        $head = $this->nursingHead();
        $inside = Employee::query()
            ->where('department_id', $head->employee->department_id)
            ->whereKeyNot($head->employee->id)
            ->firstOrFail();

        $this->pendingAttendance($inside);
        $this->pendingAttendance($this->employeeOutsideNursing());

        $this->assertSame(1, app(ApprovalQueueService::class)->forUser($head)['total']);
        $this->assertSame(2, app(ApprovalQueueService::class)->forUser($this->manager())['total']);
    }

    private function pendingAttendance(
        Employee $employee,
        int $daysAgo = 0,
        bool $checkedOut = true,
        string $approvalStatus = 'pending',
    ): AttendanceRecord {
        // Built with now() rather than a zoned Carbon, because that is how
        // AttendanceService writes a real check-out: a zoned instance is stored
        // formatted in its own timezone and read back in the app's, which lands
        // the record eight hours from where the test put it. Placed exactly
        // $daysAgo before now, so the waiting label is a whole number of days
        // whatever time the suite runs.
        $checkOut = now()->subDays($daysAgo);

        return AttendanceRecord::query()->forceCreate([
            'employee_id' => $employee->id,
            'office_location_id' => OfficeLocation::query()->value('id'),
            'attendance_date' => $checkOut->toDateString(),
            'check_in_at' => $checkOut->copy()->subHours(9),
            'check_out_at' => $checkedOut ? $checkOut : null,
            'check_in_method' => 'manual',
            'check_out_method' => $checkedOut ? 'manual' : null,
            'status' => 'present',
            'approval_status' => $approvalStatus,
            'worked_minutes' => 480,
        ]);
    }

    /** @param array<string, mixed> $queue */
    private function group(array $queue, string $key): array
    {
        return collect($queue['groups'])->firstWhere('key', $key);
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    private function nursingHead(): User
    {
        return User::query()->where('email', 'nursing.head@hrms.local')->firstOrFail();
    }

    /** Anybody the nursing head does not supervise, for the scoping tests. */
    private function employeeOutsideNursing(): Employee
    {
        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotNull('department_id')
            ->where('department_id', '!=', $this->nursingHead()->employee->department_id)
            ->orderBy('id')
            ->firstOrFail();
    }

    private function pendingLeave(Employee $employee, int $filedDaysAgo = 0, string $status = 'pending'): LeaveRequest
    {
        $leave = LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => Carbon::now()->addWeek()->toDateString(),
            'end_date' => Carbon::now()->addWeek()->addDay()->toDateString(),
            'requested_days' => 2,
            'reason' => 'Filed for the approval queue test.',
            'status' => $status,
        ]);

        if ($filedDaysAgo > 0) {
            // `created_at` is not fillable, and it is the column the queue orders
            // and ages on, so it is set straight against the row.
            LeaveRequest::query()->whereKey($leave->id)->update([
                'created_at' => Carbon::now()->subDays($filedDaysAgo),
            ]);
        }

        return $leave->refresh();
    }

    private function submittedTimesheet(Employee $employee, string $status = 'submitted'): Timesheet
    {
        return Timesheet::query()->create([
            'employee_id' => $employee->id,
            'period_start' => Carbon::now()->startOfWeek()->toDateString(),
            'period_end' => Carbon::now()->startOfWeek()->addDays(6)->toDateString(),
            'status' => $status,
            'regular_minutes' => 2400,
            'overtime_minutes' => 90,
            'submitted_at' => $status === 'submitted' ? Carbon::now() : null,
        ]);
    }
}

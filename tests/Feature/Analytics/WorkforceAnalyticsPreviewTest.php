<?php

namespace Tests\Feature\Analytics;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\WorkforceAnalyticsPreviewService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkforceAnalyticsPreviewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Friday, 7 August 2026. The month opens on a weekend, so month to date is five
     * workdays (Aug 3-7) against a calendar week -- exactly the gap between "days
     * elapsed" and "days expected" that the rates are built on.
     */
    private const TODAY = '2026-08-07';

    private const WORKDAYS = 5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        // The seeders ship demo attendance and demo leave. Rate tests have to own
        // every row inside the window, so the slate is wiped and rebuilt per test.
        AttendanceRecord::query()->delete();
        LeaveRequest::query()->delete();

        $this->travelTo(Carbon::parse(self::TODAY.' 12:00:00'));
        Cache::flush();
    }

    public function test_dashboard_renders_the_analytics_preview_panel(): void
    {
        $this->workforceOf(2)->each(fn (Employee $employee) => $this->recordAttendance($employee, 3));

        $response = $this->actingAs($this->manager())->get('/dashboard');

        $response
            ->assertOk()
            ->assertSee('Key metrics at a glance')
            ->assertSee('analytics-preview', false);

        foreach (['Attendance rate', 'Leave rate', 'Avg. overtime hours', 'Monthly punctuality', 'Employee utilization'] as $metric) {
            $response->assertSee($metric);
        }
    }

    public function test_the_panel_carries_a_view_analytics_button_onto_the_same_period(): void
    {
        $this->workforceOf(2)->each(fn (Employee $employee) => $this->recordAttendance($employee, 3));

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('View analytics')
            // The module defaults to month to date, and the button says so explicitly
            // rather than trusting both sides to keep defaulting the same way. The
            // href carries an escaped ampersand, so the expectation is escaped too.
            ->assertSee(route('analytics.index', ['date_from' => '2026-08-01', 'date_to' => self::TODAY]));
    }

    public function test_the_button_lands_on_an_analytics_page_the_viewer_may_open(): void
    {
        $this->actingAs($this->manager())
            ->get(route('analytics.index', ['date_from' => '2026-08-01', 'date_to' => self::TODAY]))
            ->assertOk();
    }

    public function test_the_preview_is_withheld_from_a_viewer_who_cannot_open_analytics(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $response = $this->actingAs($employee)->get('/dashboard');

        $response->assertOk()->assertDontSee('Key metrics at a glance');
        $this->assertNull($response->viewData('analyticsPreview'));
    }

    public function test_the_window_is_the_month_to_date_the_analytics_module_defaults_to(): void
    {
        $preview = $this->preview();

        $this->assertSame('2026-08-01', $preview['from']);
        $this->assertSame(self::TODAY, $preview['to']);
        // Aug 1 and 2 are the weekend the month opened on, so they are not expected.
        $this->assertSame(self::WORKDAYS, $preview['workdays']);
    }

    public function test_attendance_rate_measures_check_ins_against_the_expected_workdays(): void
    {
        // Two employees over five workdays is ten employee-days expected. One turns
        // up three times, the other twice, so five of the ten are delivered.
        [$regular, $patchy] = $this->workforceOf(2)->all();
        foreach ([3, 4, 5] as $day) {
            $this->recordAttendance($regular, $day);
        }
        foreach ([3, 4] as $day) {
            $this->recordAttendance($patchy, $day);
        }

        $metric = $this->metric('attendance_rate');

        $this->assertSame(50.0, $metric['value']);
        $this->assertSame('50%', $metric['display']);
        $this->assertStringContainsString('5 of 10 expected employee-days', $metric['detail']);
    }

    public function test_attendance_rate_is_capped_when_more_records_land_than_days_expected(): void
    {
        // A hospital runs weekends. One employee covering all five workdays plus the
        // Saturday and Sunday the month opened on delivers seven of five expected
        // days -- real, and still not a workforce that turned up 140% of the time.
        $this->workforceOf(1)->each(function (Employee $employee): void {
            foreach ([1, 2, 3, 4, 5, 6, 7] as $day) {
                $this->recordAttendance($employee, $day);
            }
        });

        $this->assertSame(100.0, $this->metric('attendance_rate')['value']);
    }

    public function test_employees_outside_the_active_roster_are_left_out(): void
    {
        $this->workforceOf(2);
        $onLeave = Employee::query()->where('employment_status', 'on_leave')->firstOrFail();
        $this->recordAttendance($onLeave, 5);

        $preview = $this->preview();

        // The analytics module reports on active employees only. An `on_leave` record
        // must not inflate the numerator while its owner is absent from the divisor.
        $this->assertSame(2, $preview['headcount']);
        $this->assertSame(0, $preview['records']);
    }

    public function test_leave_rate_counts_only_the_approved_weekdays_inside_the_month(): void
    {
        $employee = $this->workforceOf(2)->first();
        // Runs from the previous month into Tuesday: only Aug 3 and Aug 4 fall inside
        // the window, and the weekend it spans is not a working day it consumed.
        $this->approveLeave($employee, '2026-07-27', '2026-08-04');

        $metric = $this->metric('leave_rate');

        $this->assertSame(20.0, $metric['value']);
        $this->assertStringContainsString('2 approved leave days', $metric['detail']);
    }

    public function test_pending_leave_is_not_counted_against_the_month(): void
    {
        $employee = $this->workforceOf(2)->first();
        $this->approveLeave($employee, '2026-08-03', '2026-08-04', 'pending');

        $this->assertSame(0.0, $this->metric('leave_rate')['value']);
    }

    public function test_punctuality_is_the_share_of_check_ins_that_arrived_on_time(): void
    {
        $employee = $this->workforceOf(2)->first();
        $this->recordAttendance($employee, 3);
        $this->recordAttendance($employee, 4);
        $this->recordAttendance($employee, 5);
        $this->recordAttendance($employee, 6, ['status' => 'late', 'late_minutes' => 25]);

        $metric = $this->metric('punctuality');

        $this->assertSame(75.0, $metric['value']);
        $this->assertStringContainsString('1 late arrival out of 4 check-ins', $metric['detail']);
    }

    public function test_average_overtime_spreads_the_logged_hours_over_the_active_headcount(): void
    {
        $employee = $this->workforceOf(2)->first();
        // Four overtime hours, all worked by one of the two active employees.
        $this->recordAttendance($employee, 3, ['overtime_minutes' => 150]);
        $this->recordAttendance($employee, 4, ['overtime_minutes' => 90]);

        $metric = $this->metric('average_overtime_hours');

        $this->assertSame(2.0, $metric['value']);
        $this->assertSame('2h', $metric['display']);
        $this->assertStringContainsString('4h logged across 2 active employees', $metric['detail']);
    }

    public function test_utilization_compares_worked_time_against_the_standard_daily_capacity(): void
    {
        // Two employees over five workdays is 4,800 minutes of capacity; half is spent.
        $this->workforceOf(2)->each(function (Employee $employee): void {
            foreach ([3, 4, 5] as $day) {
                $this->recordAttendance($employee, $day, ['worked_minutes' => 400]);
            }
        });

        $metric = $this->metric('utilization');

        $this->assertSame(50.0, $metric['value']);
        $this->assertSame(50.0, $metric['share']);
    }

    public function test_utilization_is_reported_past_full_capacity_rather_than_clamped(): void
    {
        $this->workforceOf(1)->each(function (Employee $employee): void {
            foreach ([3, 4, 5, 6, 7] as $day) {
                $this->recordAttendance($employee, $day, ['worked_minutes' => 600]);
            }
        });

        $metric = $this->metric('utilization');

        // A workforce running past its capacity is the finding, so the figure keeps
        // its real value -- only the meter behind it is clamped to a drawable width.
        $this->assertSame(125.0, $metric['value']);
        $this->assertSame(100.0, $metric['share']);
    }

    public function test_the_panel_falls_back_to_an_empty_state_before_anything_is_captured(): void
    {
        $preview = $this->preview();

        $this->assertFalse($preview['tracked']);
        // Every metric is still described, so the panel can explain the quiet month.
        $this->assertCount(5, $preview['metrics']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0], array_column($preview['metrics'], 'value'));

        $this->actingAs($this->manager())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('No attendance or leave has been recorded this month yet.')
            ->assertDontSee('analytics-preview-table', false);
    }

    /** @return array<string, mixed> */
    private function metric(string $key): array
    {
        return collect($this->preview()['metrics'])->firstWhere('key', $key);
    }

    /** @return array<string, mixed> */
    private function preview(): array
    {
        // The service caches per day, so a test that reads it after arranging rows
        // would otherwise be served the empty month the previous read cached.
        Cache::flush();

        return app(WorkforceAnalyticsPreviewService::class)->forCurrentMonth();
    }

    /**
     * Shrink the active roster to a known size so the expected employee-days behind
     * each rate are small enough to state outright.
     *
     * @return Collection<int, Employee>
     */
    private function workforceOf(int $count): Collection
    {
        $keep = Employee::query()
            ->where('employment_status', 'active')
            ->orderBy('id')
            ->take($count)
            ->pluck('id');

        Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $keep)
            ->update(['employment_status' => 'inactive']);

        return Employee::query()->whereIn('id', $keep)->orderBy('id')->get();
    }

    /** @param array<string, mixed> $attributes */
    private function recordAttendance(Employee $employee, int $dayOfMonth, array $attributes = []): void
    {
        $date = Carbon::parse(sprintf('2026-08-%02d', $dayOfMonth));

        AttendanceRecord::query()->create($attributes + [
            'employee_id' => $employee->id,
            'office_location_id' => 1,
            'attendance_date' => $date->toDateString(),
            'check_in_at' => $date->copy()->setTime(8, 0),
            'check_in_method' => 'manual',
            'status' => 'present',
            'worked_minutes' => 480,
            'overtime_minutes' => 0,
        ]);
    }

    private function approveLeave(Employee $employee, string $start, string $end, string $status = 'approved'): void
    {
        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $employee->id,
            'leave_type_id' => LeaveType::query()->value('id'),
            'start_date' => $start,
            'end_date' => $end,
            'requested_days' => 2,
            'reason' => 'Filed for the analytics preview test.',
            'status' => $status,
        ]);
    }

    private function manager(): User
    {
        return User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }
}

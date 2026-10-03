<?php

namespace Tests\Feature\Attendance;

use App\Models\AttendanceRecord;
use App\Models\LeaveRequest;
use App\Models\OfficeLocation;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The employee's own attendance view: today's status, and the last week read as
 * days rather than as whichever rows happen to exist.
 */
class MyAttendancePageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private OfficeLocation $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->user = User::query()->where('email', 'employee@hrms.local')->firstOrFail();
        $this->office = OfficeLocation::query()->where('is_active', true)->firstOrFail();

        // The seeders ship demo attendance, roster and leave. These tests own the
        // employee's week, so it starts empty.
        $employeeId = $this->user->employee->id;
        AttendanceRecord::query()->where('employee_id', $employeeId)->delete();
        ScheduleAssignment::query()->where('employee_id', $employeeId)->delete();
        ScheduleDayOff::query()->where('employee_id', $employeeId)->delete();
        LeaveRequest::query()->where('employee_id', $employeeId)->delete();

        // Mid-morning, so a shift is under way and nothing sits on a day boundary.
        $this->travelTo(Carbon::now($this->office->timezone)->setTime(10, 0));
    }

    public function test_a_week_is_listed_as_seven_days_even_without_records(): void
    {
        $html = $this->actingAs($this->user)->get('/attendance')->assertOk()->getContent();

        $this->assertSame(7, substr_count($html, 'data-label="Date"'));
    }

    public function test_a_late_check_in_is_the_heading_with_its_lateness(): void
    {
        $this->record(Carbon::now($this->office->timezone)->setTime(8, 40), status: 'late', lateMinutes: 40);

        $this->actingAs($this->user)->get('/attendance')
            ->assertOk()
            ->assertSee('Checked in · Late 40m')
            ->assertSee('Late after');
    }

    public function test_a_past_time_in_without_a_time_out_is_raised(): void
    {
        $yesterday = Carbon::now($this->office->timezone)->subDay()->setTime(7, 55);
        $this->record($yesterday);

        $this->actingAs($this->user)->get('/attendance')
            ->assertOk()
            ->assertSee('1 record needs attention.')
            ->assertSee($yesterday->format('M j'))
            ->assertSee('No time-out');
    }

    public function test_todays_open_time_in_is_not_treated_as_missing(): void
    {
        $this->record(Carbon::now($this->office->timezone)->setTime(7, 55));

        $this->actingAs($this->user)->get('/attendance')
            ->assertOk()
            ->assertSee('Checked in · On time')
            ->assertDontSee('needs attention')
            ->assertDontSee('No time-out');
    }

    public function test_a_rest_day_says_so_instead_of_a_shift(): void
    {
        ScheduleDayOff::query()->create([
            'employee_id' => $this->user->employee->id,
            'work_date' => Carbon::now($this->office->timezone)->toDateString(),
            'source' => 'manual',
        ]);

        $this->actingAs($this->user)->get('/attendance')
            ->assertOk()
            ->assertSee('Rest day')
            ->assertSee('You have no shift today.')
            ->assertDontSee('Late after');
    }

    /**
     * The page refreshes itself by re-reading its own URL and pairing the live
     * panels up by position, so the count and the order are load-bearing: four
     * panels, one of them the slot the missing-time-out banner arrives in.
     */
    public function test_the_page_marks_the_panels_a_refresh_replaces(): void
    {
        $html = $this->actingAs($this->user)->get('/attendance')->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'data-attendance-live'));

        // The slot is there whether or not it has a banner in it; with a clean
        // week it has none, and the refresh still has something to swap.
        $this->assertStringContainsString('attendance-exception-slot', $html);
        $this->assertStringNotContainsString('needs attention', $html);
    }

    /**
     * What the browser's five-second poll compares. A punch recorded anywhere
     * else -- a terminal, the entrance scanner, HR closing an open day -- has to
     * move this, or the page never learns it is out of date.
     */
    public function test_the_state_endpoint_revision_moves_when_a_punch_lands(): void
    {
        $before = $this->actingAs($this->user)->getJson(route('attendance.state'))
            ->assertOk()->json('revision');

        $this->assertNotNull($before);
        $this->assertSame(
            $before,
            $this->actingAs($this->user)->getJson(route('attendance.state'))->json('revision'),
            'An unchanged week must report an unchanged revision, or the page refreshes every five seconds for nothing.',
        );

        $this->record(Carbon::now($this->office->timezone)->setTime(7, 55));

        $afterCheckIn = $this->actingAs($this->user)->getJson(route('attendance.state'))
            ->assertOk()->json('revision');

        $this->assertNotSame($before, $afterCheckIn);

        // Checking out edits the row it already wrote rather than adding one, so
        // a revision built on the row count alone would miss the time-out.
        $this->travel(4)->hours();
        AttendanceRecord::query()->where('employee_id', $this->user->employee->id)
            ->firstOrFail()
            ->update(['check_out_at' => now(), 'worked_minutes' => 240]);

        $this->assertNotSame(
            $afterCheckIn,
            $this->actingAs($this->user)->getJson(route('attendance.state'))->json('revision'),
        );
    }

    /**
     * The settings page polls the same endpoint for the capture mode alone, and
     * the administrator reading it need not be an employee at all.
     */
    public function test_the_state_endpoint_serves_a_user_without_an_employee_record(): void
    {
        $user = User::query()->whereDoesntHave('employee')->first()
            ?? User::factory()->create();

        $this->actingAs($user)->getJson(route('attendance.state'))
            ->assertOk()
            ->assertJson(['revision' => null]);
    }

    private function record(Carbon $checkIn, string $status = 'present', int $lateMinutes = 0): void
    {
        AttendanceRecord::query()->create([
            'employee_id' => $this->user->employee->id,
            'office_location_id' => $this->office->id,
            'attendance_date' => $checkIn->toDateString(),
            'check_in_at' => $checkIn->copy()->utc(),
            'check_in_method' => 'biometric',
            'status' => $status,
            'late_minutes' => $lateMinutes,
        ]);
    }
}

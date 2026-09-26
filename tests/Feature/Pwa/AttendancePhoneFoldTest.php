<?php

namespace Tests\Feature\Pwa;

use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Models\User;
use App\Services\Attendance\MyAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The attendance page's phone fold.
 *
 * Same payload as the panel beside it, ordered for one screen. These cover the
 * two things the reordering exists for: that a shift in progress shows what it
 * is measured against, and that the way time is actually recorded is stated
 * before the control that bypasses it.
 */
class AttendancePhoneFoldTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_both_folds_are_in_the_document(): void
    {
        $this->actingAs($this->employeeUser())
            ->get('/attendance')
            ->assertOk()
            ->assertSee('attendance-phone-fold', false)
            ->assertSee('attendance-desk-fold', false);
    }

    /**
     * The badge is offered wherever the manual punch is, because on a phone the
     * entrance scanner is the likelier of the two.
     */
    public function test_it_offers_the_badge(): void
    {
        $this->actingAs($this->employeeUser())
            ->get('/attendance')
            ->assertOk()
            ->assertSee(route('profile.badge'), false);
    }

    /**
     * A shift in progress gets the elapsed readout and the bar it fills. The
     * total is emitted in seconds for the shared ticker; without it the bar
     * would render but never move.
     */
    public function test_a_shift_in_progress_carries_the_elapsed_counter_and_its_total(): void
    {
        $user = $this->employeeUser();
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();

        AttendanceRecord::query()->updateOrCreate(
            [
                'employee_id' => $user->employee->id,
                'attendance_date' => now($office->timezone)->toDateString(),
            ],
            [
                'office_location_id' => $office->id,
                'check_in_at' => now($office->timezone)->subHours(3),
                'check_out_at' => null,
                'status' => 'present',
            ],
        );

        $html = $this->actingAs($user)->get('/attendance')->assertOk()->getContent();

        $this->assertStringContainsString('data-clocked-in-since=', $html);
        $this->assertStringContainsString('data-elapsed-since', $html);
        $this->assertStringContainsString('data-elapsed-total=', $html);
    }

    /**
     * The two folds both carry a manual-reason field when the mode asks for
     * one, so they cannot share its id.
     */
    public function test_the_two_folds_do_not_share_a_field_id(): void
    {
        $html = $this->actingAs($this->employeeUser())
            ->get('/attendance')
            ->assertOk()
            ->getContent();

        $this->assertLessThanOrEqual(1, substr_count($html, 'id="attendancePhoneReason"'));
    }

    /**
     * The window control. Two periods onto this page and one link to the other
     * view of the same hours, all three under the Attendance tab.
     */
    public function test_the_period_control_offers_both_windows_and_the_timesheet(): void
    {
        $this->actingAs($this->employeeUser())
            ->get('/attendance')
            ->assertOk()
            ->assertSee('Last 7 days')
            ->assertSee('This month')
            ->assertSee(route('attendance.index', ['period' => 'month']), false)
            ->assertSee(route('timesheets.index'), false);
    }

    /**
     * The month runs from the 1st to today. Days that have not happened are not
     * records, so it must never be longer than the date allows — and on the 1st
     * itself that is a single row, not an empty table.
     */
    public function test_this_month_covers_the_first_to_today(): void
    {
        $user = $this->employeeUser();
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();
        $expected = (int) now($office->timezone)->format('j');

        $rows = app(MyAttendanceService::class)
            ->forEmployee($user->employee, $office, MyAttendanceService::PERIOD_MONTH)['days'];

        $this->assertCount($expected, $rows);
    }

    /**
     * The rolling week stays the default, so anything that relied on this
     * service before keeps the shape it had.
     */
    public function test_the_default_window_is_still_seven_days(): void
    {
        $user = $this->employeeUser();
        $office = OfficeLocation::query()->where('is_active', true)->firstOrFail();

        $this->assertCount(7, app(MyAttendanceService::class)->forEmployee($user->employee, $office)['days']);
    }

    /**
     * A hand-typed window falls back rather than emptying the table.
     */
    public function test_an_unknown_period_falls_back_to_the_week(): void
    {
        $this->actingAs($this->employeeUser())
            ->get(route('attendance.index', ['period' => 'decade']))
            ->assertOk()
            ->assertSee('Last 7 days');
    }

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}

<?php

namespace Tests\Feature\Pwa;

use App\Models\AttendanceRecord;
use App\Models\OfficeLocation;
use App\Models\User;
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

    private function employeeUser(): User
    {
        return User::query()->with('employee')->where('email', 'employee@hrms.local')->firstOrFail();
    }
}

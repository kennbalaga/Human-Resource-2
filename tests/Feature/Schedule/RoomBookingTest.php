<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Room;
use App\Models\RoomBooking;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RoomBookingService;
use App\Services\Scheduling\RoomRosterService;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Theatre lists: holding a room for a case by the clock rather than by the shift.
 */
class RoomBookingTest extends TestCase
{
    use RefreshDatabase;

    private Room $theatre;

    private Shift $morning;

    private User $manager;

    private Department $surgery;

    private Position $surgeon;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->surgery = Department::query()->where('code', 'SURG')->firstOrFail();
        $this->theatre = Room::query()->where('code', 'OR-1')->firstOrFail();
        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->date = now(config('schedule.timezone'))->addWeek()->toDateString();

        $this->surgeon = Position::query()->create([
            'department_id' => $this->surgery->id,
            'code' => 'RB-SURGEON',
            'title' => 'Surgeon',
            'seniority_rank' => 5,
            'is_active' => true,
        ]);
    }

    public function test_it_holds_a_room_for_a_case(): void
    {
        $booking = $this->book('08:00', '12:00', 'Appendectomy');

        $this->assertSame($this->theatre->id, $booking->room_id);
        $this->assertSame(RoomBooking::STATUS_PLANNED, $booking->status);
        $this->assertSame('08:00 – 12:00', $booking->window);
    }

    /**
     * The rule the whole booking table exists for: two cases must not hold one
     * theatre over the same minute.
     */
    public function test_two_cases_cannot_overlap_in_one_theatre(): void
    {
        $this->book('08:00', '12:00', 'Appendectomy');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already held from 08:00 – 12:00');

        $this->book('11:00', '14:00', 'Cholecystectomy');
    }

    /**
     * A list that hands over on the hour is the normal case, not a clash.
     */
    public function test_cases_that_touch_end_to_end_are_allowed(): void
    {
        $this->book('08:00', '12:00', 'Appendectomy');
        $second = $this->book('12:00', '15:00', 'Hernia repair');

        $this->assertSame('12:00 – 15:00', $second->window);
    }

    public function test_a_stood_down_case_frees_the_room_again(): void
    {
        $first = $this->book('08:00', '12:00', 'Appendectomy');

        app(RoomBookingService::class)->cancel($first, $this->manager, 'Patient unwell.');

        $replacement = $this->book('09:00', '11:00', 'Emergency laparotomy');

        $this->assertSame(RoomBooking::STATUS_CANCELLED, $first->fresh()->status);
        $this->assertTrue($replacement->exists);
    }

    public function test_a_case_cannot_end_before_it_starts(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('must end after it starts');

        $this->book('14:00', '09:00', 'Overnight list');
    }

    public function test_a_closed_theatre_cannot_be_booked(): void
    {
        $this->theatre->update(['status' => Room::STATUS_MAINTENANCE]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Under maintenance');

        $this->book('08:00', '12:00', 'Appendectomy');
    }

    /**
     * Scrubbing somebody to a case places them in the room as well. Leaving the
     * two to be set separately is how they end up disagreeing.
     */
    public function test_scrubbing_someone_to_a_case_also_puts_them_in_the_room(): void
    {
        $booking = $this->book('08:00', '12:00', 'Appendectomy');
        $surgeon = $this->employee('RB-0001');
        $assignment = $this->roster($surgeon);

        app(RoomBookingService::class)->attach($booking, $surgeon, $this->manager);

        $assignment->refresh();

        $this->assertSame($booking->id, $assignment->room_booking_id);
        $this->assertSame($this->theatre->id, $assignment->room_id);
    }

    public function test_somebody_not_on_duty_cannot_be_scrubbed(): void
    {
        $booking = $this->book('08:00', '12:00', 'Appendectomy');
        $surgeon = $this->employee('RB-0002');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not rostered on');

        app(RoomBookingService::class)->attach($booking, $surgeon, $this->manager);
    }

    public function test_standing_a_case_down_leaves_the_teams_duty_alone(): void
    {
        $booking = $this->book('08:00', '12:00', 'Appendectomy');
        $surgeon = $this->employee('RB-0003');
        $assignment = $this->roster($surgeon);

        $service = app(RoomBookingService::class);
        $service->attach($booking, $surgeon, $this->manager);
        $service->cancel($booking, $this->manager);

        $assignment->refresh();

        $this->assertNull($assignment->room_booking_id);
        $this->assertSame('scheduled', $assignment->status);
        $this->assertSame($this->theatre->id, $assignment->room_id);
    }

    public function test_a_manager_can_book_and_stand_down_a_case_from_the_board(): void
    {
        $this->actingAs($this->manager)
            ->post(route('schedules.room-bookings.store', $this->theatre), [
                'work_date' => $this->date,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'purpose' => 'Appendectomy',
            ])
            ->assertRedirect();

        $booking = RoomBooking::query()->where('purpose', 'Appendectomy')->firstOrFail();

        $this->actingAs($this->manager)
            ->delete(route('schedules.room-bookings.destroy', $booking))
            ->assertRedirect();

        $this->assertSame(RoomBooking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    /**
     * The theatre-list panel only appears on a board that has a theatre on it,
     * so it needs a board with one to be exercised at all.
     */
    public function test_the_board_shows_the_days_theatre_list(): void
    {
        $booking = $this->book('08:00', '12:00', 'Appendectomy');

        $this->actingAs($this->manager)
            ->get(route('schedules.rooms.index', ['department_id' => $this->surgery->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('Theatre lists')
            ->assertSee('Appendectomy')
            ->assertSee($booking->window, false)
            ->assertSee('0 scrubbed');
    }

    public function test_a_case_with_nobody_scrubbed_is_flagged_on_the_board(): void
    {
        $this->book('08:00', '12:00', 'Appendectomy');

        $finding = app(RoomRosterService::class)
            ->findings($this->surgery, $this->date)
            ->firstWhere('code', 'case_unstaffed');

        $this->assertNotNull($finding);
        $this->assertStringContainsString('Appendectomy', $finding['message']);
    }

    public function test_a_standard_employee_cannot_book_a_theatre(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)
            ->post(route('schedules.room-bookings.store', $this->theatre), [
                'work_date' => $this->date,
                'start_time' => '08:00',
                'end_time' => '12:00',
                'purpose' => 'Appendectomy',
            ])
            ->assertForbidden();
    }

    private function book(string $start, string $end, string $purpose): RoomBooking
    {
        return app(RoomBookingService::class)->book($this->theatre->fresh(), [
            'work_date' => $this->date,
            'start_time' => $start,
            'end_time' => $end,
            'purpose' => $purpose,
        ], $this->manager);
    }

    private function employee(string $number): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->surgery->id,
            'position_id' => $this->surgeon->id,
            'employee_number' => $number,
            'first_name' => 'Theatre',
            'last_name' => 'Surgeon '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function roster(Employee $employee): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->morning->id,
            'work_date' => $this->date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));
    }
}

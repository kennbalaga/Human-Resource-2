<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Position;
use App\Models\Room;
use App\Models\ScheduleAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RoomRosterService;
use App\Services\Scheduling\RosterWriteContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The board for one unit on one day, and the findings it is graded against.
 */
class RoomBoardTest extends TestCase
{
    use RefreshDatabase;

    private Department $unit;

    private Position $chargeNurse;

    private Position $juniorNurse;

    private Shift $morning;

    private Shift $night;

    private User $manager;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->unit = Department::query()->create([
            'code' => 'BOARD-T',
            'name' => 'Board Test Unit',
            'category' => Department::CATEGORY_CLINICAL,
            'is_active' => true,
        ]);

        $this->chargeNurse = Position::query()->create([
            'department_id' => $this->unit->id,
            'code' => 'BT-CHARGE',
            'title' => 'Head Nurse',
            'seniority_rank' => 4,
            'is_active' => true,
        ]);

        $this->juniorNurse = Position::query()->create([
            'department_id' => $this->unit->id,
            'code' => 'BT-JUNIOR',
            'title' => 'Nurse I',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);

        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $this->night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->date = '2027-05-10';
    }

    public function test_an_empty_room_that_should_be_running_is_reported_as_unstaffed(): void
    {
        $this->room('BT-WARD', ['room_type' => 'ward', 'min_seniority_rank' => 1]);

        $this->assertTrue(
            $this->findings()->where('code', 'room_unstaffed')->isNotEmpty(),
            'A running room with nobody in it should be reported.',
        );
    }

    /**
     * The finding that would otherwise fire every night for every clinic room,
     * and in doing so teach everybody to ignore the console.
     */
    public function test_a_room_that_is_dark_for_a_shift_files_no_finding_for_it(): void
    {
        $clinic = $this->room('BT-CLINIC', ['room_type' => 'clinic', 'min_seniority_rank' => 1]);
        $clinic->shiftRequirements()->create(['shift_id' => $this->night->id, 'operates' => false]);

        $nightFindings = $this->findings()->where('shift_id', $this->night->id);

        $this->assertTrue($nightFindings->isEmpty(), 'A dark shift should be silent, not unstaffed.');
    }

    public function test_a_closed_room_with_people_in_it_blocks(): void
    {
        $room = $this->room('BT-CLOSED', ['status' => Room::STATUS_MAINTENANCE, 'min_seniority_rank' => 1]);
        $nurse = $this->employee('BT-0001', $this->chargeNurse);
        $this->roster($nurse, $this->morning, $room);

        $finding = $this->findings()->firstWhere('code', 'room_unavailable');

        $this->assertNotNull($finding);
        $this->assertSame('blocker', $finding['level']);
    }

    public function test_a_theatre_without_charge_cover_blocks(): void
    {
        $theatre = $this->room('BT-OR', ['room_type' => 'operating', 'min_seniority_rank' => 3]);
        $junior = $this->employee('BT-0002', $this->juniorNurse);
        $this->roster($junior, $this->morning, $theatre);

        $finding = $this->findings()->firstWhere('code', 'no_charge_cover');

        $this->assertNotNull($finding, 'A theatre covered only by entry level must not pass.');
        $this->assertSame('blocker', $finding['level']);
    }

    public function test_a_theatre_with_charge_cover_passes(): void
    {
        $theatre = $this->room('BT-OR2', ['room_type' => 'operating', 'min_seniority_rank' => 3]);
        $charge = $this->employee('BT-0003', $this->chargeNurse);
        $this->roster($charge, $this->morning, $theatre);

        $this->assertNull(
            $this->findings()->where('room_id', $theatre->id)->firstWhere('code', 'no_charge_cover'),
        );
    }

    public function test_going_past_the_room_capacity_blocks(): void
    {
        $room = $this->room('BT-SMALL', ['max_staff' => 1, 'min_seniority_rank' => 1]);
        $this->roster($this->employee('BT-0004', $this->chargeNurse), $this->morning, $room);
        $this->roster($this->employee('BT-0005', $this->chargeNurse), $this->morning, $room);

        $finding = $this->findings()->firstWhere('code', 'room_at_capacity');

        $this->assertNotNull($finding);
        $this->assertSame('blocker', $finding['level']);
    }

    public function test_approved_leave_on_the_day_blocks_the_placement(): void
    {
        $room = $this->room('BT-LEAVE', ['min_seniority_rank' => 1]);
        $nurse = $this->employee('BT-0006', $this->chargeNurse);
        $this->roster($nurse, $this->morning, $room);

        LeaveRequest::query()->create([
            'uuid' => (string) Str::uuid(),
            'employee_id' => $nurse->id,
            'leave_type_id' => LeaveType::query()->firstOrFail()->id,
            'start_date' => $this->date,
            'end_date' => $this->date,
            'requested_days' => 1,
            'reason' => 'Unwell.',
            'status' => 'approved',
        ]);

        $finding = $this->findings()->firstWhere('code', 'on_leave');

        $this->assertNotNull($finding, 'Leave approved after rostering leaves a stale room placement behind.');
        $this->assertSame('blocker', $finding['level']);
    }

    public function test_rostered_staff_with_no_room_are_counted_rather_than_ignored(): void
    {
        $this->room('BT-WARD2', ['min_seniority_rank' => 1]);
        $this->roster($this->employee('BT-0007', $this->chargeNurse), $this->morning, null);

        $board = app(RoomRosterService::class)->board($this->unit, $this->date);

        $this->assertSame(1, $board['summary']['unplaced']);
        $this->assertNotNull(collect($board['findings'])->firstWhere('code', 'unplaced_staff'));
    }

    public function test_a_blocker_makes_the_board_unpublishable(): void
    {
        $theatre = $this->room('BT-OR3', ['room_type' => 'operating', 'min_seniority_rank' => 3]);
        $this->roster($this->employee('BT-0008', $this->juniorNurse), $this->morning, $theatre);

        $board = app(RoomRosterService::class)->board($this->unit, $this->date);

        $this->assertFalse($board['summary']['publishable']);
        $this->assertGreaterThan(0, $board['summary']['blockers']);
    }

    public function test_the_board_page_renders_for_a_manager(): void
    {
        $room = $this->room('BT-VIEW', ['min_seniority_rank' => 1]);
        $this->roster($this->employee('BT-0009', $this->chargeNurse), $this->morning, $room);

        $this->actingAs($this->manager)
            ->get(route('schedules.rooms.index', ['department_id' => $this->unit->id, 'date' => $this->date]))
            ->assertOk()
            ->assertSee('Room board')
            ->assertSee('BT-VIEW')
            ->assertSee('Morning Shift');
    }

    /**
     * The board is a clinical instrument. An administrative unit is never
     * offered, even when somebody has managed to attach a room to it.
     */
    public function test_the_board_never_offers_an_administrative_unit(): void
    {
        $finance = Department::query()->create([
            'code' => 'FIN-T',
            'name' => 'Finance Section (test)',
            'category' => Department::CATEGORY_ADMINISTRATIVE,
            'is_active' => true,
        ]);

        // Attached directly, bypassing the form, so this tests the board rather
        // than the validation that would normally have refused it.
        Room::query()->create([
            'department_id' => $finance->id,
            'code' => 'FIN-MEET',
            'name' => 'Finance meeting room',
            'room_type' => 'clinic',
            'min_seniority_rank' => 1,
            'status' => Room::STATUS_ACTIVE,
            'is_active' => true,
        ]);

        $this->room('BT-CLINICAL', ['min_seniority_rank' => 1]);

        $this->actingAs($this->manager)
            ->get(route('schedules.rooms.index'))
            ->assertOk()
            ->assertDontSee('Finance Section (test)');
    }

    public function test_asking_for_an_administrative_unit_by_id_is_refused(): void
    {
        $finance = Department::query()->create([
            'code' => 'FIN-T2',
            'name' => 'Finance Section (test 2)',
            'category' => Department::CATEGORY_ADMINISTRATIVE,
            'is_active' => true,
        ]);

        $this->actingAs($this->manager)
            ->get(route('schedules.rooms.index', ['department_id' => $finance->id]))
            ->assertForbidden();
    }

    public function test_a_standard_employee_cannot_open_the_board(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get(route('schedules.rooms.index'))->assertForbidden();
    }

    public function test_a_manager_can_place_and_clear_a_room_from_the_board(): void
    {
        $room = $this->room('BT-POST', ['min_seniority_rank' => 1]);
        $nurse = $this->employee('BT-0010', $this->chargeNurse);
        $assignment = $this->roster($nurse, $this->morning, null);

        $this->actingAs($this->manager)
            ->post(route('schedules.rooms.store'), [
                'room_id' => $room->id,
                'shift_id' => $this->morning->id,
                'employee_id' => $nurse->id,
                'date' => $this->date,
            ])
            ->assertRedirect();

        $this->assertSame($room->id, $assignment->fresh()->room_id);

        $this->actingAs($this->manager)
            ->delete(route('schedules.rooms.destroy', $assignment))
            ->assertRedirect();

        $this->assertNull($assignment->fresh()->room_id);
        $this->assertSame('scheduled', $assignment->fresh()->status);
    }

    public function test_the_candidate_endpoint_lists_the_shifts_roster(): void
    {
        $room = $this->room('BT-PICK', ['min_seniority_rank' => 1]);
        $nurse = $this->employee('BT-0011', $this->chargeNurse);
        $this->roster($nurse, $this->morning, null);

        $this->actingAs($this->manager)
            ->getJson(route('schedules.rooms.candidates', [
                'room' => $room->id,
                'shift' => $this->morning->id,
                'date' => $this->date,
            ]))
            ->assertOk()
            ->assertJsonPath('candidates.0.employee_id', $nurse->id)
            ->assertJsonPath('candidates.0.reason', null);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function findings(): Collection
    {
        return app(RoomRosterService::class)->findings($this->unit, $this->date);
    }

    /** @param array<string, mixed> $attributes */
    private function room(string $code, array $attributes = []): Room
    {
        return Room::query()->create($attributes + [
            'department_id' => $this->unit->id,
            'code' => $code,
            'name' => 'Room '.$code,
            'room_type' => 'ward',
            'min_seniority_rank' => 1,
            'status' => Room::STATUS_ACTIVE,
            'is_active' => true,
        ]);
    }

    private function employee(string $number, Position $position): Employee
    {
        return Employee::query()->create([
            'department_id' => $this->unit->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Board',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function roster(Employee $employee, Shift $shift, ?Room $room): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $shift->id,
            'room_id' => $room?->id,
            'work_date' => $this->date,
            'status' => 'scheduled',
            'created_by' => $this->manager->id,
            'created_via' => 'manual',
        ]));
    }
}

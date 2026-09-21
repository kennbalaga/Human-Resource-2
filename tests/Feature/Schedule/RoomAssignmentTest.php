<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Room;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleAssignmentAudit;
use App\Models\Shift;
use App\Models\User;
use App\Services\Scheduling\RoomAssignmentService;
use App\Services\Scheduling\RosterWriteContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Placing a rostered shift in a room, and the placements a ward could not honour.
 */
class RoomAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $surgery;

    private Position $chargeNurse;

    private Position $staffNurse;

    private Shift $morning;

    private User $actor;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->surgery = Department::query()->create([
            'code' => 'SURG-T',
            'name' => 'Department of Surgery (test)',
            'category' => Department::CATEGORY_CLINICAL,
            'is_active' => true,
        ]);

        $this->chargeNurse = Position::query()->create([
            'department_id' => $this->surgery->id,
            'code' => 'T-CHARGE',
            'title' => 'Head Nurse',
            'seniority_rank' => 4,
            'is_active' => true,
        ]);

        $this->staffNurse = Position::query()->create([
            'department_id' => $this->surgery->id,
            'code' => 'T-STAFF',
            'title' => 'Staff Nurse',
            'seniority_rank' => 1,
            'is_active' => true,
        ]);

        $this->morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();
        $this->actor = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
        $this->date = Carbon::parse('2027-04-05')->toDateString();
    }

    public function test_it_places_a_rostered_shift_in_a_room(): void
    {
        $room = $this->room('TOR-1', ['max_staff' => 2]);
        $nurse = $this->employee('T-0001', $this->chargeNurse);
        $this->roster($nurse);

        $assignment = $this->service()->assign($room, $this->morning, $this->date, $nurse, $this->actor);

        $this->assertSame($room->id, $assignment->room_id);
        $this->assertFalse($assignment->cross_unit);
    }

    /**
     * The boundary this whole service exists to hold. The room board places
     * people who are already on duty; it is not a second way onto the roster.
     */
    public function test_it_refuses_to_place_somebody_who_is_not_rostered(): void
    {
        $room = $this->room('TOR-2');
        $nurse = $this->employee('T-0002', $this->chargeNurse);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is not rostered on the Morning Shift');

        $this->service()->assign($room, $this->morning, $this->date, $nurse, $this->actor);
    }

    public function test_it_refuses_to_overfill_a_room(): void
    {
        $room = $this->room('TOR-3', ['max_staff' => 1]);
        $first = $this->employee('T-0003', $this->chargeNurse);
        $second = $this->employee('T-0004', $this->staffNurse);
        $this->roster($first);
        $this->roster($second);

        $this->service()->assign($room, $this->morning, $this->date, $first, $this->actor);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('TOR-3 holds 1');

        $this->service()->assign($room, $this->morning, $this->date, $second, $this->actor);
    }

    public function test_it_refuses_a_room_that_is_closed(): void
    {
        $room = $this->room('TOR-4', ['status' => Room::STATUS_MAINTENANCE]);
        $nurse = $this->employee('T-0005', $this->chargeNurse);
        $this->roster($nurse);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Under maintenance');

        $this->service()->assign($room, $this->morning, $this->date, $nurse, $this->actor);
    }

    public function test_it_refuses_a_shift_the_room_is_dark_for(): void
    {
        $room = $this->room('TOPD-1');
        $room->shiftRequirements()->create(['shift_id' => $this->morning->id, 'operates' => false]);

        $nurse = $this->employee('T-0006', $this->chargeNurse);
        $this->roster($nurse);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('does not run the Morning Shift');

        $this->service()->assign($room->fresh(), $this->morning, $this->date, $nurse, $this->actor);
    }

    /**
     * Borrowing is normal and stays allowed; what changes is that the roster
     * keeps a record of it rather than losing it.
     */
    public function test_borrowing_from_another_unit_is_allowed_and_recorded(): void
    {
        $medicine = Department::query()->create([
            'code' => 'IM-T',
            'name' => 'Department of Internal Medicine (test)',
            'category' => Department::CATEGORY_CLINICAL,
            'is_active' => true,
        ]);

        $room = $this->room('TOR-5');
        $nurse = $this->employee('T-0007', $this->chargeNurse, $medicine);
        $this->roster($nurse);

        $assignment = $this->service()->assign($room, $this->morning, $this->date, $nurse, $this->actor);

        $this->assertTrue($assignment->cross_unit);
    }

    public function test_a_move_between_rooms_is_recorded_with_both_rooms(): void
    {
        $from = $this->room('TOR-6');
        $to = $this->room('TRR-1');
        $nurse = $this->employee('T-0008', $this->chargeNurse);
        $this->roster($nurse);

        $service = $this->service();
        $service->assign($from, $this->morning, $this->date, $nurse, $this->actor);
        $assignment = $service->assign($to, $this->morning, $this->date, $nurse, $this->actor);

        $audit = ScheduleAssignmentAudit::query()
            ->where('schedule_assignment_id', $assignment->id)
            ->where('action', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertTrue($audit->context['room_changed']);
        $this->assertSame($from->id, $audit->context['room_id_from']);
        $this->assertSame($to->id, $audit->context['room_id_to']);
        $this->assertSame($this->actor->id, $audit->actor_id);
    }

    public function test_taking_someone_out_of_a_room_leaves_their_duty_alone(): void
    {
        $room = $this->room('TOR-7');
        $nurse = $this->employee('T-0009', $this->chargeNurse);
        $this->roster($nurse);

        $service = $this->service();
        $assignment = $service->assign($room, $this->morning, $this->date, $nurse, $this->actor);
        $cleared = $service->unassign($assignment, $this->actor);

        $this->assertNull($cleared->room_id);
        $this->assertSame('scheduled', $cleared->status);
        $this->assertDatabaseHas('schedule_assignments', [
            'id' => $assignment->id,
            'employee_id' => $nurse->id,
            'shift_id' => $this->morning->id,
        ]);
    }

    /**
     * A room move carries no hours, so unlike a roster edit it is allowed today.
     * Yesterday is still closed: that would be rewriting history.
     */
    public function test_a_room_can_be_changed_today_but_not_yesterday(): void
    {
        $room = $this->room('TOR-8');
        $today = now(config('schedule.timezone'))->startOfDay();

        $nurse = $this->employee('T-0010', $this->chargeNurse);
        $this->roster($nurse, $today->toDateString());
        $this->service()->assign($room, $this->morning, $today->toDateString(), $nurse, $this->actor);

        $yesterdayNurse = $this->employee('T-0011', $this->chargeNurse);
        $this->roster($yesterdayNurse, $today->copy()->subDay()->toDateString());

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('already passed');

        $this->service()->assign($room, $this->morning, $today->copy()->subDay()->toDateString(), $yesterdayNurse, $this->actor);
    }

    public function test_the_candidate_list_says_why_somebody_is_unavailable(): void
    {
        $theatre = $this->room('TOR-9');
        $recovery = $this->room('TRR-2');

        $placed = $this->employee('T-0012', $this->chargeNurse);
        $free = $this->employee('T-0013', $this->staffNurse);
        $this->roster($placed);
        $this->roster($free);

        $service = $this->service();
        $service->assign($recovery, $this->morning, $this->date, $placed, $this->actor);

        $candidates = $service->candidates($theatre, $this->morning, $this->date)->keyBy(fn (array $row) => $row['employee']->id);

        $this->assertNull($candidates[$free->id]['reason']);
        $this->assertStringContainsString('TRR-2', $candidates[$placed->id]['reason']);
    }

    private function service(): RoomAssignmentService
    {
        return app(RoomAssignmentService::class);
    }

    /** @param array<string, mixed> $attributes */
    private function room(string $code, array $attributes = []): Room
    {
        return Room::query()->create($attributes + [
            'department_id' => $this->surgery->id,
            'code' => $code,
            'name' => 'Room '.$code,
            'room_type' => 'operating',
            'min_seniority_rank' => 3,
            'status' => Room::STATUS_ACTIVE,
            'is_active' => true,
        ]);
    }

    private function employee(string $number, Position $position, ?Department $department = null): Employee
    {
        return Employee::query()->create([
            'department_id' => ($department ?? $this->surgery)->id,
            'position_id' => $position->id,
            'employee_number' => $number,
            'first_name' => 'Theatre',
            'last_name' => 'Nurse '.$number,
            'employment_status' => 'active',
            'hire_date' => '2024-01-01',
        ]);
    }

    private function roster(Employee $employee, ?string $date = null): ScheduleAssignment
    {
        return RosterWriteContext::allowUnattended(fn () => ScheduleAssignment::query()->create([
            'employee_id' => $employee->id,
            'shift_id' => $this->morning->id,
            'work_date' => $date ?? $this->date,
            'status' => 'scheduled',
            'created_by' => $this->actor->id,
            'created_via' => 'manual',
        ]));
    }
}

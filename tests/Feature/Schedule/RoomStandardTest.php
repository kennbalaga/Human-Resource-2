<?php

namespace Tests\Feature\Schedule;

use App\Models\Department;
use App\Models\Room;
use App\Models\Shift;
use App\Services\Scheduling\StaffingRequirementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What one shift of one room must be staffed to.
 */
class RoomStandardTest extends TestCase
{
    use RefreshDatabase;

    private Department $surgery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->surgery = Department::query()->create([
            'code' => 'SURG-T',
            'name' => 'Department of Surgery (test)',
            'category' => Department::CATEGORY_CLINICAL,
            'bed_capacity' => 48,
            'nurse_patient_ratio' => 12,
            'is_active' => true,
        ]);
    }

    public function test_a_bedded_room_derives_its_own_staffing_from_its_own_beds(): void
    {
        $room = $this->room(['code' => 'WARD-T', 'room_type' => 'ward', 'bed_capacity' => 24]);
        $shift = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        // 24 beds at the unit's 1:12 ratio is two in the room -- not the unit's
        // own figure of four, which covers all of its rooms together.
        $this->assertSame(2, $room->derivedMinimumStaffPerShift());

        $requirement = app(StaffingRequirementService::class)->forRoomShift($room, $shift);

        $this->assertSame(2, $requirement['staff']);
        $this->assertSame('room beds and unit ratio', $requirement['source']);
    }

    /**
     * The one thing a room standard must never do. A department's figure covers
     * the whole unit; copying it into each of its rooms would demand several
     * wards' worth of nurses to staff one ward.
     */
    public function test_a_room_never_inherits_the_whole_units_figure(): void
    {
        $theatre = $this->room(['code' => 'OR-T', 'room_type' => 'operating', 'bed_capacity' => null]);
        $shift = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->assertSame(4, $this->surgery->derivedMinimumStaffPerShift());

        $requirement = app(StaffingRequirementService::class)->forRoomShift($theatre, $shift);

        $this->assertSame(1, $requirement['staff']);
        $this->assertSame('default minimum', $requirement['source']);
    }

    public function test_a_recorded_room_standard_beats_the_derived_figure(): void
    {
        $room = $this->room(['code' => 'WARD-T2', 'room_type' => 'ward', 'bed_capacity' => 24]);
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();

        $room->shiftRequirements()->create([
            'shift_id' => $night->id,
            'operates' => true,
            'minimum_staff' => 5,
            'minimum_senior' => 2,
        ]);

        $requirement = app(StaffingRequirementService::class)->forRoomShift($room->fresh(), $night);

        $this->assertSame(5, $requirement['staff']);
        $this->assertSame(2, $requirement['senior']);
        $this->assertSame('room shift standard', $requirement['source']);
    }

    /**
     * A theatre with no coverage grid filled in still has to refuse an
     * all-entry-level list, or the safest default would be the one nobody set.
     */
    public function test_a_restricted_room_expects_charge_cover_without_a_grid(): void
    {
        $theatre = $this->room(['code' => 'OR-T2', 'room_type' => 'operating', 'min_seniority_rank' => 3]);
        $shift = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->assertSame(1, app(StaffingRequirementService::class)->forRoomShift($theatre, $shift)['senior']);
    }

    public function test_a_dark_shift_is_recorded_rather_than_inferred(): void
    {
        $clinic = $this->room(['code' => 'OPD-T', 'room_type' => 'clinic']);
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $clinic->shiftRequirements()->create(['shift_id' => $night->id, 'operates' => false]);

        $requirements = app(StaffingRequirementService::class);

        $this->assertFalse($requirements->forRoomShift($clinic->fresh(), $night)['operates']);
        $this->assertTrue($requirements->forRoomShift($clinic->fresh(), $morning)['operates']);
    }

    /** @param array<string, mixed> $attributes */
    private function room(array $attributes): Room
    {
        return Room::query()->create($attributes + [
            'department_id' => $this->surgery->id,
            'name' => 'Test room '.($attributes['code'] ?? 'X'),
            'room_type' => 'ward',
            'min_seniority_rank' => 1,
            'status' => Room::STATUS_ACTIVE,
            'is_active' => true,
        ]);
    }
}

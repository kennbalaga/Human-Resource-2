<?php

namespace Tests\Feature\Organization;

use App\Models\Department;
use App\Models\Room;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recording the hospital's rooms.
 */
class RoomDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();

        $this->manager = User::query()->where('email', 'hr.manager@hrms.local')->firstOrFail();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('rooms.index'))->assertRedirect('/login');
    }

    public function test_the_seeded_hospital_rooms_are_listed(): void
    {
        $this->actingAs($this->manager)
            ->get(route('rooms.index'))
            ->assertOk()
            ->assertSee('Rooms')
            ->assertSee('OR-1')
            ->assertSee('Operating room');
    }

    /**
     * Operating Room and Delivery Room used to be departments and were deleted
     * as such. They belong to a unit now, and nothing should have put them back.
     */
    public function test_a_theatre_is_a_room_of_a_unit_and_not_a_unit_of_its_own(): void
    {
        $theatre = Room::query()->where('code', 'OR-1')->firstOrFail();

        $this->assertNotNull($theatre->department);
        $this->assertSame('SURG', $theatre->department->code);
        $this->assertFalse(Department::query()->where('code', 'OR')->exists());
    }

    public function test_a_manager_can_record_a_room(): void
    {
        $unit = Department::query()->where('code', 'IM')->firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('rooms.store'), [
                'department_id' => $unit->id,
                'code' => 'ward-b',
                'name' => 'Medical Ward B',
                'room_type' => 'ward',
                'bed_capacity' => 24,
                'max_staff' => 8,
                'min_seniority_rank' => 2,
                'status' => Room::STATUS_ACTIVE,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('rooms', ['code' => 'WARD-B', 'bed_capacity' => 24]);
    }

    /**
     * A blank charge-cover field on a theatre must not ship a theatre that
     * accepts an all-entry-level list.
     */
    public function test_a_new_theatre_defaults_to_charge_level_cover(): void
    {
        $unit = Department::query()->where('code', 'SURG')->firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('rooms.store'), [
                'department_id' => $unit->id,
                'code' => 'OR-3',
                'name' => 'Operating Room 3',
                'room_type' => 'operating',
                'min_seniority_rank' => '',
                'status' => Room::STATUS_ACTIVE,
                'is_active' => '1',
            ])
            ->assertRedirect();

        $this->assertSame(
            Room::THEATRE_MINIMUM_RANK,
            Room::query()->where('code', 'OR-3')->firstOrFail()->min_seniority_rank,
        );
    }

    public function test_room_codes_stay_unique(): void
    {
        $unit = Department::query()->where('code', 'SURG')->firstOrFail();

        $this->actingAs($this->manager)
            ->post(route('rooms.store'), [
                'department_id' => $unit->id,
                'code' => 'OR-1',
                'name' => 'Duplicate theatre',
                'room_type' => 'operating',
                'min_seniority_rank' => 3,
                'status' => Room::STATUS_ACTIVE,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('code');
    }

    /**
     * A room is a clinical fact. Finance has offices, not wards, and nothing
     * good comes of somebody modelling a meeting room as one.
     */
    public function test_a_room_cannot_belong_to_an_administrative_unit(): void
    {
        $finance = Department::query()->where('code', 'FIN')->firstOrFail();
        $this->assertSame(Department::CATEGORY_ADMINISTRATIVE, $finance->category);

        $this->actingAs($this->manager)
            ->post(route('rooms.store'), [
                'department_id' => $finance->id,
                'code' => 'FIN-1',
                'name' => 'Finance meeting room',
                'room_type' => 'clinic',
                'min_seniority_rank' => 1,
                'status' => Room::STATUS_ACTIVE,
                'is_active' => '1',
            ])
            ->assertSessionHasErrors('department_id');

        $this->assertDatabaseMissing('rooms', ['code' => 'FIN-1']);
    }

    public function test_the_room_form_only_offers_clinical_units(): void
    {
        $this->actingAs($this->manager)
            ->get(route('rooms.create'))
            ->assertOk()
            ->assertSee('Department of Surgery')
            ->assertDontSee('Finance and Accounting Section')
            ->assertDontSee('Human Resource Management Section');
    }

    public function test_every_seeded_room_sits_in_a_clinical_unit(): void
    {
        $offenders = Room::query()
            ->whereHas('department', fn ($q) => $q->where('category', '!=', Department::CATEGORY_CLINICAL))
            ->pluck('code');

        $this->assertTrue($offenders->isEmpty(), 'Rooms outside a clinical unit: '.$offenders->implode(', '));
    }

    public function test_a_standard_employee_cannot_record_a_room(): void
    {
        $employee = User::query()->where('email', 'employee@hrms.local')->firstOrFail();

        $this->actingAs($employee)->get(route('rooms.create'))->assertForbidden();
    }

    public function test_a_manager_can_mark_a_shift_the_room_is_dark_for(): void
    {
        $room = Room::query()->where('code', 'OPD-C1')->firstOrFail();
        $night = Shift::query()->where('code', 'NIGHT-2200')->firstOrFail();
        $morning = Shift::query()->where('code', 'MORNING-0600')->firstOrFail();

        $this->actingAs($this->manager)
            ->put(route('rooms.shift-coverage.update', $room), [
                'requirements' => [
                    $morning->id => ['operates' => '1', 'minimum_staff' => 2, 'minimum_senior' => 1],
                    // No 'operates' key at all: the checkbox was cleared.
                    $night->id => ['minimum_staff' => '', 'minimum_senior' => 0],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('room_shift_requirements', [
            'room_id' => $room->id,
            'shift_id' => $night->id,
            'operates' => false,
        ]);

        $this->assertDatabaseHas('room_shift_requirements', [
            'room_id' => $room->id,
            'shift_id' => $morning->id,
            'operates' => true,
            'minimum_staff' => 2,
        ]);
    }
}

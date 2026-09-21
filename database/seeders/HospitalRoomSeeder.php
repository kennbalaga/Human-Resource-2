<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Room;
use App\Models\RoomShiftRequirement;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * The hospital's actual rooms, hung off the units that staff them.
 *
 * Operating Room, Delivery Room and ICU used to be departments here and were
 * deleted in 2026_08_11_000047 because they are places, not units. This puts
 * them back where they belong — under Surgery, under Obstetrics, under Internal
 * Medicine — so a roster can say a nurse is in OR-1 without claiming she
 * transferred there.
 */
class HospitalRoomSeeder extends Seeder
{
    /**
     * Keyed by department code. `dark` lists the shifts a room does not run, so
     * the board stops reporting an outpatient clinic as unstaffed every night.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    private const ROOMS = [
        'SURG' => [
            ['code' => 'OR-1', 'name' => 'Operating Room 1', 'room_type' => 'operating', 'max_staff' => 6, 'min_seniority_rank' => 3, 'minimum_staff' => 4],
            ['code' => 'OR-2', 'name' => 'Operating Room 2', 'room_type' => 'operating', 'max_staff' => 6, 'min_seniority_rank' => 3, 'minimum_staff' => 4, 'dark' => ['NIGHT-2200']],
            ['code' => 'RR-1', 'name' => 'Post-Anaesthesia Recovery Room', 'room_type' => 'recovery', 'bed_capacity' => 8, 'max_staff' => 4, 'min_seniority_rank' => 2],
            ['code' => 'WARD-SURG', 'name' => 'Surgical Ward', 'room_type' => 'ward', 'bed_capacity' => 24, 'max_staff' => 8, 'min_seniority_rank' => 2],
        ],
        'OB-GYN' => [
            ['code' => 'DR-1', 'name' => 'Delivery Room 1', 'room_type' => 'delivery', 'max_staff' => 5, 'min_seniority_rank' => 3, 'minimum_staff' => 3],
            ['code' => 'DR-2', 'name' => 'Delivery Room 2', 'room_type' => 'delivery', 'max_staff' => 5, 'min_seniority_rank' => 3, 'minimum_staff' => 3],
            ['code' => 'WARD-OB', 'name' => 'Maternity Ward', 'room_type' => 'ward', 'bed_capacity' => 20, 'max_staff' => 6, 'min_seniority_rank' => 2],
        ],
        'IM' => [
            ['code' => 'WARD-A', 'name' => 'Medical Ward A', 'room_type' => 'ward', 'bed_capacity' => 30, 'max_staff' => 8, 'min_seniority_rank' => 2],
            ['code' => 'ISO-1', 'name' => 'Isolation Room 1', 'room_type' => 'isolation', 'bed_capacity' => 4, 'max_staff' => 3, 'min_seniority_rank' => 2],
        ],
        'PEDS' => [
            ['code' => 'WARD-PED', 'name' => 'Pediatric Ward', 'room_type' => 'ward', 'bed_capacity' => 18, 'max_staff' => 6, 'min_seniority_rank' => 2],
        ],
        'ER' => [
            ['code' => 'ER-TRIAGE', 'name' => 'Triage Area', 'room_type' => 'treatment', 'max_staff' => 4, 'min_seniority_rank' => 2, 'minimum_staff' => 2],
            ['code' => 'ER-RESUS', 'name' => 'Resuscitation Bay', 'room_type' => 'procedure', 'bed_capacity' => 4, 'max_staff' => 6, 'min_seniority_rank' => 3, 'minimum_staff' => 3],
        ],
        'OPD' => [
            ['code' => 'OPD-C1', 'name' => 'Clinic Room 1', 'room_type' => 'clinic', 'max_staff' => 2, 'min_seniority_rank' => 1, 'dark' => ['NIGHT-2200']],
            ['code' => 'OPD-C2', 'name' => 'Clinic Room 2', 'room_type' => 'clinic', 'max_staff' => 2, 'min_seniority_rank' => 1, 'dark' => ['NIGHT-2200']],
        ],
        'DERM' => [
            ['code' => 'DERM-TX', 'name' => 'Dermatology Treatment Room', 'room_type' => 'treatment', 'max_staff' => 3, 'min_seniority_rank' => 2, 'dark' => ['NIGHT-2200']],
        ],
    ];

    public function run(): void
    {
        // Clinical only, and filtered here rather than trusted from the list
        // above: a unit that has been reclassified since this seeder was
        // written must stop receiving rooms, not keep them because its code
        // still appears in an array.
        $departments = Department::query()
            ->clinical()
            ->whereIn('code', array_keys(self::ROOMS))
            ->get()
            ->keyBy('code');

        $shifts = Shift::query()->get()->keyBy('code');

        foreach (self::ROOMS as $departmentCode => $rooms) {
            $department = $departments->get($departmentCode);

            // A department this install never created is skipped rather than
            // failing the seed — the structure differs between environments.
            if ($department === null) {
                continue;
            }

            foreach ($rooms as $definition) {
                $dark = $definition['dark'] ?? [];
                $minimumStaff = $definition['minimum_staff'] ?? null;

                $room = Room::query()->updateOrCreate(
                    ['code' => $definition['code']],
                    [
                        'department_id' => $department->id,
                        'name' => $definition['name'],
                        'room_type' => $definition['room_type'],
                        'bed_capacity' => $definition['bed_capacity'] ?? null,
                        'max_staff' => $definition['max_staff'] ?? null,
                        'min_seniority_rank' => $definition['min_seniority_rank'],
                        'status' => Room::STATUS_ACTIVE,
                        'is_active' => true,
                    ],
                );

                // A requirement row is written only where the room departs from
                // the derived figure or goes dark. Everything else is left to
                // the standard, so the grid stays a record of decisions rather
                // than a copy of the defaults.
                foreach ($shifts as $code => $shift) {
                    $isDark = in_array($code, $dark, true);

                    if (! $isDark && $minimumStaff === null) {
                        continue;
                    }

                    RoomShiftRequirement::query()->updateOrCreate(
                        ['room_id' => $room->id, 'shift_id' => $shift->id],
                        [
                            'operates' => ! $isDark,
                            'minimum_staff' => $isDark ? null : $minimumStaff,
                            'minimum_senior' => $definition['min_seniority_rank'] > 1 ? 1 : 0,
                        ],
                    );
                }
            }
        }
    }
}

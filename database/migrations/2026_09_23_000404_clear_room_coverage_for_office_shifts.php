<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rooms are clinical places, so they answer only to the rotation's legs.
 *
 * The room board and the room's coverage standard used to carry a column and a
 * row for every active shift, the standalone office day included. Nobody was
 * ever placed in a theatre on an 8-to-5 office shift — the people who work it
 * sit in administrative units, which have no rooms — so that column graded
 * itself unstaffed every day against a figure no room was going to meet, and
 * the coverage form invited a standard for a shift no room runs.
 *
 * The screens no longer offer it. This clears what the old ones recorded:
 * coverage rows for shifts a room cannot be staffed on, and any placement that
 * was made against one before the route started refusing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $officeShiftIds = DB::table('shifts')->where('is_rotating', false)->pluck('id')->all();

        if ($officeShiftIds === []) {
            return;
        }

        DB::table('room_shift_requirements')->whereIn('shift_id', $officeShiftIds)->delete();

        // The duty itself stays exactly where it is; only the room, and the
        // case that was scrubbed inside it, come off.
        DB::table('schedule_assignments')
            ->whereIn('shift_id', $officeShiftIds)
            ->where(fn ($query) => $query->whereNotNull('room_id')->orWhereNotNull('room_booking_id'))
            ->update(['room_id' => null, 'room_booking_id' => null, 'cross_unit' => false]);
    }

    /**
     * Nothing to put back. The cleared rows said a room was staffed on a shift
     * it is not worked on, and restoring that would only recreate the findings
     * this removed.
     */
    public function down(): void {}
};

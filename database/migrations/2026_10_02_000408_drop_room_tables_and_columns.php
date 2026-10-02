<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms leave the system: they belong to the hospital information system, not
 * to workforce management.
 *
 * The four migrations that built `rooms`, `room_shift_requirements`,
 * `room_bookings` and the two columns on `schedule_assignments` are gone, so a
 * fresh install never creates any of this and every guard below is a no-op
 * there. A database that was already migrated still carries them, and this is
 * what takes them off it.
 *
 * `cross_unit` goes with the rooms. It only ever recorded that somebody was
 * borrowed from another unit *to stand in a room*, and nothing wrote it except
 * the room placement service.
 *
 * Order matters on the way out, in two ways. The child columns on
 * `schedule_assignments` hold the foreign keys, so they go before the tables
 * they point at, and `room_bookings` before `rooms` for the same reason. And
 * within the `room_id` column itself: MySQL satisfies the foreign key's index
 * requirement with the composite (room_id, work_date) index, so dropping that
 * index while the constraint still exists fails with error 1553. The
 * constraint goes first, then the index, then the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedule_assignments')) {
            Schema::table('schedule_assignments', function (Blueprint $table): void {
                if (Schema::hasColumn('schedule_assignments', 'room_booking_id')) {
                    $table->dropConstrainedForeignId('room_booking_id');
                }
            });

            if (Schema::hasColumn('schedule_assignments', 'room_id')) {
                Schema::table('schedule_assignments', function (Blueprint $table): void {
                    $table->dropForeign(['room_id']);
                });

                Schema::table('schedule_assignments', function (Blueprint $table): void {
                    $table->dropIndex(['room_id', 'work_date']);
                });

                Schema::table('schedule_assignments', function (Blueprint $table): void {
                    $table->dropColumn('room_id');
                });
            }

            Schema::table('schedule_assignments', function (Blueprint $table): void {
                if (Schema::hasColumn('schedule_assignments', 'cross_unit')) {
                    $table->dropColumn('cross_unit');
                }
            });
        }

        Schema::dropIfExists('room_bookings');
        Schema::dropIfExists('room_shift_requirements');
        Schema::dropIfExists('rooms');
    }

    /**
     * Nothing to put back. The code that read these tables no longer exists, so
     * recreating them would leave empty structures nothing can fill.
     */
    public function down(): void {}
};

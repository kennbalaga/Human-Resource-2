<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a rostered shift is actually worked.
 *
 * Nullable on purpose, and it will stay nullable. Plenty of duty has no room:
 * administrative staff, on-call cover, a float nurse who goes where the night
 * takes her. Forcing a room on every assignment would mean inventing a fake one
 * for all of them, and a fake room is worse than an honest null.
 *
 * `cross_unit` records that the person was borrowed from another department.
 * That is allowed — a hospital lends staff constantly — so the roster does not
 * refuse it; it keeps a note of it, which is what makes the lending auditable
 * instead of invisible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table): void {
            $table->foreignId('room_id')
                ->nullable()
                ->after('shift_id')
                ->constrained('rooms')
                ->nullOnDelete();

            $table->boolean('cross_unit')
                ->default(false)
                ->after('room_id')
                ->comment('True when the room belongs to a unit other than the employee own.');

            $table->index(['room_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table): void {
            $table->dropIndex(['room_id', 'work_date']);
            $table->dropConstrainedForeignId('room_id');
            $table->dropColumn('cross_unit');
        });
    }
};

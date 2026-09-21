<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A theatre list: the room held for one case, between two clock times.
 *
 * Wards are staffed by the shift, so `schedule_assignments.room_id` says
 * everything there is to say about them. A theatre is not: two cases can run in
 * one operating room across a single morning, and the room is reserved for each
 * in turn. That reservation is a thing in its own right -- it has a start, an
 * end, a purpose and a surgeon leading it -- and it exists whether or not the
 * team around it has been named yet, which is exactly why it cannot be a column
 * on somebody's duty row.
 *
 * `shift_id` is nullable because a list is timed by the clock, not by the shift
 * pattern; it is recorded when the list plainly belongs to one, so the board can
 * show it in the right column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->date('work_date');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('purpose');

            $table->foreignId('lead_employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete()
                ->comment('The surgeon or attending answerable for the case.');

            $table->string('status', 20)
                ->default('planned')
                ->index()
                ->comment('planned, confirmed, completed or cancelled.');

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'work_date']);
            $table->index(['work_date', 'status']);
        });

        Schema::table('schedule_assignments', function (Blueprint $table): void {
            // Which case on the list this person is scrubbed for. Null for all
            // ward duty, which is most of it.
            $table->foreignId('room_booking_id')
                ->nullable()
                ->after('room_id')
                ->constrained('room_bookings')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('room_booking_id');
        });

        Schema::dropIfExists('room_bookings');
    }
};

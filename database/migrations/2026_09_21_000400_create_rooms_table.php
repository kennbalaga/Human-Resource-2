<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A room is a physical place inside a unit, not a unit of its own.
 *
 * The distinction is load-bearing here. Operating Room, Delivery Room and ICU
 * were once carried as departments and were removed in
 * 2026_08_11_000047_restructure_departments_to_hospital_structure, because a
 * theatre does not hire, does not hold positions and does not appear on an
 * organisational chart — it is a place the Department of Surgery staffs. Giving
 * rooms their own table lets a roster say where somebody stands without
 * pretending they transferred units to get there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->restrictOnDelete();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('room_type', 20)->index();

            $table->unsignedSmallInteger('bed_capacity')
                ->nullable()
                ->comment('Licensed beds. Null marks a room that is not bedded, such as a theatre or a clinic.');

            $table->unsignedTinyInteger('max_staff')
                ->nullable()
                ->comment('Most people who can work in here at once. Null means the room sets no ceiling of its own.');

            $table->unsignedTinyInteger('min_seniority_rank')
                ->default(1)
                ->comment('Lowest positions.seniority_rank that counts as charge cover for this room.');

            $table->string('status', 20)
                ->default('active')
                ->index()
                ->comment('active, maintenance or closed. Anything but active refuses new assignments.');

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['department_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rooms');
    }
};

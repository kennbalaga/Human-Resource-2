<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one shift of one room must be staffed to — and, first of all, whether the
 * room runs that shift at all.
 *
 * The second question is the one a department-level standard cannot answer. An
 * outpatient clinic room is dark after the afternoon list and a second theatre
 * is dark overnight, so a board that assumed every room runs every shift would
 * raise an unstaffed finding against both of them every single night. Nobody
 * reads a console that cries every night, so `operates` is recorded here beside
 * the numbers rather than inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_shift_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();

            $table->boolean('operates')
                ->default(true)
                ->comment('False marks a shift this room is dark for, so it is never reported as unstaffed.');

            $table->unsignedSmallInteger('minimum_staff')
                ->nullable()
                ->comment('Overrides the figure derived from the room beds and the unit ratio.');

            $table->unsignedTinyInteger('minimum_senior')
                ->default(0)
                ->comment('Charge-level staff who must be in the room, so no theatre is left to entry level alone.');

            $table->timestamps();

            $table->unique(['room_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_shift_requirements');
    }
};

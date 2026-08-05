<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A government hospital does not staff a shift by picking a number at rostering
 * time: each bedded unit carries a standing requirement derived from its bed
 * capacity and the nurse-to-patient ratio the DOH licences it under. Storing the
 * standard makes the roster answerable to a policy instead of to whoever typed
 * the number that day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('bed_capacity')
                ->nullable()
                ->after('category')
                ->comment('Licensed beds. Null marks a unit that is not bedded, such as an office.');

            $table->unsignedSmallInteger('nurse_patient_ratio')
                ->nullable()
                ->after('bed_capacity')
                ->comment('Patients per nurse on duty. The DOH general-ward standard is 12.');
        });

        Schema::create('department_shift_requirements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('minimum_staff')
                ->nullable()
                ->comment('Overrides the figure derived from beds and ratio when a shift is staffed differently.');

            $table->unsignedTinyInteger('minimum_senior')
                ->default(1)
                ->comment('Charge-level staff who must be on duty, so no shift is left to entry level alone.');

            $table->timestamps();

            $table->unique(['department_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_shift_requirements');

        Schema::table('departments', function (Blueprint $table): void {
            $table->dropColumn(['bed_capacity', 'nurse_patient_ratio']);
        });
    }
};

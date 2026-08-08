<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing signal for the rotation planner and AI candidate scoring to read —
 * not a workflow, just a preference the employee can change anytime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->foreignId('preferred_shift_id')
                ->nullable()
                ->after('supervisor_id')
                ->constrained('shifts')
                ->nullOnDelete();
            $table->unsignedTinyInteger('preferred_weekly_off_day')
                ->nullable()
                ->after('preferred_shift_id')
                ->comment('ISO weekday 1 (Monday) through 7 (Sunday), the employee\'s standing preferred rest day.');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('preferred_shift_id');
            $table->dropColumn('preferred_weekly_off_day');
        });
    }
};

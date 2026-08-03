<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var array<int, array<string, mixed>> */
    private const STANDARD_SHIFTS = [
        ['code' => 'MORNING-0600', 'name' => 'Morning Shift', 'start_time' => '06:00', 'end_time' => '14:00', 'break_minutes' => 60, 'color' => '#2F80ED'],
        ['code' => 'AFTERNOON-1400', 'name' => 'Afternoon Shift', 'start_time' => '14:00', 'end_time' => '22:00', 'break_minutes' => 60, 'color' => '#F2994A'],
        ['code' => 'NIGHT-2200', 'name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00', 'break_minutes' => 60, 'color' => '#334155'],
        ['code' => 'ADMIN-0800', 'name' => 'Administrative Shift', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'color' => '#176B43'],
    ];

    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        $standardCodes = array_column(self::STANDARD_SHIFTS, 'code');
        $obsoleteShiftIds = DB::table('shifts')->whereNotIn('code', $standardCodes)->pluck('id');

        if ($obsoleteShiftIds->isNotEmpty()) {
            // schedule_recommendation_decisions cascades from schedule_recommendations,
            // so clearing recommendations, assignments, and recurring schedules is enough
            // to satisfy the restrictOnDelete foreign keys pointing at these shifts.
            DB::table('schedule_recommendations')->whereIn('target_shift_id', $obsoleteShiftIds)->delete();
            DB::table('schedule_assignments')->whereIn('shift_id', $obsoleteShiftIds)->delete();
            DB::table('recurring_schedules')->whereIn('shift_id', $obsoleteShiftIds)->delete();
            DB::table('shifts')->whereIn('id', $obsoleteShiftIds)->delete();
        }

        $now = now();

        foreach (self::STANDARD_SHIFTS as $shift) {
            DB::table('shifts')->updateOrInsert(
                ['code' => $shift['code']],
                $shift + ['is_active' => true, 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('shifts')->whereIn('code', array_column(self::STANDARD_SHIFTS, 'code'))->delete();

        Schema::table('shifts', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};

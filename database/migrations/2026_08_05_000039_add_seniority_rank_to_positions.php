<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Government hospital rosters are judged on skill mix, not head count alone: a
 * shift covered entirely by entry-level staff is short a charge nurse even when
 * the minimum staffing number is met. Ranking each position on the familiar
 * Nurse I-V ladder lets scheduling check seniority alongside the count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->unsignedTinyInteger('seniority_rank')
                ->default(1)
                ->after('title')
                ->comment('1 = entry level, higher = more senior (Nurse I-V style ladder).');
        });

        // Department heads outrank the staff they supervise, so they start at the
        // top of the ladder instead of the entry-level default.
        DB::table('positions')
            ->where('title', 'like', '%Head%')
            ->orWhere('title', 'like', '%Manager%')
            ->update(['seniority_rank' => 4]);
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table): void {
            $table->dropColumn('seniority_rank');
        });
    }
};

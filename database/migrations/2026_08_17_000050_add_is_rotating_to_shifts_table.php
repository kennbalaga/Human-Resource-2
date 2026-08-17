<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a shift is one leg of a round-the-clock rotation or covers its unit
 * on its own.
 *
 * Morning, Afternoon and Night tile a 24-hour day: rostering one of them alone
 * leaves the other two thirds of the day uncovered, so the assistant asks for
 * at least two. The Administrative Shift is a standalone 8-to-5 office day —
 * there is no second leg to pair it with, and no night to limit — so it is
 * complete by itself.
 *
 * Nothing in the data said which was which; this is that distinction, on the
 * template rather than guessed from a code or a name, so a new 8-to-5 shift
 * created later can say so too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('is_rotating')->default(true)->after('is_system');
        });

        // The one standard template that stands alone. Everything else keeps
        // the rotating default, which is what the three clinical legs are.
        DB::table('shifts')->where('code', 'ADMIN-0800')->update(['is_rotating' => false]);
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('is_rotating');
        });
    }
};

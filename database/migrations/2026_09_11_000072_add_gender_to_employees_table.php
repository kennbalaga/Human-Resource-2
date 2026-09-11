<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recorded for one reason: maternity and paternity leave are sex-specific
 * entitlements under Philippine law, and leave eligibility cannot be checked
 * without it. Nullable, because a record HR has not completed should read as
 * unknown rather than as an answer -- the leave check treats it that way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('gender', 10)->nullable()->after('suffix');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn('gender');
        });
    }
};

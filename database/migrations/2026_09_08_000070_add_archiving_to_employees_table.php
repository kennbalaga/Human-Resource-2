<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archiving, which is deliberately not deletion.
     *
     * A terminated employee's record is the evidence behind every timesheet,
     * leave balance and attendance row they ever produced, so it stays in the
     * database for good. What HR actually needs is for the directory to stop
     * being a list of everyone who has ever worked here — so the record is
     * marked archived and filtered out of the day-to-day listing, still whole
     * and still readable under the Archived filter.
     *
     * `archived_by` is kept because "who put this away, and when" is the
     * question asked months later, when the person who did it has moved on.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('employment_status');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            // The directory filters on this on every page load.
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');
        });
    }
};

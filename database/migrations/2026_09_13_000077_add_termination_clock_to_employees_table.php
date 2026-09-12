<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The clock that runs between "this person has left" and "file the record".
     *
     * Archiving used to be available on anybody, which meant a colleague who
     * was merely on leave could be filed away by one mis-aimed click. It is now
     * the second half of a termination, and these two columns are what make the
     * gap between the two halves survivable.
     *
     * `terminated_at` is when the record entered the terminated status, not when
     * somebody typed a date into a form: it is stamped by the model on the save
     * that changes the status, and cleared if the status ever moves back off
     * terminated. Without it there is no honest way to say "thirty days later" —
     * `updated_at` moves every time anyone corrects a phone number.
     *
     * `archive_hold_at` keeps the sweep from fighting a human: a record
     * terminated months ago and deliberately restored would otherwise be swept
     * straight back onto the shelf that night, mid-review. It is renamed to
     * `restored_at` by the very next migration, which also settles what it
     * means — a delay of one more window, not an exemption from the sweep.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->timestamp('terminated_at')->nullable()->after('employment_status');
            $table->timestamp('archive_hold_at')->nullable()->after('archived_by');
            // The nightly sweep reads this column and nothing else to find its
            // candidates.
            $table->index('terminated_at');
        });

        // Records already sitting in Terminated have no stamp, and inventing
        // today's date for them would restart a clock that ran out long ago.
        // `updated_at` is the only evidence in the row of when anything last
        // changed about it, so it stands in — imprecise for a record edited
        // since, but never later than the truth by more than that edit.
        DB::table('employees')
            ->where('employment_status', 'terminated')
            ->whereNull('terminated_at')
            ->update(['terminated_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['terminated_at']);
            $table->dropColumn(['terminated_at', 'archive_hold_at']);
        });
    }
};

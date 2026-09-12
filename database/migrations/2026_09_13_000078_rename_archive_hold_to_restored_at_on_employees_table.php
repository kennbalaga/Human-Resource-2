<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hold was the wrong idea, and the wrong name for it.
     *
     * It was written as an exemption: restore a record and the nightly sweep
     * would never touch it again. That turns one forgotten click into a record
     * that sits in the directory forever — which is the exact failure the
     * thirty-day sweep exists to prevent, reintroduced through the back door.
     *
     * What restoring should buy is *time*, not immunity. So the column keeps
     * its data and loses its policy: `restored_at` records the plain fact of
     * when somebody pulled the record back out, and the sweep counts thirty
     * days from the later of that and the termination. Forget to re-archive
     * and it happens on its own, a month later, exactly as it would have the
     * first time.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('archive_hold_at', 'restored_at');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->renameColumn('restored_at', 'archive_hold_at');
        });
    }
};

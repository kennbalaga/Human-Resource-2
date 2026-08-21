<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $nullCount = DB::table('schedule_assignments')->whereNull('created_by')->count();

        if ($nullCount > 0) {
            $migrationActorId = DB::table('users')->where('email', 'admin@hrms.local')->value('id');

            if ($migrationActorId === null) {
                throw new RuntimeException(
                    'Cannot backfill schedule_assignments.created_by: the designated migration actor (admin@hrms.local) was not found.',
                );
            }

            DB::table('schedule_assignments')->whereNull('created_by')->update(['created_by' => $migrationActorId]);
        }

        // MySQL refuses to make a column NOT NULL while its FK carries an
        // ON DELETE SET NULL action (error 1830) — that action could never
        // fire again. A human who has authored roster history shouldn't be
        // hard-deletable anyway, so the constraint becomes RESTRICT here.
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable(false)->change();
        });

        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->change();
        });

        Schema::table('schedule_assignments', function (Blueprint $table) {
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }
};

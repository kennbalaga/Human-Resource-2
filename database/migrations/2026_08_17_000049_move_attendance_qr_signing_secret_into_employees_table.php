<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Badges were signed with the installation's APP_KEY, which is generated per
 * install and never shared. Two machines working off this same database
 * therefore issued and expected different codes for the same person, so a badge
 * downloaded on one was refused at the other's scanner.
 *
 * The secret moves here, alongside the employee it belongs to, so every
 * installation reading this database agrees on what that person's badge is.
 * Rotating the secret is what retires a leaked badge, which is what the old
 * revision counter was for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('attendance_qr_secret', 64)->nullable()->after('employment_status');
        });

        // Existing staff keep working without anyone reissuing anything by hand.
        DB::table('employees')->orderBy('id')->select('id')->chunk(200, function ($employees) {
            foreach ($employees as $employee) {
                DB::table('employees')
                    ->where('id', $employee->id)
                    ->update(['attendance_qr_secret' => Str::random(64)]);
            }
        });

        if (Schema::hasColumn('employees', 'attendance_qr_revision')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('attendance_qr_revision');
            });
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('attendance_qr_secret');
            $table->unsignedInteger('attendance_qr_revision')->default(1)->after('employment_status');
        });
    }
};

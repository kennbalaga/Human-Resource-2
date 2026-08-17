<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The revision an employee's attendance QR code is signed against. Bumping it
 * invalidates every printed copy of that person's code at once, which is the
 * only recall available once a badge has been photographed or lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedInteger('attendance_qr_revision')->default(1)->after('employment_status');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('attendance_qr_revision');
        });
    }
};

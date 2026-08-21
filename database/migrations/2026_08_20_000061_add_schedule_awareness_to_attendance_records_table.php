<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('schedule_assignment_id')->nullable()->after('office_location_id')
                ->constrained('schedule_assignments')->nullOnDelete();
            $table->dateTime('shift_start_at')->nullable()->after('schedule_assignment_id');
            $table->dateTime('shift_end_at')->nullable()->after('shift_start_at');
            $table->string('binding_source', 20)->default('scheduled')->after('shift_end_at');
            $table->string('schedule_status', 20)->default('on_shift')->after('binding_source');
            $table->unsignedInteger('early_minutes')->default(0)->after('schedule_status');
            $table->foreignId('override_authorised_by')->nullable()->after('early_minutes')
                ->constrained('users')->nullOnDelete();
            $table->text('override_reason')->nullable()->after('override_authorised_by');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_authorised_by');
            $table->dropConstrainedForeignId('schedule_assignment_id');
            $table->dropColumn([
                'shift_start_at',
                'shift_end_at',
                'binding_source',
                'schedule_status',
                'early_minutes',
                'override_reason',
            ]);
        });
    }
};

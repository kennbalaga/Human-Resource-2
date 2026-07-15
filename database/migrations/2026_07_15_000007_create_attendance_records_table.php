<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('office_location_id')->nullable()->constrained()->nullOnDelete();
            $table->date('attendance_date');
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->decimal('check_in_latitude', 10, 7)->nullable();
            $table->decimal('check_in_longitude', 10, 7)->nullable();
            $table->decimal('check_out_latitude', 10, 7)->nullable();
            $table->decimal('check_out_longitude', 10, 7)->nullable();
            $table->decimal('check_in_accuracy_meters', 8, 2)->nullable();
            $table->decimal('check_out_accuracy_meters', 8, 2)->nullable();
            $table->decimal('check_in_distance_meters', 9, 2)->nullable();
            $table->decimal('check_out_distance_meters', 9, 2)->nullable();
            $table->boolean('check_in_within_geofence')->nullable();
            $table->boolean('check_out_within_geofence')->nullable();
            $table->string('status', 30)->default('present')->index();
            $table->unsignedInteger('late_minutes')->default(0);
            $table->unsignedInteger('undertime_minutes')->default(0);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->text('notes')->nullable();
            $table->string('check_in_ip_address', 45)->nullable();
            $table->string('check_out_ip_address', 45)->nullable();
            $table->text('check_in_user_agent')->nullable();
            $table->text('check_out_user_agent')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['attendance_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('early_window_minutes')->default(60);
            $table->unsignedInteger('grace_minutes')->default(15);
            $table->unsignedInteger('late_bind_minutes')->default(240);
            $table->boolean('schedule_aware')->default(false);
            $table->boolean('enforce_published_shift')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_schedule_settings');
    }
};

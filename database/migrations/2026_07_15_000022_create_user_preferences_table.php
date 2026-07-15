<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('timezone', 60)->default('Asia/Manila');
            $table->boolean('email_notifications')->default(true);
            $table->boolean('attendance_reminders')->default(true);
            $table->boolean('schedule_updates')->default(true);
            $table->boolean('leave_updates')->default(true);
            $table->boolean('compact_navigation')->default(false);
            $table->boolean('reduce_motion')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};

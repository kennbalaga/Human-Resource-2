<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether HRMS emails its notifications is now one decision for the whole
     * system, made by a System Administrator, rather than four switches on each
     * employee's own settings page. An employee who silenced their schedule
     * emails stopped hearing about a shift change until they next opened the
     * app, which is the one outcome notification email exists to prevent.
     */
    public function up(): void
    {
        Schema::create('notification_email_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications',
                'attendance_reminders',
                'schedule_updates',
                'leave_updates',
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', function (Blueprint $table) {
            $table->boolean('email_notifications')->default(true);
            $table->boolean('attendance_reminders')->default(true);
            $table->boolean('schedule_updates')->default(true);
            $table->boolean('leave_updates')->default(true);
        });

        Schema::dropIfExists('notification_email_settings');
    }
};

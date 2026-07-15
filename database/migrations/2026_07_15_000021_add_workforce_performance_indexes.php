<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasIndex('timesheets', ['status', 'period_start', 'period_end'])) {
            Schema::table('timesheets', fn (Blueprint $table) => $table->index(['status', 'period_start', 'period_end'], 'timesheets_status_period_idx'));
        }

        if (! Schema::hasIndex('leave_requests', ['status', 'start_date', 'end_date'])) {
            Schema::table('leave_requests', fn (Blueprint $table) => $table->index(['status', 'start_date', 'end_date'], 'leave_status_dates_idx'));
        }

        if (! Schema::hasIndex('attendance_records', ['employee_id', 'approval_status', 'attendance_date'])) {
            Schema::table('attendance_records', fn (Blueprint $table) => $table->index(['employee_id', 'approval_status', 'attendance_date'], 'attendance_employee_approval_date_idx'));
        }
    }

    public function down(): void
    {
        $this->dropIndex('timesheets', 'timesheets_status_period_idx', ['status', 'period_start', 'period_end']);
        $this->dropIndex('leave_requests', 'leave_status_dates_idx', ['status', 'start_date', 'end_date']);
        $this->dropIndex('attendance_records', 'attendance_employee_approval_date_idx', ['employee_id', 'approval_status', 'attendance_date']);
    }

    /** @param array<int, string> $columns */
    private function dropIndex(string $tableName, string $preferredName, array $columns): void
    {
        if (Schema::hasIndex($tableName, $preferredName)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($preferredName));

            return;
        }

        if (Schema::hasIndex($tableName, $columns)) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropIndex($columns));
        }
    }
};

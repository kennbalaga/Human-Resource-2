<?php

namespace App\Console\Commands;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\LeaveRequest;
use Illuminate\Console\Command;

/**
 * Deletes records past their configured retention window. Every window in
 * config/privacy.php defaults to null (disabled) — this command is not
 * scheduled anywhere, so running it does nothing until both (a) a window is
 * explicitly configured and (b) someone deliberately schedules it. See
 * docs/DATA_PRIVACY.md for why those are left as separate, deliberate steps
 * rather than assumed by this command's existence.
 */
class PruneExpiredRecordsCommand extends Command
{
    protected $signature = 'records:prune-expired';

    protected $description = 'Delete records past their configured retention window (config/privacy.php); no-op for any table without one configured.';

    public function handle(): int
    {
        $windows = config('privacy.retention_days', []);
        $pruned = false;

        $pruned = $this->pruneByColumn(AttendanceRecord::class, 'attendance_records', 'attendance_date', $windows['attendance_records'] ?? null) || $pruned;
        $pruned = $this->pruneByColumn(LeaveRequest::class, 'leave_requests', 'end_date', $windows['leave_requests'] ?? null) || $pruned;
        $pruned = $this->pruneByColumn(AuditLog::class, 'audit_logs', 'created_at', $windows['audit_logs'] ?? null) || $pruned;

        if (! $pruned) {
            $this->info('No retention window is configured — nothing to prune. See config/privacy.php and docs/DATA_PRIVACY.md.');
        }

        return self::SUCCESS;
    }

    private function pruneByColumn(string $modelClass, string $table, string $dateColumn, null|int|string $days): bool
    {
        if ($days === null || $days === '') {
            return false;
        }

        $cutoff = now()->subDays((int) $days)->toDateString();
        $deleted = $modelClass::query()->whereDate($dateColumn, '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} row(s) from {$table} older than {$days} day(s) (before {$cutoff}).");

        return true;
    }
}

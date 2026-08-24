<?php

namespace Tests\Feature\Console;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PruneExpiredRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    public function test_it_prunes_nothing_when_no_retention_window_is_configured(): void
    {
        config(['privacy.retention_days' => ['attendance_records' => null, 'leave_requests' => null, 'audit_logs' => null]]);
        $old = $this->auditLog(now()->subYears(10));

        Artisan::call('records:prune-expired');

        $this->assertTrue(AuditLog::query()->whereKey($old->id)->exists());
    }

    public function test_it_prunes_audit_logs_older_than_the_configured_window_but_keeps_recent_ones(): void
    {
        config(['privacy.retention_days' => ['attendance_records' => null, 'leave_requests' => null, 'audit_logs' => 30]]);
        $old = $this->auditLog(now()->subDays(45));
        $recent = $this->auditLog(now()->subDays(10));

        Artisan::call('records:prune-expired');

        $this->assertFalse(AuditLog::query()->whereKey($old->id)->exists());
        $this->assertTrue(AuditLog::query()->whereKey($recent->id)->exists());
    }

    private function auditLog(Carbon $createdAt): AuditLog
    {
        $log = AuditLog::query()->create([
            'action' => 'test.action',
            'route_name' => 'test.route',
            'method' => 'POST',
            'path' => '/test',
        ]);
        DB::table('audit_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);

        return $log->fresh();
    }
}

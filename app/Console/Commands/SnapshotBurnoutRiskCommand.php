<?php

namespace App\Console\Commands;

use App\Services\Burnout\BurnoutRiskService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Makes the day's burnout risk assessment for every active employee, then
 * deletes assessments past their retention window.
 *
 * Nothing depends on this having run: every screen makes a missing assessment
 * when it is first asked for. Running it overnight means nobody waits for that
 * during the working day, and the pruning keeps the history no longer than
 * config('burnout.retention_days').
 */
class SnapshotBurnoutRiskCommand extends Command
{
    protected $signature = 'burnout:snapshot
        {--date= : Assess as of this date (YYYY-MM-DD) instead of today}';

    protected $description = 'Record today\'s burnout risk assessment for every active employee and prune old assessments.';

    public function handle(BurnoutRiskService $risk): int
    {
        $asOf = $this->option('date') !== null
            ? Carbon::parse((string) $this->option('date'), config('schedule.timezone'))->startOfDay()
            : null;

        $assessed = $risk->snapshotAll($asOf);
        $pruned = $risk->prune();

        $this->info("Burnout risk assessed for {$assessed} employee(s) as of ".($asOf ?? $risk->today())->toDateString().'.');
        $this->info("Assessments past the retention window deleted: {$pruned}.");

        return self::SUCCESS;
    }
}

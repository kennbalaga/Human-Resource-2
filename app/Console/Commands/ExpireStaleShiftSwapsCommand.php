<?php

namespace App\Console\Commands;

use App\Models\ShiftSwapRequest;
use App\Services\ShiftSwapService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Closes the swap requests the calendar has already answered.
 *
 * A swap is a question about a shift that is coming. Nothing used to ask it
 * again once that shift had gone: an unanswered request kept its pending status
 * indefinitely, carried on counting against the reviewer's sidebar badge, and
 * sat in the queue behind an Approve button that could only refuse it, because
 * approval rejects a shift already worked. The queue filled with requests
 * nobody could clear and nothing said why.
 *
 * Both parties keep the whole of the shift's own day to answer -- a request is
 * only swept once the earlier of the two shifts is properly in the past, never
 * on the morning of it.
 *
 * It also closes the requests that stopped being answerable for the other
 * reason: somebody in them transferred to a post that does not swap shifts, so
 * the page the request lives on is now hidden from them and nobody left can
 * see it, let alone answer it.
 */
class ExpireStaleShiftSwapsCommand extends Command
{
    protected $signature = 'shift-swaps:expire
        {--dry-run : Report what would be expired without touching anything}';

    protected $description = 'Close shift swap requests that can no longer be answered.';

    public function handle(ShiftSwapService $service): int
    {
        if ($this->option('dry-run')) {
            // The same list the sweep is about to close, asked for without the
            // closing, so a dry run cannot describe a different set from the
            // real run.
            $stale = $service->unanswerable();
            $this->report($stale, 'Would expire: ');
            $this->info($stale->count().' request(s) would be expired.');

            return self::SUCCESS;
        }

        $expired = $service->expireStale();
        $this->report($expired, 'Expired: ');
        $this->info($expired->count().' request(s) expired.');

        return self::SUCCESS;
    }

    /**
     * Named rather than counted, and with the reason: somebody will want to
     * know whose request went unanswered and why, and a bare number tells them
     * neither.
     *
     * @param  Collection<int, ShiftSwapRequest>  $swaps
     */
    private function report(Collection $swaps, string $prefix): void
    {
        foreach ($swaps as $swap) {
            $date = $swap->requesterAssignment?->work_date?->format('j M Y') ?? 'unknown date';

            $this->line($prefix.($swap->requesterEmployee?->full_name ?? 'Unknown employee')
                .' ↔ '.($swap->targetEmployee?->full_name ?? 'Unknown employee')
                .' ('.$date.', '.str_replace('_', ' ', $swap->status).')');
            $this->line('    '.$swap->expired_reason);
        }
    }
}

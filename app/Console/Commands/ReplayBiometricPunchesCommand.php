<?php

namespace App\Console\Commands;

use App\Models\BiometricPunch;
use App\Services\Attendance\BiometricPunchIngestionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Re-attempts stored punches that never became attendance.
 *
 * Every scan the terminal reports is written to biometric_punches the moment it
 * arrives, whether or not it can be placed. That is deliberate: the bridge
 * agent stops retrying once this application answers 2xx, so a punch it can
 * never place -- a PIN nobody has enrolled yet, a punch code not mapped to a
 * direction -- is recorded with a status rather than refused, which would trap
 * the agent in a loop it could not escape.
 *
 * Which leaves the other half of that bargain, and this is it. Without a replay
 * those punches sit on file correct and inert, and the attendance they should
 * have become is simply missing. Two cases make that concrete:
 *
 *   - Enrolment runs ward by ward, which is the sensible way to do it, and
 *     staff whose ward is not done yet still try the terminal. Their punches
 *     land 'unmatched'. Once their enrolment exists, those days are recoverable
 *     only from here.
 *
 *   - The punch codes this unit emits were never observed before go-live, so
 *     the mapping in config/attendance.php is a convention rather than a
 *     measurement. If it is wrong, a run of check-outs lands 'unsupported'.
 *     Correcting the config is one line; without a replay the attendance it
 *     should have produced has to be keyed in by hand.
 *
 * Nothing here touches the terminal. It reads punches already in this database
 * and pushes them through the same derivation the bridge endpoint uses, so a
 * replay cannot invent a scan -- at worst it fails the same way twice. Punches
 * already placed are not in the set, and the gateway's own unique constraint
 * stands behind that, so re-running is safe.
 */
class ReplayBiometricPunchesCommand extends Command
{
    protected $signature = 'biometric:replay
        {--status=* : Replay these statuses instead of the default set}
        {--since= : Only punches recorded on or after this date (YYYY-MM-DD)}
        {--dry-run : Report what would be replayed without touching anything}';

    protected $description = 'Re-derive attendance from stored biometric punches that never produced any.';

    public function handle(BiometricPunchIngestionService $ingestion): int
    {
        $statuses = $this->statuses();

        if ($statuses === null) {
            return self::FAILURE;
        }

        $since = $this->since();

        if ($since === false) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            // The same set the replay is about to work on, asked for without
            // the working, so a dry run cannot describe a different set from
            // the real run.
            $candidates = $ingestion->replayable($statuses, $since);

            foreach ($candidates as $punch) {
                $this->line('Would replay: '.$this->describe($punch).' — currently '.$punch->processing_status);
            }

            $this->info($candidates->count().' punch(es) would be replayed.');

            return self::SUCCESS;
        }

        $replayed = $ingestion->replay($statuses, $since);

        $this->report($replayed);

        return self::SUCCESS;
    }

    /**
     * Named rather than counted, and with what each one became: a replay is run
     * after fixing something, and the question it has to answer is whether the
     * fix worked. A bare total cannot say which punches are still stuck.
     *
     * @param  Collection<int, BiometricPunch>  $punches
     */
    private function report(Collection $punches): void
    {
        $settled = [];

        foreach ($punches as $punch) {
            $outcome = (string) $punch->getAttribute('replay_outcome');

            if ($outcome === 'skipped') {
                continue;
            }

            $this->line('Replayed: '.$this->describe($punch).' — '.(
                $outcome === 'unchanged'
                    ? 'still '.$punch->processing_status
                    : 'now '.$punch->processing_status
            ));

            $settled[$punch->processing_status] = ($settled[$punch->processing_status] ?? 0) + 1;
        }

        $skipped = $punches->filter(fn (BiometricPunch $punch) => $punch->getAttribute('replay_outcome') === 'skipped');

        $this->info($punches->count() - $skipped->count().' punch(es) replayed.');

        foreach ($settled as $status => $count) {
            $this->line('  · '.$status.': '.$count);
        }

        // A terminal that has been retired or had its serial corrected leaves
        // punches nothing can be derived against. Warned rather than counted
        // silently: somebody has to decide whether that device comes back.
        if ($skipped->isNotEmpty()) {
            $this->warn($skipped->count().' punch(es) could not be replayed:');

            foreach ($skipped as $punch) {
                $this->line('  · '.$this->describe($punch).' — '.$punch->getAttribute('replay_reason'));
            }
        }
    }

    private function describe(BiometricPunch $punch): string
    {
        return 'PIN '.$punch->pin
            .' at '.$punch->punched_at->timezone(config('workforce.timezone', 'Asia/Manila'))->format('j M Y H:i')
            .' on '.$punch->device_sn;
    }

    /**
     * The statuses to work on, or null when the caller named one that cannot be
     * replayed.
     *
     * 'stale' is accepted here but never defaulted to, and that asymmetry is
     * the point. A stale punch is either a drifted device clock, where
     * replaying writes fiction into the time-and-attendance record, or a real
     * outage backlog, where replaying recovers work people actually did. The
     * two are indistinguishable from the row, so naming it has to be a decision
     * somebody makes rather than something a scheduled sweep does for them.
     *
     * @return list<string>|null
     */
    private function statuses(): ?array
    {
        /** @var list<string> $named */
        $named = (array) $this->option('status');

        if ($named === []) {
            return BiometricPunchIngestionService::REPLAYABLE_STATUSES;
        }

        $allowed = ['unmatched', 'unsupported', 'pending', 'stale', 'rejected', 'duplicate'];
        $unknown = array_values(array_diff($named, $allowed));

        if ($unknown !== []) {
            $this->error('Cannot replay: '.implode(', ', $unknown).'.');
            $this->line('Replayable statuses are '.implode(', ', $allowed).'.');
            $this->line("A punch that is already 'processed' has its attendance and is never replayed.");

            return null;
        }

        return array_values($named);
    }

    /**
     * @return string|null|false false when the date cannot be read
     */
    private function since(): string|null|false
    {
        $since = $this->option('since');

        if ($since === null || $since === '') {
            return null;
        }

        try {
            // Read in the office's timezone, like every other date this
            // application accepts from a person, then handed over in the UTC
            // the column stores.
            return Carbon::parse((string) $since, config('workforce.timezone', 'Asia/Manila'))
                ->startOfDay()
                ->utc()
                ->toDateTimeString();
        } catch (\Throwable) {
            $this->error('Could not read --since='.$since.'. Use YYYY-MM-DD.');

            return false;
        }
    }
}

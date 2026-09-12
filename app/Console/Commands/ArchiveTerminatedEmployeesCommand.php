<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\Organization\EmployeeArchiver;
use Illuminate\Console\Command;

/**
 * Files away the records that have sat in Terminated past the window.
 *
 * The manual button exists for the terminations that are finished on the day
 * they are signed. This is for the rest of them — the clearance still being
 * chased, the final timesheet still being argued about — where the record has
 * to stay reachable for a while and then, when nobody is thinking about it any
 * more, stop cluttering the directory. Nobody has to remember to come back.
 *
 * It refuses the same things the button refuses. A record with a shift still
 * rostered, an undecided leave request, direct reports pointing at it or an
 * attendance day left open is reported and skipped, not forced: the sweep runs
 * unattended, and quietly closing an account that still owes the organisation
 * an answer is the one thing it must not do on its own.
 */
class ArchiveTerminatedEmployeesCommand extends Command
{
    protected $signature = 'employees:archive-terminated
        {--days= : Override the window from config/workforce.php for this run}
        {--dry-run : Report what would be archived without touching anything}';

    protected $description = 'Archive employee records that have been terminated for longer than the configured window.';

    public function handle(EmployeeArchiver $archiver): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : $archiver->windowInDays();

        if ($days <= 0) {
            $this->info('Automatic archiving is switched off (WORKFORCE_TERMINATED_ARCHIVE_AFTER_DAYS is '.$days.'). Nothing was archived.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $due = Employee::query()
            ->with('user')
            ->dueForAutomaticArchive($cutoff)
            ->orderBy('terminated_at')
            ->get();

        if ($due->isEmpty()) {
            $this->info("No record has been terminated for {$days} day(s) or more without already being archived.");

            return self::SUCCESS;
        }

        $archived = 0;
        $skipped = [];

        foreach ($due as $employee) {
            $outstanding = $archiver->outstandingWork($employee);

            if ($outstanding !== []) {
                $skipped[] = $employee->full_name.' — '.$archiver->listPhrase($outstanding);

                continue;
            }

            if (! $dryRun) {
                // No actor: nobody signed in to do this. The record carries the
                // date with no name against it, which the directory reads back
                // as "archived automatically".
                $archiver->archive($employee, null);
            }

            $archived++;
            $this->line(($dryRun ? 'Would archive: ' : 'Archived: ').$employee->full_name
                .' (terminated '.$employee->terminated_at->format('j M Y').')');
        }

        $this->info($dryRun
            ? "{$archived} record(s) would be archived."
            : "{$archived} record(s) archived after {$days} day(s) in Terminated.");

        if ($skipped !== []) {
            // Named rather than counted: these are the ones a person has to go
            // and settle, and a bare number tells nobody which.
            $this->warn(count($skipped).' record(s) were left alone because something is still open:');
            foreach ($skipped as $line) {
                $this->warn('  · '.$line);
            }
        }

        return self::SUCCESS;
    }
}

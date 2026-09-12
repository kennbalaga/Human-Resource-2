<?php

namespace App\Services\Organization;

use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Putting a record on the shelf, and taking it back off.
 *
 * Archiving happens two ways — a manager clicking Archive, and the nightly
 * sweep filing records that have sat in Terminated past the window — and both
 * have to do exactly the same thing to the row, the account and the tokens. A
 * sweep that forgot to delete a personal access token would leave a credential
 * answering for somebody who left months ago, and nothing on screen would say
 * so. So the act itself lives here once, and the two callers differ only in who
 * they name as the archiver.
 */
class EmployeeArchiver
{
    /**
     * How long a record sits in Terminated before the sweep files it.
     *
     * Zero or less turns the automatic half off entirely and leaves archiving
     * a manual act — a deployment that would rather decide record by record
     * should be able to say so without deleting the schedule.
     */
    public function windowInDays(): int
    {
        return (int) config('workforce.terminated_archive_after_days', 30);
    }

    public function automaticArchivingEnabled(): bool
    {
        return $this->windowInDays() > 0;
    }

    /**
     * When the sweep will come for this record, or null if it will not: the
     * employment is not over, the record is already filed, or automatic
     * archiving is switched off for this deployment.
     *
     * Restoring a record moves this date rather than removing it. Somebody who
     * un-archives a former colleague to check one last thing gets a fresh
     * window to do it in, and if they then forget — which is the whole reason
     * the sweep exists — the record files itself a month later anyway.
     */
    public function dueAt(Employee $employee): ?CarbonImmutable
    {
        $clockStartedAt = $employee->archiveClockStartedAt();

        if (! $this->automaticArchivingEnabled() || $employee->isArchived() || $clockStartedAt === null) {
            return null;
        }

        return CarbonImmutable::parse($clockStartedAt)->addDays($this->windowInDays());
    }

    /**
     * File the record away.
     *
     * Nothing is deleted and nothing is anonymised — the row keeps every field
     * it had, and its timesheets, leave and attendance history stay attached
     * and countable. It leaves the directory's working list, and it stops being
     * offered as somebody to roster or report to.
     *
     * A null actor is the sweep: the archive is recorded with a date and no
     * name, which is the honest account of an act nobody signed in to perform.
     */
    public function archive(Employee $employee, ?User $actor): void
    {
        DB::transaction(function () use ($employee, $actor): void {
            $employee->forceFill([
                'archived_at' => now(),
                'archived_by' => $actor?->id,
            ])->save();

            // Archived means out of the organisation's working day, not merely
            // out of one list. Leaving the account open would let somebody the
            // directory no longer shows sign in and clock in as usual.
            $employee->user?->update(['is_active' => false]);
            // A personal access token outlives sessions and ignores is_active
            // at the point of issue, so it is the one credential that would
            // still answer for an archived account.
            $employee->user?->tokens()->delete();
        });
    }

    /**
     * Back onto the working list, exactly as it was.
     *
     * Stamping the restore is what makes it safe to do on a record terminated
     * long ago: without it the sweep would find the same person that night and
     * file them again, and the reason they were pulled out — a timesheet to
     * settle, a clearance to check — would never get looked at. It buys one
     * more window, not an exemption. Nobody has to remember to re-file the
     * record, which is the same promise the first thirty days made.
     */
    public function restore(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            $employee->forceFill([
                'archived_at' => null,
                'archived_by' => null,
                'restored_at' => $employee->isTerminated() ? now() : null,
            ])->save();

            // Access follows employment again, by the same rule the employee
            // form uses. A restored record whose status still reads active but
            // whose account stayed shut would be a person locked out with
            // nothing on screen to explain why — and a restored *terminated*
            // record stays shut, which is the same rule reaching the other
            // answer.
            $employee->user?->update([
                'is_active' => Employee::employmentGrantsAccess($employee->employment_status),
            ]);
        });
    }

    /**
     * What has to be settled before a record can be filed away.
     *
     * Archiving is the end of somebody's presence in the working day, so the
     * things that would outlive them are checked first: a shift still rostered
     * in their name, a leave request nobody has decided, the colleagues who
     * report to them — who would otherwise keep pointing at a supervisor the
     * directory no longer shows — and a day they are still standing in.
     *
     * That last one is the trap the others do not cover. Archiving closes the
     * account and turns away the badge, so a person archived between their
     * check-in and their check-out can no longer record the second half: not
     * from the website they can no longer sign in to, and not at the scanner
     * that now refuses their card. The day would stay open forever, and the
     * timesheet built from it would be wrong with nobody to fix it.
     *
     * @return array<int, string>
     */
    public function outstandingWork(Employee $employee): array
    {
        $futureShifts = $employee->scheduleAssignments()->whereDate('work_date', '>=', now()->toDateString())->count();
        $pendingLeave = $employee->leaveRequests()->where('status', 'pending')->count();
        $directReports = $employee->directReports()->notArchived()->count();
        $stillClockedIn = $employee->attendanceRecords()
            ->whereNotNull('check_in_at')
            ->whereNull('check_out_at')
            ->exists();

        return array_values(array_filter([
            $stillClockedIn ? 'an attendance day that has not been checked out' : null,
            $futureShifts > 0 ? $futureShifts.' '.str('scheduled shift')->plural($futureShifts).' from today onwards' : null,
            $pendingLeave > 0 ? $pendingLeave.' pending leave '.str('request')->plural($pendingLeave) : null,
            $directReports > 0 ? $directReports.' direct '.str('report')->plural($directReports) : null,
        ]));
    }

    /** @param array<int, string> $items */
    public function listPhrase(array $items): string
    {
        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items).' and '.$last;
    }
}

<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Timesheet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What is waiting on this account's decision, right now.
 *
 * The dashboard counted these two figures long before it showed them: pending
 * leave and submitted timesheets were gathered on every load, folded into a
 * notification collection no view ever rendered, and thrown away. A manager
 * opening the overview was told the size of the workforce and nothing about the
 * work only they can clear.
 *
 * Two groups, because they are answered in two different modules and a manager
 * clears them in separate sittings:
 *
 *   Leave requests - pending, awaiting approve/reject in Leave Management.
 *   Timesheets     - submitted, awaiting review in Timesheets.
 *
 * Each group carries a count, the few oldest rows by name, and how long the front
 * of the queue has been waiting. Oldest-first is the whole point: a queue sorted
 * newest-first buries the request that has been ignored for a week under the one
 * filed this morning.
 *
 * Everything is narrowed by `Employee::constrainRelatedQuery`, so a department
 * head sees their own unit's queue and HR sees the hospital's. The counts are the
 * same expressions DashboardController's summary row uses, so the panel and any
 * figure drawn from that row cannot disagree.
 *
 * Deliberately not cached. Every other dashboard panel describes a situation and
 * can afford to be a minute stale; this one describes a to-do list the reader is
 * about to act on, and an approval that still shows as pending after the redirect
 * back reads as a failed save.
 */
class ApprovalQueueService
{
    /**
     * Rows named per group. Enough to start on without turning a dashboard panel
     * into a second copy of the module behind it.
     */
    private const PREVIEW_LIMIT = 4;

    /**
     * How long an item may sit before the panel calls it out. Leave in particular
     * loses its meaning if it is answered after the dates it asked for.
     */
    private const STALE_DAYS = 3;

    public function __construct(private readonly ReferenceDataCache $reference) {}

    /**
     * @return array{
     *     total: int,
     *     oldest_days: int|null,
     *     oldest_label: string|null,
     *     groups: array<int, array{key: string, label: string, icon: string, count: int, url: string, action_label: string, empty_label: string, waiting_label: string|null, items: array<int, array<string, mixed>>}>,
     * }
     */
    public function forUser(?User $user): array
    {
        $now = Carbon::now(config('workforce.timezone', 'Asia/Manila'));

        // Both counts and both queue ages in one round trip. Four aggregates over
        // two tables would be four trips against a remote database for figures
        // that are only ever read together.
        $row = (array) DB::query()
            ->selectSub($this->pendingLeave($user)->selectRaw('count(*)'), 'leave_count')
            ->selectSub($this->pendingLeave($user)->selectRaw('min(created_at)'), 'leave_oldest')
            ->selectSub($this->submittedTimesheets($user)->selectRaw('count(*)'), 'timesheet_count')
            ->selectSub($this->submittedTimesheets($user)->selectRaw('min(coalesce(submitted_at, updated_at))'), 'timesheet_oldest')
            ->first();

        $leaveCount = (int) $row['leave_count'];
        $timesheetCount = (int) $row['timesheet_count'];

        $leaveOldest = $this->timestamp($row['leave_oldest']);
        $timesheetOldest = $this->timestamp($row['timesheet_oldest']);

        $groups = [
            [
                'key' => 'leave',
                'label' => 'Leave requests',
                'icon' => 'leave',
                'count' => $leaveCount,
                'url' => route('leaves.index', ['status' => 'pending', 'year' => $now->year]),
                'action_label' => 'Open leave management',
                'empty_label' => 'No leave requests are waiting.',
                'waiting_label' => $leaveOldest ? $this->waitingLabel($leaveOldest, $now) : null,
                // The preview costs a query, so it is only paid for when the group
                // has something in it.
                'items' => $leaveCount > 0 ? $this->leaveItems($user, $now) : [],
            ],
            [
                'key' => 'timesheet',
                'label' => 'Timesheets',
                'icon' => 'timesheet',
                'count' => $timesheetCount,
                'url' => route('timesheets.index', ['status' => 'submitted']),
                'action_label' => 'Open timesheets',
                'empty_label' => 'No timesheets are awaiting review.',
                'waiting_label' => $timesheetOldest ? $this->waitingLabel($timesheetOldest, $now) : null,
                'items' => $timesheetCount > 0 ? $this->timesheetItems($user, $now) : [],
            ],
        ];

        $oldest = collect([$leaveOldest, $timesheetOldest])->filter()->min();

        return [
            'total' => $leaveCount + $timesheetCount,
            'oldest_days' => $oldest ? $this->daysWaiting($oldest, $now) : null,
            'oldest_label' => $oldest ? $this->waitingLabel($oldest, $now) : null,
            'groups' => $groups,
        ];
    }

    /**
     * The oldest pending leave, named. The employee is eager loaded; the two
     * reference tables the row is summarised by -- department and leave type --
     * are filled from cache, so a four-row preview costs two queries rather than
     * the nine it would take one row at a time.
     *
     * @return array<int, array<string, mixed>>
     */
    private function leaveItems(?User $user, Carbon $now): array
    {
        $leaves = $this->pendingLeave($user)
            ->with('employee')
            ->oldest('created_at')
            ->oldest('id')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        $this->reference->attach($leaves, 'leaveType', 'leave_type_id', LeaveType::class);
        $this->reference->attach($leaves->pluck('employee')->filter(), 'department', 'department_id', Department::class);

        return $leaves
            ->map(function (LeaveRequest $leave) use ($now): array {
                $days = (float) $leave->requested_days;

                return [
                    'name' => $leave->employee?->full_name ?? 'Unknown employee',
                    'employee_number' => $leave->employee?->employee_number,
                    'department' => $leave->employee?->department?->name,
                    'summary' => $leave->leaveType?->name ?? 'Leave',
                    'detail' => $this->dateRange($leave->start_date, $leave->end_date)
                        .' · '.$this->number($days).' '.str('day')->plural($days),
                    'waiting_label' => $this->waitingLabel($leave->created_at, $now),
                    'stale' => $this->daysWaiting($leave->created_at, $now) >= self::STALE_DAYS,
                    // Lands on the module already filtered to this person's pending
                    // rows, in the year the leave was asked for -- not the current
                    // year, which would show an empty list for a December request
                    // answered in January.
                    'url' => route('leaves.index', [
                        'status' => 'pending',
                        'employee_id' => $leave->employee_id,
                        'year' => $leave->start_date->year,
                    ]),
                ];
            })
            ->all();
    }

    /**
     * The oldest submitted timesheets, named.
     *
     * @return array<int, array<string, mixed>>
     */
    private function timesheetItems(?User $user, Carbon $now): array
    {
        $timesheets = $this->submittedTimesheets($user)
            ->with('employee')
            ->orderByRaw('coalesce(submitted_at, updated_at) asc')
            ->oldest('id')
            ->limit(self::PREVIEW_LIMIT)
            ->get();

        $this->reference->attach($timesheets->pluck('employee')->filter(), 'department', 'department_id', Department::class);

        return $timesheets
            ->map(function (Timesheet $timesheet) use ($now): array {
                // A timesheet submitted by a path that skipped the column still has
                // to be able to report an age.
                $waitingSince = $timesheet->submitted_at ?? $timesheet->updated_at;

                return [
                    'name' => $timesheet->employee?->full_name ?? 'Unknown employee',
                    'employee_number' => $timesheet->employee?->employee_number,
                    'department' => $timesheet->employee?->department?->name,
                    'summary' => $this->dateRange($timesheet->period_start, $timesheet->period_end),
                    'detail' => $this->hours($timesheet->total_minutes).' logged'
                        .($timesheet->overtime_minutes > 0 ? ' · '.$this->hours($timesheet->overtime_minutes).' overtime' : ''),
                    'waiting_label' => $this->waitingLabel($waitingSince, $now),
                    'stale' => $this->daysWaiting($waitingSince, $now) >= self::STALE_DAYS,
                    'url' => route('timesheets.index', [
                        'status' => 'submitted',
                        'employee_id' => $timesheet->employee_id,
                        'date_from' => $timesheet->period_start->toDateString(),
                        'date_to' => $timesheet->period_end->toDateString(),
                    ]),
                ];
            })
            ->all();
    }

    /** @return Builder<LeaveRequest> */
    private function pendingLeave(?User $user): Builder
    {
        return Employee::constrainRelatedQuery(LeaveRequest::query()->where('status', 'pending'), $user);
    }

    /** @return Builder<Timesheet> */
    private function submittedTimesheets(?User $user): Builder
    {
        return Employee::constrainRelatedQuery(Timesheet::query()->where('status', 'submitted'), $user);
    }

    /**
     * A `min()` over a datetime column comes back as a driver-shaped string, or as
     * null when the group is empty.
     */
    private function timestamp(mixed $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse((string) $value);
    }

    /** How long the front of a queue has been waiting, coarse on purpose. */
    private function waitingLabel(Carbon $since, Carbon $now): string
    {
        $days = $this->daysWaiting($since, $now);

        return match (true) {
            $days < 1 => 'today',
            $days === 1 => '1 day',
            default => $days.' days',
        };
    }

    private function daysWaiting(Carbon $since, Carbon $now): int
    {
        // Guards the clock-skew case: a row written a second into the future by a
        // database on a slightly different clock would otherwise read as negative.
        return max(0, (int) floor($since->diffInDays($now)));
    }

    /** "Aug 3 – 7" inside one month, "Aug 30 – Sep 2" across two. */
    private function dateRange(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $start->format('M j');
        }

        return $start->isSameMonth($end)
            ? $start->format('M j').' – '.$end->format('j')
            : $start->format('M j').' – '.$end->format('M j');
    }

    private function hours(int $minutes): string
    {
        return $this->number(round($minutes / 60, 1)).'h';
    }

    /** Trims the decimal when it adds nothing: "7.5" but "8". */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }
}

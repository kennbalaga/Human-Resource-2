<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PreferredDayOff;
use App\Models\ShiftSwapRequest;
use App\Models\Timesheet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The counts the navigation rail carries beside three of its entries.
 *
 * The dashboard's approval queue already says what is waiting, but only while
 * the reader is standing on the dashboard. These are the same figures moved to
 * the one piece of chrome that is on screen no matter which module they are in,
 * so a leave request filed while somebody is deep in the roster still announces
 * itself.
 *
 * Every number means the same thing: items waiting on *this* reader. That is
 * what makes a badge worth reacting to — a count of "things that exist" would
 * sit there permanently and stop being read after a week.
 *
 *   Preferences and Swaps - a manager's queue is day-off requests plus swaps
 *                           both parties already agreed to; an employee's is
 *                           swaps somebody has asked *them* to take.
 *   Timesheets            - submitted, awaiting review.
 *   Leave Management      - pending, awaiting approve/reject.
 *
 * Scoped by {@see Employee::constrainRelatedQuery}, so a department head is
 * badged for their own unit and HR for the hospital.
 *
 * Deliberately uncached, for the reason {@see ApprovalQueueService} gives: this
 * is a to-do list the reader is about to act on, and a badge that still reads 18
 * after the approval redirect looks like the save failed.
 */
class SidebarBadgeService
{
    /**
     * Past this the plate would outgrow the rail's 188px label column, so it
     * stops counting and says so.
     */
    public const MAX_DISPLAY = 99;

    /**
     * @return array{swaps: int, timesheets: int, leave: int}
     */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return ['swaps' => 0, 'timesheets' => 0, 'leave' => 0];
        }

        $employee = $user->employee;
        $canManage = Gate::forUser($user)->allows('workforce.manage');

        // One round trip for the whole rail. This runs on every page in the
        // product, so four separate aggregates would be four trips added to
        // every request in it.
        $row = (array) DB::query()
            ->selectSub($this->pendingLeave($user)->selectRaw('count(*)'), 'leave')
            ->selectSub($this->submittedTimesheets($user)->selectRaw('count(*)'), 'timesheets')
            ->selectSub($this->waitingSwaps($user, $employee, $canManage)->selectRaw('count(*)'), 'swaps')
            ->selectSub($this->pendingDayOff($user, $canManage)->selectRaw('count(*)'), 'day_off')
            ->first();

        return [
            // The two halves of that page answer to the same reviewer, and the
            // rail has one plate for it, so they are read as one queue.
            'swaps' => (int) $row['swaps'] + (int) $row['day_off'],
            'timesheets' => (int) $row['timesheets'],
            'leave' => (int) $row['leave'],
        ];
    }

    /** @return Builder<LeaveRequest> */
    private function pendingLeave(User $user): Builder
    {
        return Employee::constrainRelatedQuery(LeaveRequest::query()->where('status', 'pending'), $user);
    }

    /** @return Builder<Timesheet> */
    private function submittedTimesheets(User $user): Builder
    {
        return Employee::constrainRelatedQuery(Timesheet::query()->where('status', 'submitted'), $user);
    }

    /**
     * A swap moves through the target before it reaches a manager, so the two
     * roles are waiting on different rows and never on the same one.
     *
     * @return Builder<ShiftSwapRequest>
     */
    private function waitingSwaps(User $user, ?Employee $employee, bool $canManage): Builder
    {
        if ($canManage) {
            return Employee::constrainRelatedQuery(
                ShiftSwapRequest::query()->where('status', 'pending_manager'),
                $user,
                'requesterEmployee',
            );
        }

        if ($employee === null) {
            return ShiftSwapRequest::query()->whereRaw('0 = 1');
        }

        return ShiftSwapRequest::query()
            ->where('target_employee_id', $employee->id)
            ->where('status', 'pending_target');
    }

    /**
     * Day-off requests are only ever answered by a manager. An employee's own
     * pending request is waiting on somebody else, so badging them for it would
     * be asking them to chase their own paperwork.
     *
     * @return Builder<PreferredDayOff>
     */
    private function pendingDayOff(User $user, bool $canManage): Builder
    {
        if (! $canManage) {
            return PreferredDayOff::query()->whereRaw('0 = 1');
        }

        return Employee::constrainRelatedQuery(PreferredDayOff::query()->where('status', 'pending'), $user);
    }
}

<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ScheduleAssignment;
use App\Models\ScheduleDayOff;
use App\Models\ShiftSwapRequest;
use App\Models\User;
use App\Services\Scheduling\RoomAssignmentService;
use App\Services\Scheduling\RosterWriteContext;
use App\Services\Scheduling\ScheduleLockService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShiftSwapService
{
    /**
     * The two states a request can still move out of. Everything else --
     * approved, rejected, declined, cancelled, expired -- is an ending, and no
     * queue, badge or duplicate check should count one.
     *
     * @var array<int, string>
     */
    public const OPEN_STATUSES = ['pending_target', 'pending_manager'];

    public function __construct(
        private readonly ScheduleService $scheduleService,
        private readonly ScheduleLockService $scheduleLockService,
        private readonly RoomAssignmentService $roomAssignments,
    ) {}

    /** @param array{requester_assignment_id: int, target_assignment_id: int, reason: string} $data */
    public function create(Employee $requester, array $data): ShiftSwapRequest
    {
        return DB::transaction(function () use ($requester, $data) {
            $requesterAssignment = ScheduleAssignment::query()
                ->lockForUpdate()
                ->where('employee_id', $requester->id)
                ->findOrFail($data['requester_assignment_id']);
            $targetAssignment = ScheduleAssignment::query()
                ->lockForUpdate()
                ->with('employee.department')
                ->findOrFail($data['target_assignment_id']);

            if ($requester->id === $targetAssignment->employee_id) {
                throw ValidationException::withMessages(['target_assignment_id' => 'You cannot request a swap with yourself.']);
            }

            $this->ensureEligible($requester, 'requester_assignment_id');
            $this->ensureEligible($targetAssignment->employee, 'target_assignment_id');
            $this->ensureSwappable($requesterAssignment, 'requester_assignment_id');
            $this->ensureSwappable($targetAssignment, 'target_assignment_id');
            $this->ensureDifferentSlot($requesterAssignment, $targetAssignment);
            $this->ensureNoOpenSwap($requesterAssignment->id, $targetAssignment->id);

            return ShiftSwapRequest::query()->create([
                'uuid' => (string) Str::uuid(),
                'requester_employee_id' => $requester->id,
                'requester_assignment_id' => $requesterAssignment->id,
                'target_employee_id' => $targetAssignment->employee_id,
                'target_assignment_id' => $targetAssignment->id,
                'reason' => $data['reason'],
                'status' => 'pending_target',
            ]);
        });
    }

    public function respond(ShiftSwapRequest $swap, Employee $target, bool $accept, ?string $notes): ShiftSwapRequest
    {
        if ($swap->target_employee_id !== $target->id) {
            abort(403);
        }

        // Accepting is what puts a request in front of a manager, so the
        // deadline has to be enforced here and not only at approval. It was
        // checked at approval alone, which let a colleague answer days late and
        // hand the manager a request that could never be approved -- and that
        // then sat in their queue for good.
        //
        // Outside the transaction below, because closing the request is the
        // point: raised from inside, the exception that reports the closure
        // would roll back the closure with it.
        $this->expireIfUnanswerable($swap);

        return DB::transaction(function () use ($swap, $accept, $notes) {
            $swap = ShiftSwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
            if ($swap->status !== 'pending_target') {
                throw ValidationException::withMessages(['swap' => 'This request already moved past your response.']);
            }

            $swap->update([
                'status' => $accept ? 'pending_manager' : 'declined_by_target',
                'target_responded_at' => now(),
                'target_notes' => $notes,
            ]);

            return $swap->refresh();
        });
    }

    public function approve(ShiftSwapRequest $swap, User $reviewer, ?string $notes): ShiftSwapRequest
    {
        return DB::transaction(function () use ($swap, $reviewer, $notes) {
            $swap = ShiftSwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
            if ($swap->status !== 'pending_manager') {
                throw ValidationException::withMessages(['swap' => 'Only requests accepted by the colleague and awaiting manager review can be approved.']);
            }

            $requesterAssignment = ScheduleAssignment::query()->lockForUpdate()->with('shift')->findOrFail($swap->requester_assignment_id);
            $targetAssignment = ScheduleAssignment::query()->lockForUpdate()->with('shift')->findOrFail($swap->target_assignment_id);
            $requesterEmployee = Employee::query()->findOrFail($swap->requester_employee_id);
            $targetEmployee = Employee::query()->with('department')->findOrFail($swap->target_employee_id);
            $requesterEmployee->loadMissing('department');

            $this->ensureSwappable($requesterAssignment, 'requester_assignment_id');
            $this->ensureSwappable($targetAssignment, 'target_assignment_id');
            $this->ensureDifferentSlot($requesterAssignment, $targetAssignment);

            $targetDate = Carbon::parse($targetAssignment->work_date->toDateString(), config('schedule.timezone'));
            $requesterDate = Carbon::parse($requesterAssignment->work_date->toDateString(), config('schedule.timezone'));

            if ($requesterEmployee->department !== null) {
                $this->scheduleLockService->assertUnlocked($requesterEmployee->department, $requesterDate);
            }
            if ($targetEmployee->department !== null) {
                $this->scheduleLockService->assertUnlocked($targetEmployee->department, $targetDate);
            }

            $reasonForRequester = $this->scheduleService->bulkAssignmentBlockReason(
                $requesterEmployee,
                $targetAssignment->shift,
                $targetDate,
                $this->assignmentsFor($requesterEmployee, $targetDate, $requesterAssignment->id),
                $this->leavesFor($requesterEmployee, $targetDate),
                $this->dayOffsFor($requesterEmployee, $targetDate),
            );
            if ($reasonForRequester !== null) {
                throw ValidationException::withMessages(['swap' => "This swap cannot be approved: {$reasonForRequester} for {$requesterEmployee->full_name} on {$targetAssignment->shift->name}."]);
            }

            $reasonForTarget = $this->scheduleService->bulkAssignmentBlockReason(
                $targetEmployee,
                $requesterAssignment->shift,
                $requesterDate,
                $this->assignmentsFor($targetEmployee, $requesterDate, $targetAssignment->id),
                $this->leavesFor($targetEmployee, $requesterDate),
                $this->dayOffsFor($targetEmployee, $requesterDate),
            );
            if ($reasonForTarget !== null) {
                throw ValidationException::withMessages(['swap' => "This swap cannot be approved: {$reasonForTarget} for {$targetEmployee->full_name} on {$requesterAssignment->shift->name}."]);
            }

            // The room stays with the slot, so each person inherits the other's
            // room. That is the right answer for a swap -- but a theatre must
            // not lose its charge cover because two nurses agreed a trade
            // between themselves.
            foreach ([[$requesterAssignment, $targetEmployee], [$targetAssignment, $requesterEmployee]] as [$assignment, $incoming]) {
                $roomReason = $this->roomAssignments->substitutionBlockReason($assignment, $incoming);

                if ($roomReason !== null) {
                    throw ValidationException::withMessages([
                        'swap' => "This swap cannot be approved: {$roomReason} for {$incoming->full_name}.",
                    ]);
                }
            }

            RosterWriteContext::allow($reviewer, function () use ($requesterAssignment, $targetAssignment, $requesterEmployee, $targetEmployee): void {
                $requesterAssignment->update(['employee_id' => $targetEmployee->id]);
                $targetAssignment->update(['employee_id' => $requesterEmployee->id]);

                // Whether the new occupant is borrowed from another unit is a
                // fact about the pairing, not about the row, so it is worked
                // out again rather than carried over from whoever stood here
                // before.
                $this->roomAssignments->refreshCrossUnitFlag($requesterAssignment, $targetEmployee);
                $this->roomAssignments->refreshCrossUnitFlag($targetAssignment, $requesterEmployee);
            });

            $swap->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $swap->refresh();
        });
    }

    public function reject(ShiftSwapRequest $swap, User $reviewer, string $notes): ShiftSwapRequest
    {
        $this->ensureNotParticipant($swap, $reviewer, 'reject');

        return DB::transaction(function () use ($swap, $reviewer, $notes) {
            $swap = ShiftSwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
            if (! in_array($swap->status, self::OPEN_STATUSES, true)) {
                throw ValidationException::withMessages(['swap' => 'Only open requests can be rejected.']);
            }

            $swap->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $swap->refresh();
        });
    }

    public function cancel(ShiftSwapRequest $swap, User $user): ShiftSwapRequest
    {
        // A manager's cancel is a review action, so it stops at the units the
        // manager supervises, as leave cancellation does.
        if ($swap->requester_employee_id !== $user->employee?->id
            && ! Gate::forUser($user)->allows('workforce.manage.record', [$swap->loadMissing('requesterEmployee')->requesterEmployee])) {
            abort(403);
        }

        return DB::transaction(function () use ($swap) {
            $swap = ShiftSwapRequest::query()->lockForUpdate()->findOrFail($swap->id);
            if (! in_array($swap->status, self::OPEN_STATUSES, true)) {
                throw ValidationException::withMessages(['swap' => 'Only open requests can be cancelled.']);
            }

            $swap->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $swap->refresh();
        });
    }

    /**
     * Close out every open request that can no longer be answered.
     *
     * Two things end a request without anybody deciding anything, and neither
     * used to end it at all:
     *
     *   The shift was worked. A swap answers a shift that is coming, so once
     *   that shift is in the past there is nothing left to decide. The earlier
     *   of the two shifts sets the deadline -- once one side has been worked
     *   there is no trade to make, however far off the other still is.
     *
     *   Somebody in it stopped swapping shifts. Eligibility follows the
     *   department, so a transfer to an administrative post takes the feature
     *   away mid-request: the page that would show it is hidden from them, and
     *   a request nobody can even see is not one anybody will answer.
     *
     * Either way the request kept its pending status for ever, counted against
     * the reviewer's sidebar badge, and sat at the top of a queue offering an
     * Approve button that could only ever refuse it.
     *
     * @return Collection<int, ShiftSwapRequest> the requests it closed, each
     *                                           carrying the reason, so the
     *                                           caller can report them
     */
    public function expireStale(): Collection
    {
        $closed = $this->unanswerable();

        if ($closed->isEmpty()) {
            return $closed;
        }

        // Grouped by reason rather than written a row at a time: these rows are
        // only being stamped, and a nightly job should not open a statement per
        // request to do it.
        $expiredAt = now();

        foreach ($closed->groupBy('expired_reason') as $reason => $swaps) {
            ShiftSwapRequest::query()
                ->whereKey($swaps->modelKeys())
                ->update(['status' => 'expired', 'expired_at' => $expiredAt, 'expired_reason' => $reason]);
        }

        return $closed;
    }

    /**
     * The open requests that can no longer be answered, each with its reason
     * set but nothing written. What `expireStale()` is about to close, which is
     * also exactly what a dry run wants to list -- asked once here so the two
     * cannot drift into disagreeing about it.
     *
     * @return Collection<int, ShiftSwapRequest>
     */
    public function unanswerable(): Collection
    {
        $today = now(config('schedule.timezone'))->toDateString();

        return ShiftSwapRequest::query()
            ->with([
                'requesterEmployee.department',
                'targetEmployee.department',
                'requesterAssignment.shift',
                'targetAssignment.shift',
            ])
            ->whereIn('status', self::OPEN_STATUSES)
            ->orderBy('id')
            ->get()
            ->each(fn (ShiftSwapRequest $swap) => $swap->expired_reason = $this->unanswerableReason($swap, $today))
            ->filter(fn (ShiftSwapRequest $swap): bool => $swap->expired_reason !== null)
            ->values();
    }

    /**
     * Why this request can no longer be answered, or null while it still can.
     *
     * Written for the two people who filed it rather than for the log: it is
     * shown against the request on the page they go looking at when they wonder
     * what became of it.
     */
    private function unanswerableReason(ShiftSwapRequest $swap, string $today): ?string
    {
        $dates = collect([$swap->requesterAssignment?->work_date, $swap->targetAssignment?->work_date])->filter();

        if ($dates->contains(fn ($date): bool => $date->toDateString() < $today)) {
            return 'The shift was worked before anyone answered.';
        }

        foreach ([$swap->requesterEmployee, $swap->targetEmployee] as $employee) {
            if ($employee !== null && ! $employee->canUseShiftSwaps()) {
                return $employee->full_name.' no longer swaps shifts, so this request cannot go ahead.';
            }
        }

        return null;
    }

    /**
     * Closes the request and says why, when it can no longer be answered.
     *
     * Both of the points where somebody tries to move a request forward go
     * through here, so a late answer ends the request rather than leaving it
     * where it was for the nightly sweep to find. The reason is the sweep's
     * own, so a request closed by a button and one closed overnight read the
     * same afterwards.
     */
    private function expireIfUnanswerable(ShiftSwapRequest $swap): void
    {
        // A request that has already ended keeps the ending it was given: a
        // withdrawn or declined one is not re-labelled as lapsed just because
        // somebody pushes a stale button at it. This runs before the caller's
        // own status check, so it has to say so itself.
        if (! in_array($swap->fresh()?->status ?? $swap->status, self::OPEN_STATUSES, true)) {
            return;
        }

        $swap->loadMissing([
            'requesterEmployee.department',
            'targetEmployee.department',
            'requesterAssignment',
            'targetAssignment',
        ]);

        $reason = $this->unanswerableReason($swap, now(config('schedule.timezone'))->toDateString());

        if ($reason === null) {
            return;
        }

        $swap->update(['status' => 'expired', 'expired_at' => now(), 'expired_reason' => $reason]);

        throw ValidationException::withMessages(['swap' => $reason.' This request has been closed.']);
    }

    /**
     * Both sides of a trade must be clinical staff. The requester is already
     * gated at the request layer, but the target arrives as a bare assignment
     * id with no such check — without this, a clinical employee could still
     * name an administrative colleague's assignment as the other half of the
     * trade.
     */
    /**
     * A manager who is one of the two people trading shifts is not the one
     * to sign it off -- the same rule leave, attendance and timesheets hold.
     */
    private function ensureNotParticipant(ShiftSwapRequest $swap, User $reviewer, string $action): void
    {
        $reviewerEmployeeId = $reviewer->employee?->id;

        if ($reviewerEmployeeId !== null
            && in_array($reviewerEmployeeId, [$swap->requester_employee_id, $swap->target_employee_id], true)) {
            throw ValidationException::withMessages(['swap' => "You cannot {$action} a shift swap you are part of."]);
        }
    }

    private function ensureEligible(Employee $employee, string $field): void
    {
        if (! $employee->canUseShiftSwaps()) {
            throw ValidationException::withMessages([
                $field => 'Shift swaps are for clinical staff. '.$employee->full_name.' is not eligible.',
            ]);
        }
    }

    private function ensureSwappable(ScheduleAssignment $assignment, string $field): void
    {
        if ($assignment->status !== 'scheduled') {
            throw ValidationException::withMessages([$field => 'That shift is no longer scheduled.']);
        }

        if ($assignment->work_date->toDateString() < now(config('schedule.timezone'))->toDateString()) {
            throw ValidationException::withMessages([$field => 'Past shifts cannot be swapped.']);
        }
    }

    /**
     * Two people already on the identical shift and date have nothing to trade
     * — swapping them would leave that slot's coverage unchanged, and briefly
     * assigning both rows to the same employee mid-update would collide with
     * the (employee_id, shift_id, work_date) unique constraint besides.
     */
    private function ensureDifferentSlot(ScheduleAssignment $requesterAssignment, ScheduleAssignment $targetAssignment): void
    {
        if ($requesterAssignment->shift_id === $targetAssignment->shift_id
            && $requesterAssignment->work_date->toDateString() === $targetAssignment->work_date->toDateString()) {
            throw ValidationException::withMessages(['target_assignment_id' => 'These are the same shift and date — there is nothing to swap.']);
        }
    }

    private function ensureNoOpenSwap(int $requesterAssignmentId, int $targetAssignmentId): void
    {
        $exists = ShiftSwapRequest::query()
            ->whereIn('status', self::OPEN_STATUSES)
            ->where(function ($query) use ($requesterAssignmentId, $targetAssignmentId) {
                $query->whereIn('requester_assignment_id', [$requesterAssignmentId, $targetAssignmentId])
                    ->orWhereIn('target_assignment_id', [$requesterAssignmentId, $targetAssignmentId]);
            })
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['requester_assignment_id' => 'One of these shifts already has an open swap request.']);
        }
    }

    /** @return Collection<int, ScheduleAssignment> */
    private function assignmentsFor(Employee $employee, Carbon $date, ?int $excludeAssignmentId): Collection
    {
        return ScheduleAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->where('status', 'scheduled')
            ->when($excludeAssignmentId, fn ($query) => $query->where('id', '!=', $excludeAssignmentId))
            ->whereBetween('work_date', [
                $date->copy()->startOfWeek()->subDay()->toDateString(),
                $date->copy()->endOfWeek()->addDay()->toDateString(),
            ])
            ->get();
    }

    /** @return Collection<int, LeaveRequest> */
    private function leavesFor(Employee $employee, Carbon $date): Collection
    {
        return LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->get();
    }

    /** @return Collection<int, ScheduleDayOff> */
    private function dayOffsFor(Employee $employee, Carbon $date): Collection
    {
        return ScheduleDayOff::query()
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date->toDateString())
            ->get();
    }
}

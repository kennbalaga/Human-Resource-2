<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    /** @param array{leave_type_id: int, start_date: string, end_date: string, reason: string} $data */
    public function create(Employee $employee, array $data): LeaveRequest
    {
        return DB::transaction(function () use ($employee, $data) {
            $type = LeaveType::query()->where('is_active', true)->findOrFail($data['leave_type_id']);
            $start = Carbon::parse($data['start_date'], config('workforce.timezone'));
            $end = Carbon::parse($data['end_date'], config('workforce.timezone'));

            if ($start->year !== $end->year) {
                throw ValidationException::withMessages(['end_date' => 'A leave request must stay within one calendar year.']);
            }

            $days = $this->businessDays($start, $end);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'The selected range contains no working days.']);
            }

            $overlap = LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('start_date', '<=', $end->toDateString())
                ->whereDate('end_date', '>=', $start->toDateString())
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['start_date' => 'This request overlaps another pending or approved leave.']);
            }

            $balance = $this->balanceFor($employee, $type, $start->year, true);
            if ($balance->available_days < $days) {
                throw ValidationException::withMessages(['leave_type_id' => "Insufficient {$type->name} balance. {$balance->available_days} day(s) available."]);
            }

            $request = LeaveRequest::query()->create([
                'uuid' => (string) Str::uuid(),
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'requested_days' => $days,
                'reason' => $data['reason'],
                'status' => 'pending',
            ]);

            $balance->increment('pending_days', $days);

            return $request;
        });
    }

    public function approve(LeaveRequest $request, User $reviewer, ?string $notes): LeaveRequest
    {
        if ($request->employee_id === $reviewer->employee?->id) {
            throw ValidationException::withMessages(['leave' => 'You cannot approve your own leave request.']);
        }

        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->ensurePending($request);
            $balance = $this->balanceFor($request->employee, $request->leaveType, $request->start_date->year, true);

            $balance->update([
                'pending_days' => max(0, (float) $balance->pending_days - (float) $request->requested_days),
                'used_days' => (float) $balance->used_days + (float) $request->requested_days,
            ]);
            $request->update([
                'status' => 'approved',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $request->refresh();
        });
    }

    public function reject(LeaveRequest $request, User $reviewer, string $notes): LeaveRequest
    {
        if ($request->employee_id === $reviewer->employee?->id) {
            throw ValidationException::withMessages(['leave' => 'You cannot reject your own leave request.']);
        }

        return DB::transaction(function () use ($request, $reviewer, $notes) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            $this->ensurePending($request);
            $balance = $this->balanceFor($request->employee, $request->leaveType, $request->start_date->year, true);
            $balance->update(['pending_days' => max(0, (float) $balance->pending_days - (float) $request->requested_days)]);
            $request->update([
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $notes,
            ]);

            return $request->refresh();
        });
    }

    public function cancel(LeaveRequest $request, User $user): LeaveRequest
    {
        // Cancelling your own request is self-service. Cancelling somebody
        // else's is a review action, so it needs write access as well as reach.
        if ($request->employee_id !== $user->employee?->id
            && ! Gate::forUser($user)->allows('workforce.manage')) {
            abort(403);
        }

        return DB::transaction(function () use ($request) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['leave' => 'Only pending or approved requests can be cancelled.']);
            }

            $balance = $this->balanceFor($request->employee, $request->leaveType, $request->start_date->year, true);
            $field = $request->status === 'approved' ? 'used_days' : 'pending_days';
            $balance->update([$field => max(0, (float) $balance->{$field} - (float) $request->requested_days)]);
            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $request->refresh();
        });
    }

    public function businessDays(Carbon $start, Carbon $end): float
    {
        return (float) collect(CarbonPeriod::create($start->copy()->startOfDay(), $end->copy()->startOfDay()))
            ->filter(fn ($date) => ! Carbon::instance($date)->isWeekend())
            ->count();
    }

    public function balanceFor(Employee $employee, LeaveType $type, int $year, bool $lock = false): LeaveBalance
    {
        LeaveBalance::query()->firstOrCreate(
            ['employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
            ['entitled_days' => $type->annual_entitlement],
        );

        $query = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /**
     * Resolve the balance for every supplied leave type at once, creating any
     * that do not exist yet. Asking for them one type at a time costs a pair of
     * queries each, which dominates the leave screen against a remote database.
     *
     * @param  Collection<int, LeaveType>  $types
     * @return EloquentCollection<int, LeaveBalance>
     */
    public function balancesFor(Employee $employee, Collection $types, int $year): EloquentCollection
    {
        $existing = $this->loadBalances($employee, $types, $year);
        $missing = $types->reject(fn (LeaveType $type) => $existing->has($type->id));

        if ($missing->isNotEmpty()) {
            LeaveBalance::query()->insertOrIgnore($missing->map(fn (LeaveType $type) => [
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'year' => $year,
                'entitled_days' => $type->annual_entitlement,
                'created_at' => now(),
                'updated_at' => now(),
            ])->values()->all());

            $existing = $this->loadBalances($employee, $types, $year);
        }

        $balances = $types
            ->map(function (LeaveType $type) use ($existing): ?LeaveBalance {
                // The type is already in memory, so hand it over rather than
                // letting the view lazy-load the relation back out of the database.
                return $existing->get($type->id)?->setRelation('leaveType', $type);
            })
            ->filter()
            ->values()
            ->all();

        return new EloquentCollection($balances);
    }

    /**
     * @param  Collection<int, LeaveType>  $types
     * @return Collection<int, LeaveBalance>
     */
    private function loadBalances(Employee $employee, Collection $types, int $year): Collection
    {
        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('year', $year)
            ->whereIn('leave_type_id', $types->pluck('id'))
            ->get()
            ->keyBy('leave_type_id');
    }

    private function ensurePending(LeaveRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['leave' => 'Only pending leave requests can be reviewed.']);
        }
    }
}

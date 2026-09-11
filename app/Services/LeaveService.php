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

            $this->assertEligible($employee, $type, $start);

            $days = $this->countDays($start, $end, $type);
            if ($days <= 0) {
                throw ValidationException::withMessages(['start_date' => 'The selected range contains no working days.']);
            }

            // A per-occasion entitlement caps the request rather than a
            // balance: 105 days of maternity leave is the ceiling for one
            // childbirth, and there is no running total to draw it from.
            $ceiling = $type->maximumDaysPerRequest();
            if ($ceiling !== null && $days > $ceiling) {
                throw ValidationException::withMessages([
                    'end_date' => "{$type->name} is limited to ".number_format($ceiling, 0).' '.$type->day_basis.' day(s) per occurrence.',
                ]);
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

            // Usage is recorded for every type, because reporting wants to
            // know how much maternity or unpaid leave was taken. Only a yearly
            // allowance is *enforced* against a balance -- the others hold no
            // credits to run out of, and approval is what governs them.
            $balance = $this->balanceFor($employee, $type, $start->year, true);
            if ($type->isBalanceBacked() && $balance->available_days < $days) {
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
        // else's is a review action, so it needs write access as well as reach
        // — and reach stops at the unit the reviewer actually supervises.
        if ($request->employee_id !== $user->employee?->id
            && ! Gate::forUser($user)->allows('workforce.manage.record', [$request->loadMissing('employee')->employee])) {
            abort(403);
        }

        return DB::transaction(function () use ($request) {
            $request = LeaveRequest::query()->lockForUpdate()->findOrFail($request->id);
            if (! in_array($request->status, ['pending', 'approved'], true)) {
                throw ValidationException::withMessages(['leave' => 'Only pending or approved requests can be cancelled.']);
            }

            // Checked inside the lock, against the row as it stands: the last
            // day of leave can pass between the page rendering the button and
            // the form arriving.
            if (! $request->isCancellable()) {
                throw ValidationException::withMessages(['leave' => 'This leave has already been taken and can no longer be cancelled.']);
            }

            $balance = $this->balanceFor($request->employee, $request->leaveType, $request->start_date->year, true);
            $field = $request->status === 'approved' ? 'used_days' : 'pending_days';
            $balance->update([$field => max(0, (float) $balance->{$field} - (float) $request->requested_days)]);
            $request->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            return $request->refresh();
        });
    }

    /**
     * How many days the range costs against the given type. Statutory leave
     * reckoned in calendar days counts weekends too -- 105 days of maternity
     * leave is 105 days, not fifteen weeks of working days.
     */
    public function countDays(Carbon $start, Carbon $end, LeaveType $type): float
    {
        return $type->usesCalendarDays()
            ? (float) ($start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1)
            : $this->businessDays($start, $end);
    }

    /**
     * The entry conditions the law attaches to a leave type, checked before any
     * balance is touched. Nothing enforced these before, so a male employee
     * could file the full 105 days of maternity leave and the system took it.
     */
    public function assertEligible(Employee $employee, LeaveType $type, Carbon $asOf): void
    {
        if ($type->requires_designation !== null && ! $employee->hasDesignation($type->requires_designation, $asOf)) {
            // Distinguishes a lapsed ID from one that was never recorded. An
            // employee whose solo parent ID expired last month is owed a
            // different instruction from one HR never marked at all.
            $lapsed = $type->requires_designation === LeaveType::DESIGNATION_SOLO_PARENT
                && $employee->solo_parent_id_expires_on !== null;

            throw ValidationException::withMessages(['leave_type_id' => $lapsed
                ? "{$type->name} requires a valid solo parent ID. The one on your record expired on {$employee->solo_parent_id_expires_on->format('j M Y')}."
                : "{$type->name} is available to employees HR has recorded as qualifying. Ask HR if you believe this applies to you."]);
        }

        if ($type->eligible_gender !== null && $employee->gender !== $type->eligible_gender) {
            // An unrecorded gender is not a refusal of the entitlement, it is
            // an incomplete record, and the message has to send the employee
            // somewhere that can fix it rather than reading as a denial.
            throw ValidationException::withMessages(['leave_type_id' => $employee->gender === null
                ? "{$type->name} is available to {$type->eligible_gender} employees, and your record does not state one. Ask HR to complete your profile."
                : "{$type->name} is available to {$type->eligible_gender} employees."]);
        }

        $required = (int) $type->min_service_months;
        if ($required > 0 && ($served = $employee->serviceMonthsAsOf($asOf)) < $required) {
            throw ValidationException::withMessages([
                'leave_type_id' => "{$type->name} requires {$required} month(s) of service. You will have {$served} by the start of this leave.",
            ]);
        }
    }

    /**
     * The types this employee could hold at all, for the balance cards and the
     * request picker.
     *
     * Only the standing gates narrow the list -- gender and designation --
     * because those do not change by waiting. A tenure requirement is left in
     * so the card still shows: the employee will qualify for it in a few
     * months, and hiding it would read as though the entitlement did not
     * exist. assertEligible is still what refuses the request; this only keeps
     * the page from advertising leave nobody can take.
     *
     * @param  Collection<int, LeaveType>  $types
     * @return Collection<int, LeaveType>
     */
    public function selectableTypes(Employee $employee, Collection $types, Carbon $asOf): Collection
    {
        return $types->filter(function (LeaveType $type) use ($employee, $asOf): bool {
            if ($type->requires_designation !== null && ! $employee->hasDesignation($type->requires_designation, $asOf)) {
                return false;
            }

            return $type->eligible_gender === null || $employee->gender === $type->eligible_gender;
        })->values();
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
            ['entitled_days' => $this->entitlementFor($type)],
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
                'entitled_days' => $this->entitlementFor($type),
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

    /**
     * The credits to open a year with. Only a yearly allowance grants any: a
     * per-occasion or uncapped type would otherwise hand every employee a
     * fresh 105 days of maternity leave each January, and an entitlement
     * nobody holds is exactly what once had the balance tile reading 890 days.
     */
    private function entitlementFor(LeaveType $type): float
    {
        return $type->isBalanceBacked() ? (float) $type->annual_entitlement : 0.0;
    }

    private function ensurePending(LeaveRequest $request): void
    {
        if ($request->status !== 'pending') {
            throw ValidationException::withMessages(['leave' => 'Only pending leave requests can be reviewed.']);
        }
    }
}

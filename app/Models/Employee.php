<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The end of an employment, and the only status from which a record may be
     * archived. Named because the archive rules read it in four places and a
     * typo in any one of them would open the shelf to everybody again.
     */
    public const STATUS_TERMINATED = 'terminated';

    protected $fillable = [
        'user_id',
        'department_id',
        'position_id',
        'supervisor_id',
        'employee_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'gender',
        'solo_parent_id_number',
        'solo_parent_id_expires_on',
        'employment_status',
        'hire_date',
        'contact_number',
        'address',
        'preferred_shift_id',
        'preferred_weekly_off_day',
    ];

    /**
     * Anyone holding this can mint that employee's attendance badge, so it must
     * never ride along in an API payload or a debug dump.
     *
     * @var list<string>
     */
    protected $hidden = [
        'attendance_qr_secret',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'solo_parent_id_expires_on' => 'date',
            'terminated_at' => 'datetime',
            'archived_at' => 'datetime',
            'restored_at' => 'datetime',
            'preferred_weekly_off_day' => 'integer',
        ];
    }

    /**
     * The termination clock, wound by the save that ends the employment.
     *
     * This lives on the model rather than in the controller because the status
     * is written from more than one place -- the employee form, the seeders, a
     * console command correcting a record -- and a clock that only some of them
     * wind is worse than no clock: the automatic sweep would file some records
     * and silently forget others.
     *
     * An already-set stamp is left alone so a deliberate value (a backfill, a
     * test, a correction) survives the save that carries it.
     */
    protected static function booted(): void
    {
        static::saving(function (self $employee): void {
            if (! $employee->isDirty('employment_status')) {
                return;
            }

            if ($employee->employment_status === self::STATUS_TERMINATED) {
                $employee->terminated_at ??= now();

                return;
            }

            // Reinstated, or the status was wrong in the first place. Either
            // way there is no termination to count from any more, and the
            // archive lifecycle this record was part of is over.
            $employee->terminated_at = null;
            $employee->restored_at = null;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Who filed this record away. Null on a record nobody has archived. */
    public function archiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isTerminated(): bool
    {
        return $this->employment_status === self::STATUS_TERMINATED;
    }

    /**
     * Whether this record may be put on the shelf.
     *
     * Archiving is the second half of a termination, never a shortcut past one.
     * A colleague on leave, on a break in service, or simply inactive is still
     * somebody the organisation employs, and filing their record away would
     * close their sign-in and pull them out of every picker on the strength of
     * one mis-aimed click.
     */
    public function canBeArchived(): bool
    {
        return $this->isTerminated() && ! $this->isArchived();
    }

    /**
     * Filed by the nightly sweep rather than by a person.
     *
     * Read off the absence of an archiver, which is the only actor the
     * automatic path has: nobody signed in, so nobody is named. The record
     * still says when, which is the half that matters months later.
     */
    public function wasArchivedAutomatically(): bool
    {
        return $this->isArchived() && $this->archived_by === null;
    }

    /**
     * When the current run at the archive clock began.
     *
     * The termination, normally. On a record somebody pulled back out of the
     * archive, the restore — so the checking it was pulled out for gets a full
     * window before the sweep comes round again, and a manager who then forgets
     * to re-file it does not leave the record in the directory for good.
     */
    public function archiveClockStartedAt(): ?Carbon
    {
        if (! $this->isTerminated() || $this->terminated_at === null) {
            return null;
        }

        return $this->restored_at !== null && $this->restored_at->greaterThan($this->terminated_at)
            ? $this->restored_at
            : $this->terminated_at;
    }

    /** Whether employment as it currently reads should open the sign-in at all. */
    public static function employmentGrantsAccess(string $status): bool
    {
        return in_array($status, ['active', 'on_leave'], true);
    }

    /**
     * Whether the employee holds the standing status a leave type demands, as
     * at the given date.
     *
     * Unknown designations return false rather than true: a type asking for
     * something this method cannot verify must refuse the leave, not wave it
     * through. Expiry is checked because a DSWD solo parent ID lapses, and an
     * employee whose ID has run out is not a solo parent for the purpose of
     * claiming the seven days.
     */
    public function hasDesignation(string $designation, Carbon $asOf): bool
    {
        return match ($designation) {
            LeaveType::DESIGNATION_SOLO_PARENT => $this->solo_parent_id_expires_on !== null
                && $this->solo_parent_id_expires_on->greaterThanOrEqualTo($asOf->copy()->startOfDay()),
            default => false,
        };
    }

    /** Whether the solo parent ID on file is present and still in date today. */
    public function hasValidSoloParentId(): bool
    {
        return $this->hasDesignation(LeaveType::DESIGNATION_SOLO_PARENT, Carbon::now());
    }

    /**
     * The solo parent ID as it reads on a record, phrased in one place so the
     * profile and the employee record cannot describe the same row
     * differently. A lapsed ID says so rather than being hidden: an employee
     * whose leave has quietly stopped being available is owed the reason.
     */
    public function soloParentSummary(): string
    {
        if ($this->solo_parent_id_expires_on === null) {
            return 'Not recorded';
        }

        $date = $this->solo_parent_id_expires_on->format('j M Y');
        $number = $this->solo_parent_id_number ?: 'ID on file';

        return $this->hasValidSoloParentId()
            ? "{$number} · valid to {$date}"
            : "{$number} · expired {$date}";
    }

    /**
     * Completed months of service as at the given date, for the leave types
     * that carry a tenure requirement. Counted from the hire date, so a record
     * without one reads as no service rather than as unlimited service.
     */
    public function serviceMonthsAsOf(Carbon $date): int
    {
        if ($this->hire_date === null || $this->hire_date->greaterThan($date)) {
            return 0;
        }

        return (int) $this->hire_date->diffInMonths($date);
    }

    /**
     * Archived records are kept, not hidden from the system: they still count
     * in headcounts, still carry their attendance and timesheet history, and
     * are still readable in the directory under the Archived filter. These two
     * scopes exist for the places where an employee is offered as a *choice* —
     * the directory's working list, and the pickers that assign work — because
     * nobody should be able to roster a person the organisation has filed away.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /** @param  Builder<Employee>  $query */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * The records the automatic sweep is entitled to consider: terminated
     * before the cutoff, not already filed, and — if somebody restored them —
     * restored before the cutoff too, so a record pulled out for checking gets
     * a full window before the sweep comes round again.
     *
     * The two dates are compared separately rather than through GREATEST or
     * COALESCE: this query runs on MySQL in production and SQLite under test,
     * and those two disagree about both.
     *
     * Whether each one can actually go is a separate question — a rostered
     * shift or an open attendance day still blocks it — and the sweep asks that
     * per record rather than trying to express it here.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeDueForAutomaticArchive(Builder $query, Carbon $clockStartedBefore): Builder
    {
        return $query
            ->where('employment_status', self::STATUS_TERMINATED)
            ->whereNotNull('terminated_at')
            ->where('terminated_at', '<=', $clockStartedBefore)
            ->where(fn (Builder $restored) => $restored
                ->whereNull('restored_at')
                ->orWhere('restored_at', '<=', $clockStartedBefore))
            ->notArchived();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Narrow a query to the employees the given account supervises.
     *
     * The org-wide roles get the query back untouched; a department head gets
     * their own unit. Anyone else supervises nobody, and `whereRaw('0 = 1')`
     * says so as a query rather than as an empty `whereIn`, which some drivers
     * are happy to optimise into a full table scan of nothing while others
     * treat as a syntax problem.
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        // Deliberately not `$user?->supervisedDepartmentIds() ?? []`: that
        // coalesce cannot tell "there is no user" from the null the org-wide
        // roles legitimately return, and would quietly reduce HR to seeing
        // nothing. The absent user is handled on its own line.
        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $departmentIds = $user->supervisedDepartmentIds();

        if ($departmentIds === null) {
            return $query;
        }

        if ($departmentIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn('department_id', $departmentIds);
    }

    /**
     * The same narrowing, applied to a query on a model that merely belongs to
     * an employee — attendance, leave, timesheets, schedule assignments. Saves
     * every caller repeating the `whereHas` wrapper around scopeVisibleTo.
     *
     * @param  Builder<covariant Model>  $query
     */
    public static function constrainRelatedQuery(Builder $query, ?User $user, string $relation = 'employee'): Builder
    {
        if ($user === null) {
            return $query->whereRaw('0 = 1');
        }

        $departmentIds = $user->supervisedDepartmentIds();

        if ($departmentIds === null) {
            return $query;
        }

        if ($departmentIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas($relation, fn (Builder $employeeQuery) => $employeeQuery->whereIn('department_id', $departmentIds));
    }

    /**
     * Shift swaps are a clinical-staffing tool — covering a ward or a shift
     * when a nurse or other clinical worker cannot make it. Administrative and
     * support staff work fixed office hours with no shift to trade, so the
     * feature does not apply to them at all, not merely hidden by preference.
     */
    public function canUseShiftSwaps(): bool
    {
        return $this->department?->category === Department::CATEGORY_CLINICAL;
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supervisor_id');
    }

    public function preferredShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'preferred_shift_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(self::class, 'supervisor_id');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function biometricEnrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class);
    }

    public function scheduleAssignments(): HasMany
    {
        return $this->hasMany(ScheduleAssignment::class);
    }

    public function recurringSchedules(): HasMany
    {
        return $this->hasMany(RecurringSchedule::class);
    }

    public function scheduleDayOffs(): HasMany
    {
        return $this->hasMany(ScheduleDayOff::class);
    }

    public function preferredDayOffs(): HasMany
    {
        return $this->hasMany(PreferredDayOff::class);
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(Timesheet::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    /**
     * Narrow to the rows a free-text directory search is asking for.
     *
     * The search is split on whitespace and every term has to land somewhere on
     * the row. Matching the whole string against each column in turn -- what the
     * directory and the global search box both did before -- can never find a
     * person by their full name: no single column holds it, so "Louie" returned
     * Louie Cas and "Louie Cas" returned nothing. Term by term, the first name
     * answers one word and the surname the other, in either order.
     *
     * Middle name and suffix are searchable too. They are part of the name the
     * results print, and a directory that shows a name it will not accept back
     * reads as broken.
     *
     * Email is off unless the caller passes it in: matching on it turns the box
     * into a confirmation oracle, where anyone signed in can probe for a
     * colleague's address one guess at a time.
     */
    public function scopeMatchingSearch(Builder $query, string $search, bool $includeEmail = false): void
    {
        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terms as $term) {
            $query->where(function (Builder $termQuery) use ($term, $includeEmail): void {
                $termQuery
                    ->where('employee_number', 'like', "%{$term}%")
                    ->orWhere('first_name', 'like', "%{$term}%")
                    ->orWhere('middle_name', 'like', "%{$term}%")
                    ->orWhere('last_name', 'like', "%{$term}%")
                    ->orWhere('suffix', 'like', "%{$term}%")
                    ->when($includeEmail, fn (Builder $builder) => $builder
                        ->orWhereHas('user', fn (Builder $userQuery) => $userQuery->where('email', 'like', "%{$term}%")));
            });
        }
    }

    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->middle_name,
            $this->last_name,
            $this->suffix,
        ])->filter()->implode(' ');
    }
}

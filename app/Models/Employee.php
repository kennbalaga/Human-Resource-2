<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

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
            'preferred_weekly_off_day' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
